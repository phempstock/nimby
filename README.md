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

1. **Database.** In the IONOS control panel, create a MySQL database. Note its host, name and user.
2. **Subdomain.** Create `nimby.parknews.co.uk`, point it at the folder `nimby/public`, and switch on the free SSL certificate.
3. **Upload the code.** Either set up the GitHub deployment below, or over SSH:
   ```
   git clone https://github.com/phempstock/nimby.git ~/nimby
   ```
4. **Configure.** Copy `config.example.php` to `config.php` in the `nimby` folder (not in `public/`) and fill in the database details.
5. **Create the tables.**
   ```
   php ~/nimby/bin/migrate.php
   ```
6. **First sync.** This back-fills the full history; those applications are marked so they are never emailed.
   ```
   php ~/nimby/bin/sync.php
   ```
7. **Nightly cron.** Find the PHP binary with `ls /usr/bin/php*`, then `crontab -e` and add:
   ```
   MAILTO=nimby@parknews.co.uk
   15 5 * * * /usr/bin/php8.3 $HOME/nimby/bin/sync.php > /dev/null
   ```
   Cron times are in the server's time zone, which may not be UK time. Successful runs are silent; failures are emailed by the script and by cron.

### Automatic deployment from GitHub

`.github/workflows/ci.yml` runs the tests on every push. After the tests pass on `main`, it copies the site to IONOS with `rsync` over SSH. It never touches the server's `config.php`, cache or map data. To switch it on:

1. Create an SSH key pair for deployment and add the public key to `~/.ssh/authorized_keys` on IONOS.
2. In GitHub, go to **Settings > Secrets and variables > Actions** and add these secrets:
   - `IONOS_SSH_HOST`
   - `IONOS_SSH_USER`
   - `IONOS_SSH_KEY`: the private key
   - `IONOS_KNOWN_HOSTS`: the output of `ssh-keyscan <host>`
   - `IONOS_DEPLOY_PATH`: for example `nimby`
3. Add a repository variable `DEPLOY_ENABLED` set to `true`.

## Development

```
php tests/run.php
```

The tests use an in-memory SQLite database and a fake council server, so they need no network or MySQL. For a local trial run, point `config.php` at SQLite (`'dsn' => 'sqlite:/tmp/nimby.sqlite'`), then run `bin/migrate.php` and `bin/sync.php`.

## Safeguards

- If the council renames a field we use, the sync stops and emails the admin.
- If the council returns nothing, or fewer than 80% of the applications we already hold, the sync stops and keeps yesterday's data.
- Database changes happen in one transaction, and map files are written atomically, so a failed run never leaves the map half-updated.
- Applications that vanish from the council feed are marked as removed, never deleted, and are restored if they reappear.
- Status and decision wordings not in `src/Council/Status.php` are listed in the sync log so they can be categorised.

## Roadmap

- [x] Phase 0: data checks
- [x] Phase 1: nightly sync (this release; still to be installed on IONOS)
- [ ] Phase 2: map page (Leaflet + OpenStreetMap, pins, popups, filters, deep links `/?app=26-01848-PWC`)
- [ ] Phase 3: subscriptions and the Monday digest of new applications
- [ ] Phase 4: launch

## Data licence

Contains public sector information from Nottingham City Council, licensed under the Open Government Licence. © Crown copyright and database rights, OS AC0000822898. Map tiles © OpenStreetMap contributors.
