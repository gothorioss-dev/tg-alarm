# TG Alarm

A Telegram bot that watches power outage schedules and sends users, in private messages, the schedule and its changes only for the queues they selected, as well as outages by address.

## Running (server with Docker)

```bash
cp .env.example .env      # fill in TELEGRAM_BOT_TOKEN and TELEGRAM_ADMIN_ID
docker compose up -d --build
docker compose logs -f worker reminders addresses monitor bot
```

No domain, HTTPS or open ports are needed (long polling). The administrator must send `/start` to the bot once, otherwise Telegram will not deliver service messages. Only one instance of the bot can run with a given token.

Updates: automatic after a push or merge to `main` (see "CI/CD").

### Backup

Once a day (after 03:30 Kyiv time) the worker makes a verified copy of the database in the `backups` volume and keeps the last 5 (`BACKUP_KEEP_DAYS` and `keep_min` in `config/backup.php`). Failures are reported to the administrator.

```bash
docker compose exec worker ls -l /backups                        # list
docker compose cp worker:/backups/daily-YYYY-MM-DD.sqlite .       # download
```

Restore (the current database is replaced, a copy of it is kept alongside):

```bash
docker compose stop worker reminders addresses monitor bot
docker compose run --rm --no-deps worker sh -c 'cd /data && rm -f before-restore.sqlite && php -r "(new PDO(\"sqlite:database.sqlite\"))->exec(\"VACUUM INTO \\x27before-restore.sqlite\\x27\");" && rm -f database.sqlite-wal database.sqlite-shm && cp /backups/daily-YYYY-MM-DD.sqlite database.sqlite'
docker compose up -d --no-build
```

## CI/CD

`.github/workflows/deploy.yml`: tests and code style; on success in `main`, GitHub builds the image, publishes it to `ghcr.io/gothorioss-dev/tg-alarm` tagged with the commit hash, and the server pulls and runs it.

- The image must be public (GitHub → Packages → `tg-alarm` → Package settings), otherwise the server cannot pull it and stays on the previous version.
- The CI key is restricted by the forced command `/usr/local/sbin/zoe-alarm-deploy` and deploys only commits from `main`.
- Order (`deploy/deploy.sh`): pull the image, copy the database to `/opt/zoe-alarm-backups` (last 5), run migrations, start, check.
- On failure the previous image is restored, but the database is not rolled back: migrations must be compatible with the previous version.
- The result is sent to the administrator in Telegram.
- Rolling back a version: `git revert` and push.

GitHub Actions secrets: `DEPLOY_SSH_KEY`, `DEPLOY_KNOWN_HOSTS`, `DEPLOY_HOST`.

Server setup: `install -m 755 deploy/zoe-alarm-deploy /usr/local/sbin/`, and in `authorized_keys`:
`restrict,command="/usr/local/sbin/zoe-alarm-deploy" ssh-ed25519 …`

## Monitoring

The `monitor` process checks disk, memory, load, delays, errors, flooding and the other processes once a minute and writes to the administrator directly; `bot` in turn watches `monitor`. Thresholds and quiet hours: `config/monitor.php`. A failure of the whole server cannot be detected this way.

## Sources

Configuration: `config/sources.php` (then rebuild).

- Change the main source: `priority` (higher = more important).
- Disable: `enabled`.
- Add: a class implementing `App\Sources\Source` and an entry in the config.
- Different version selection logic: a class implementing `App\Reconcile\ReconcileStrategy`, in `strategy`.

Source lag is visible in the `publications` table: `published_at` and `fetched_at`.

## Addresses

The "🚨 Відключення за адресою" button: emergency, urgent and planned repairs by street and house (experimental). Available only to users with `chats.perks_at`: the first `config/perks.php` (`limit`) users to select a queue get it and keep it after unsubscribing. Unavailable without a selected queue, like everything except queue selection and help: the rules are in `App\Telegram\Access`. Configuration: `config/addresses.php`.

- A separate `addresses` process (128 MB memory limit) with its own send queue: its failures do not delay schedules.
- The source is the ZOE_Alarm channel: a post with a `…ЗМЕМ….xlsx` file opens an outage, the same file later confirms its start, and replies extend and close it.
- The file is downloaded via the admin chat: the bot sends it to the administrator by the post link (`sendDocument`), reads it and immediately deletes the message. Requires `TELEGRAM_ADMIN_ID` and the administrator's `/start`. This Telegram method is undocumented; if it stops working, the administrator will get a message for each such post.
- The oblenergo page (Google Apps Script) is only a directory of streets and houses. Only the street name is sent out, without the user or the house number.
- Adding a source or city: classes implementing `OutageFeed` and `StreetDirectory`, and an entry in `cities`.

## Development

```bash
docker build --target dev -t zoe-alarm-dev .
docker run --rm -u $(id -u):$(id -g) -e HOME=/tmp -v "$PWD":/app -w /app zoe-alarm-dev composer install
docker run --rm -u $(id -u):$(id -g) -e HOME=/tmp -v "$PWD":/app -w /app zoe-alarm-dev vendor/bin/phpunit
```
