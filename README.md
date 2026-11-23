# ZOE Alarm

Telegram-бот: следит за графиками ГПВ Запоріжжяобленерго и присылает в личные сообщения график и изменения только по выбранным очередям.

## Запуск (сервер с Docker)

```bash
cp .env.example .env      # заполнить TELEGRAM_BOT_TOKEN и TELEGRAM_ADMIN_ID
docker compose up -d --build
docker compose logs -f worker bot
```

Домен, HTTPS и открытые порты не нужны (long polling). Администратор должен один раз написать боту `/start`, иначе Telegram не доставит служебные сообщения. С одним токеном может работать только одна копия бота.

Обновление: автоматически после push или merge в `main` (см. «CI/CD»).

### Бэкап

Worker раз в сутки (после 03:30 по Киеву) делает проверенную копию базы в volume `backups` и удаляет копии старше `BACKUP_KEEP_DAYS` дней (по умолчанию 30, `config/backup.php`; последние 3 остаются всегда). О сбое сообщает администратору.

```bash
docker compose exec worker ls -l /backups                        # список
docker compose cp worker:/backups/daily-ГГГГ-ММ-ДД.sqlite .       # скачать
```

Восстановление (текущая база заменяется, её копия остаётся рядом):

```bash
docker compose stop worker bot
docker compose run --rm --no-deps worker sh -c 'cd /data && rm -f before-restore.sqlite && php -r "(new PDO(\"sqlite:database.sqlite\"))->exec(\"VACUUM INTO \\x27before-restore.sqlite\\x27\");" && rm -f database.sqlite-wal database.sqlite-shm && cp /backups/daily-ГГГГ-ММ-ДД.sqlite database.sqlite'
docker compose up -d --no-build
```

## CI/CD

`.github/workflows/deploy.yml`: тесты, стиль и сборка образа; при успехе в `main` сервер разворачивает этот коммит.

- Ключ CI ограничен forced command `/usr/local/sbin/zoe-alarm-deploy` и разворачивает только коммиты из `main`.
- Порядок (`deploy/deploy.sh`): сборка, копия базы в `/opt/zoe-alarm-backups` (последние 5), миграции, запуск, проверка.
- При ошибке возвращается прежний образ, база назад не откатывается: миграции должны быть совместимы с предыдущей версией.
- Результат приходит администратору в Telegram.
- Откат версии: `git revert` и push.

Секреты GitHub Actions: `DEPLOY_SSH_KEY`, `DEPLOY_KNOWN_HOSTS`, `DEPLOY_HOST`.

Установка на сервере: `install -m 755 deploy/zoe-alarm-deploy /usr/local/sbin/`, в `authorized_keys`:
`restrict,command="/usr/local/sbin/zoe-alarm-deploy" ssh-ed25519 …`

## Источники

Настройка: `config/sources.php` (затем пересборка).

- Поменять главный источник: `priority` (больше = главнее).
- Отключить: `enabled`.
- Добавить: класс, реализующий `App\Sources\Source`, и запись в конфиге.
- Другая логика выбора версии: класс, реализующий `App\Reconcile\ReconcileStrategy`, в `strategy`.

Отставание источников видно в таблице `publications`: `published_at` и `fetched_at`.

## Разработка

```bash
docker build --target dev -t zoe-alarm-dev .
docker run --rm -u $(id -u):$(id -g) -e HOME=/tmp -v "$PWD":/app -w /app zoe-alarm-dev composer install
docker run --rm -u $(id -u):$(id -g) -e HOME=/tmp -v "$PWD":/app -w /app zoe-alarm-dev vendor/bin/phpunit
```
