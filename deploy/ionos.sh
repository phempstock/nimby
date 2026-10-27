#!/usr/bin/env bash
# Deployment and server tasks for IONOS web hosting, run by GitHub Actions.
#
#   deploy/ionos.sh check    report what the server has (PHP, rsync, cron, config, database)
#   deploy/ionos.sh deploy   copy the code to the server, then update database tables
#   deploy/ionos.sh setup    first-time setup: tables, first sync, nightly cron job
#   deploy/ionos.sh sync     run the sync now
#
# Needs environment variables SSH_HOST, SSH_USER and SSH_KEY (from repository
# secrets). DEPLOY_PATH defaults to "nimby", relative to the hosting home folder.
#
# The repository is public, so its Actions logs are public too. Everything this
# script prints passes through redact(), and it never echoes host names, user
# names or server paths.
set -euo pipefail

DEPLOY_PATH="${DEPLOY_PATH:-nimby}"
HERE="$(cd "$(dirname "$0")" && pwd)"
PINS="$HERE/ionos-host-keys.txt"
SSH_DIR="${RUNNER_TEMP:-/tmp}/nimby-ssh"
KEY="$SSH_DIR/id_deploy"
KNOWN="$SSH_DIR/known_hosts"
CRON_TAG='# nimby-sync'

note() { echo "::notice title=NIMBY server::$*"; }
fail() { echo "::error title=NIMBY server::$*"; exit 1; }

redact() {
  sed -E \
    -e "s/'[^']*'@'[^']*'/'***'@'***'/g" \
    -e 's#/(kunden|homepages)/[^ "]*#[server path]#g' \
    -e 's/db[0-9]+\.hosting-data\.io/[db host]/g' \
    -e 's/[a-z0-9.-]+\.1and1-data\.host/[ssh host]/g' \
    -e 's/\b(dbu|dbs|acc|u)[0-9]{6,}\b/***/g'
}

ssh_opts=(-i "$KEY" -o "UserKnownHostsFile=$KNOWN" -o StrictHostKeyChecking=yes -o BatchMode=yes -o ConnectTimeout=20 -o LogLevel=ERROR)
remote() { ssh "${ssh_opts[@]}" "$SSH_USER@$SSH_HOST" "$@"; }

# Run a remote command and show its whole output as ONE multi-line notice
# (GitHub keeps only 10 notices per step, so one per line would be cut off).
report() {
  local status=0
  remote "$1" > "$SSH_DIR/out" 2>&1 || status=$?
  local body
  body="$(redact < "$SSH_DIR/out" | grep -v '^[[:space:]]*$' | sed -e 's/%/%25/g' | awk 'BEGIN{ORS="%0A"} {print}' | sed -e 's/%0A$//')"
  [ -n "$body" ] && note "$body"
  echo "$body" | sed 's/%0A/\n/g; s/%25/%/g'   # also readable in the step log
  return "$status"
}

connect() {
  if [ -z "${SSH_HOST:-}" ] || [ -z "${SSH_USER:-}" ] || [ -z "${SSH_KEY:-}" ]; then
    fail "The IONOS_SSH_HOST, IONOS_SSH_USER and IONOS_SSH_KEY repository secrets are not all set"
  fi
  mkdir -p "$SSH_DIR" && chmod 700 "$SSH_DIR"
  printf '%s\n' "$SSH_KEY" | tr -d '\r' > "$KEY" && chmod 600 "$KEY"

  # Only trust host keys whose fingerprints are pinned in deploy/ionos-host-keys.txt.
  ssh-keyscan -T 20 "$SSH_HOST" 2>/dev/null | grep -v '^#' > "$SSH_DIR/scanned" || true
  [ -s "$SSH_DIR/scanned" ] || fail "Could not reach the server's SSH port"
  : > "$KNOWN"
  local pinned; pinned="$(grep -v '^#' "$PINS" 2>/dev/null | awk 'NF {print $1}' || true)"
  while IFS= read -r line; do
    printf '%s\n' "$line" > "$SSH_DIR/one"
    local fp; fp="$(ssh-keygen -lf "$SSH_DIR/one" 2>/dev/null | awk '{print $2}')"
    if [ -n "$fp" ] && grep -qxF "$fp" <<< "$pinned"; then
      printf '%s\n' "$line" >> "$KNOWN"
    fi
  done < "$SSH_DIR/scanned"
  if [ ! -s "$KNOWN" ]; then
    ssh-keygen -lf "$SSH_DIR/scanned" | awk '{gsub(/[()]/, "", $NF); print $NF, $2}' | while read -r type fp; do
      note "Server offered host key $type $fp (not yet pinned)"
    done
    fail "The server's host key is not in deploy/ionos-host-keys.txt, so it was not trusted"
  fi

  remote true 2>&1 | redact || fail "SSH login with the deploy key was refused"
}

