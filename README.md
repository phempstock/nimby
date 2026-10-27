# NIMBY – Planning watch for The Park

A map of planning applications in The Park Conservation Area, Nottingham, with a weekly email of new applications. Run by the Park Estate Residents' Association at [nimby.parknews.co.uk](https://nimby.parknews.co.uk).

## How it works

Once a night, `bin/sync.php` asks Nottingham City Council's public map server for every planning application inside The Park Conservation Area. It stores them in MySQL, logs any status or decision changes, and writes `public/data/applications.geojson` for the map page. Visitors only ever load that static file; the council's server is queried once a day.

| Source | What we use |
| --- | --- |
| [Planning Application Points (layer 13)](https://maps164.nottinghamcity.gov.uk/server/rest/services/Core_services/Planning/MapServer/13) | Applications: reference, address, proposal, dates, status, decision, location |
| [Conservation Areas (Local Plan layer 49)](https://maps164.nottinghamcity.gov.uk/server/rest/services/Core_services/Local_Plan_2020/MapServer/49) | The Park outline (`NAME = 'The Park'`, 57.8 ha), downloaded once and cached |
| [Public Access](https://publicaccess.nottinghamcity.gov.uk/online-applications/) | Each pin links to `applicationDetails.do?activeTab=summary&keyVal=<KEYVAL>` |

### What the data looks like (checked 26 October 2026)

- 5,771 applications inside The Park, going back to 1933; 2,681 are from before 2000.
- About 70–120 new applications a year recently (86 so far in 2026), so roughly two a week.
- Over half of recent applications are tree works (`PWC`, `PTPO`, `5DAY`).
- Every record has a key, a reference and a location; there are no duplicate points.
- The council truncates `PROPOSAL` at 300 characters; the full text is on Public Access.
- The full map file is about 4 MB, or 0.5 MB compressed (compression is set in `public/.htaccess`).

## Project layout

```
bin/         sync.php (nightly), migrate.php (creates tables)
src/         Council API client, storage, map-file export
migrations/  database schema (MySQL for IONOS, SQLite for tests)
public/      web root: the site, plus data/ written by the sync
tests/       php tests/run.php
config.example.php   copy to config.php on the server (never committed)
```

## Setting up on IONOS

Deployment and server tasks run from GitHub Actions over SSH (`deploy/ionos.sh`), so nothing needs typing on the server after the first connection.

### One-time steps

1. **Deploy key.** On your own computer, create a key used only for deployment. Press Enter twice when asked for a passphrase:
   ```
   ssh-keygen -t ed25519 -f ~/.ssh/nimby_deploy -C nimby-github-deploy
   ```
2. **Authorise the key on IONOS.** This also creates the `nimby` folder. It asks for your SSH password once:
   ```
   cat ~/.ssh/nimby_deploy.pub | ssh USER@HOST "mkdir -p ~/.ssh ~/nimby && chmod 700 ~/.ssh && cat >> ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys"
   ```
   The first time, ssh shows the server's `ED25519 key fingerprint is SHA256:...`. Add that fingerprint to `deploy/ionos-host-keys.txt`; the deploy script refuses any server whose key is not listed there.
3. **config.php.** Copy `config.example.php` to `config.php`, fill in the database details and password, and upload it into the `nimby` folder (not `public/`):
   ```
   scp config.php USER@HOST:nimby/config.php
   ```
4. **GitHub secrets.** Under **Settings > Secrets and variables > Actions > New repository secret**, add:
   - `IONOS_SSH_HOST`: the SSH host name
   - `IONOS_SSH_USER`: the SSH user name
   - `IONOS_SSH_KEY`: the whole of `~/.ssh/nimby_deploy` (the private key), including the BEGIN and END lines

   The repository is public. Host names, user names and passwords belong in secrets or `config.php`, never in committed files.
5. **Subdomain.** In the IONOS control panel, point `nimby.parknews.co.uk` at the webspace folder `/nimby/public` and switch on its SSL certificate.

### Server tasks (Actions > Server tasks > Run workflow)

| Task | What it does |
| --- | --- |
| `check` | Reports PHP version, rsync, cron, config and database status |
| `deploy` | Copies the code to the server and updates the database tables |
| `setup` | First-time setup: tables, first sync (back-fills history), nightly cron job at 05:17 server time |
| `sync` | Runs the sync now and reports the result |

Every push to `main` also deploys automatically once both test jobs pass. Deployment uses `rsync --delete`, but never touches the server's `config.php`, its `data/` folder (database cache and `sync.log`) or the generated map data. Without rsync it falls back to `tar`.

Everything the scripts print appears in the public Actions log, so it is filtered to remove host names, user names, database names and server paths.

### By hand over SSH

```
cd ~/nimby
/usr/bin/php8.3 bin/migrate.php       # create or update tables
/usr/bin/php8.3 bin/sync.php          # run the sync now
/usr/bin/php8.3 bin/healthcheck.php   # status report
tail data/cache/sync.log              # output of the nightly runs
```

On IONOS, plain `php` may be an old version; always use a `/usr/bin/php8.x` path.

## Development

```
php tests/run.php
```

The tests use an in-memory SQLite database and a fake council server, so they need no network or MySQL.

The map page has browser tests (Playwright and Chromium) against about 5,800 generated applications:

```
python3 tests/browser/make-data.py public/data
php -S 127.0.0.1:8123 -t public &
node tests/browser/map.test.js http://127.0.0.1:8123 public/data
```

GitHub Actions runs all of these on every push, and only deploys if they pass.

### Third-party files

Leaflet, marker clustering and the Alegreya Sans font are served from `public/vendor/`, so visitors' browsers contact no other servers apart from OpenStreetMap for map tiles. To update them, run **Actions > Update vendored libraries**; versions and checksums are recorded in `public/vendor/VERSIONS.txt`. For a local trial run, point `config.php` at SQLite (`'dsn' => 'sqlite:/tmp/nimby.sqlite'`), then run `bin/migrate.php` and `bin/sync.php`.

## Safeguards

- If the council renames a field we use, the sync stops and emails the admin.
- If the council returns nothing, or fewer than 80% of the applications we already hold, the sync stops and keeps yesterday's data.
- Database changes happen in one transaction, and map files are written atomically, so a failed run never leaves the map half-updated.
- Applications that vanish from the council feed are marked as removed, never deleted, and are restored if they reappear.
- Status and decision wordings not in `src/Council/Status.php` are listed in the sync log so they can be categorised.

## Roadmap

- [x] Phase 0: data checks
- [x] Phase 1: nightly sync, live on IONOS since 27 October 2026
- [x] Phase 2: map page (Leaflet + OpenStreetMap, pins by status, popups, filters, list, deep links `/?app=26-01848-PWC`)
- [ ] Phase 3: subscriptions and the Monday digest of new applications
- [ ] Phase 4: launch

## Data licence

Contains public sector information from Nottingham City Council, licensed under the Open Government Licence. © Crown copyright and database rights, OS AC0000822898. Map tiles © OpenStreetMap contributors.
