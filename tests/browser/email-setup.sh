#!/usr/bin/env bash
# Build a throwaway copy of the site for the email walk-through (tests/browser/email.test.js):
# SQLite database, emails written to a log file instead of being sent, history plus
# five new applications in The Park.
# Usage: bash tests/browser/email-setup.sh <target-dir> [site-url]
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
TARGET="$1"
URL="${2:-http://127.0.0.1:8130}"

rm -rf "$TARGET" && mkdir -p "$TARGET"
(cd "$ROOT" && git ls-files -co --exclude-standard -z | xargs -0 -I{} cp --parents {} "$TARGET/")
rm -f "$TARGET/config.php" "$TARGET/config.mail.php" "$TARGET"/public/data/*.geojson "$TARGET/public/data/meta.json"
mkdir -p "$TARGET/data/cache"

sed -e "s#'mysql:host=localhost;dbname=nimby;charset=utf8mb4'#'sqlite:' . __DIR__ . '/data/nimby.sqlite'#" \
    -e "s#'buffer_metres' => 50,#'buffer_metres' => 0,#" \
    "$ROOT/config.example.php" > "$TARGET/config.php"
cat > "$TARGET/config.mail.php" <<PHP
<?php
return [
    'site' => ['url' => '$URL'],
    'mail' => ['transport' => 'log', 'log_file' => __DIR__ . '/data/cache/mail.log', 'pause_ms' => 0],
];
PHP

cd "$TARGET"
php bin/migrate.php > /dev/null
php tests/browser/seed-email.php