php_bin() {
  # IONOS ships CGI builds as /usr/bin/php8.x and command-line builds as /usr/bin/php8.x-cli.
  # Take the newest PHP 8 binary that really is the command-line build.
  remote 'for p in $(ls -1 /usr/bin/php8.* 2>/dev/null | grep -E "/php8\.[0-9]+(-cli)?$" | sort -V -r); do
            if [ "$("$p" -r "echo PHP_SAPI;" 2>/dev/null)" = cli ]; then echo "$p"; break; fi
          done'
}

need_php() {
  PHP="$(php_bin)"
  [ -n "$PHP" ] || fail "No command-line PHP 8 found in /usr/bin on the server"
}

need_config() {
  remote "[ -f '$DEPLOY_PATH/config.php' ]" || fail "config.php is not on the server yet (expected in the $DEPLOY_PATH folder)"
}

task_check() {
  need_php
  note "PHP $(remote "$PHP -r 'echo PHP_VERSION;'") at $PHP"
  note "rsync: $(remote 'command -v rsync >/dev/null && echo available || echo missing')"
  note "crontab: $(remote 'command -v crontab >/dev/null && echo available || echo missing')"
  note "Server time: $(remote 'date "+%H:%M %Z"')"
  note "Code deployed: $(remote "[ -f '$DEPLOY_PATH/bootstrap.php' ] && echo yes || echo no")"
  note "config.php present: $(remote "[ -f '$DEPLOY_PATH/config.php' ] && echo yes || echo no")"
  note "Nightly cron job: $(remote "crontab -l 2>/dev/null | grep -q '$CRON_TAG' && echo installed || echo 'not installed'")"
  if remote "[ -f '$DEPLOY_PATH/bin/healthcheck.php' ] && [ -f '$DEPLOY_PATH/config.php' ]"; then
    report "cd '$DEPLOY_PATH' && $PHP bin/healthcheck.php" || true
  fi
}

task_deploy() {
  local rev; rev="$(git rev-parse --short HEAD)"
  if remote 'command -v rsync >/dev/null'; then
    # --delete keeps the server in step with the repository; the P (protect) rules
    # stop it removing the server's own config.php, data/ (cache, logs) and map data.
    rsync -az --delete \
      --filter='P /config.php' \
      --filter='P /data/' \
      --filter='P /public/data/*.geojson' \
      --filter='P /public/data/meta.json' \
      --exclude='/.git/' --exclude='/.github/' --exclude='/tests/' --exclude='/deploy/' \
      -e "ssh ${ssh_opts[*]}" \
      ./ "$SSH_USER@$SSH_HOST:$DEPLOY_PATH/" 2>&1 | redact
    note "Deployed $rev with rsync"
  else
    tar czf - --exclude=./.git --exclude=./.github --exclude=./tests --exclude=./deploy . \
      | remote "mkdir -p '$DEPLOY_PATH' && tar xzf - -C '$DEPLOY_PATH'" 2>&1 | redact
    note "Deployed $rev with tar (rsync is missing, so files deleted from the repository stay on the server)"
  fi

  if remote "[ -f '$DEPLOY_PATH/config.php' ]"; then
    need_php
    report "cd '$DEPLOY_PATH' && $PHP bin/migrate.php"
  else
    note "Database update skipped: config.php is not on the server yet"
  fi
}

task_setup() {
  need_php
  need_config
  remote "chmod 600 '$DEPLOY_PATH/config.php' && mkdir -p '$DEPLOY_PATH/data/cache' '$DEPLOY_PATH/public/data'"
  report "cd '$DEPLOY_PATH' && $PHP bin/migrate.php"
  report "cd '$DEPLOY_PATH' && $PHP bin/sync.php"

  # Nightly at 05:17 server time; output goes to data/cache/sync.log.
  remote "dir=\$(cd ~/'$DEPLOY_PATH' && pwd) && line=\"17 5 * * * $PHP \$dir/bin/sync.php >> \$dir/data/cache/sync.log 2>&1 $CRON_TAG\" && (crontab -l 2>/dev/null | grep -vF '$CRON_TAG'; echo \"\$line\") | crontab -"
  note "Nightly cron job: $(remote "crontab -l 2>/dev/null | grep -cF '$CRON_TAG'") entry, 05:17 server time"
  report "cd '$DEPLOY_PATH' && $PHP bin/healthcheck.php"
}

task_sync() {
  need_php
  need_config
  report "cd '$DEPLOY_PATH' && $PHP bin/sync.php"
  report "cd '$DEPLOY_PATH' && $PHP bin/healthcheck.php"
}

case "${1:-}" in
  check)  connect; task_check ;;
  deploy) connect; task_deploy ;;
  setup)  connect; task_setup ;;
  sync)   connect; task_sync ;;
  *) echo "Usage: $0 check|deploy|setup|sync" >&2; exit 2 ;;
esac
