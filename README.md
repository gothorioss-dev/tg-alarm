# ZOE Alarm

Telegram-бот: следит за графиками ГПВ Запоріжжяобленерго и присылает график и изменения только по выбранным очередям.

## Запуск (сервер с Docker)

```bash
cp .env.example .env      # заполнить TELEGRAM_BOT_TOKEN и TELEGRAM_ADMIN_ID
docker compose up -d --build
docker compose logs -f worker bot
```

Домен, HTTPS и открытые порты не нужны (long polling). Администратор должен один раз написать боту `/start`, иначе Telegram не доставит служебные сообщения. С одним токеном может работать только одна копия бота.

Обновление: доставить новый код и снова выполнить `docker compose up -d --build`. Миграции применяются автоматически.

Бэкап БД: `docker compose stop && docker compose cp worker:/data/. ./backup && docker compose start worker bot`

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
