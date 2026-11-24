#!/usr/bin/env bash
# Builds and starts the checked out release; on failure restores the previous one.
set -uo pipefail

previous=$1
sha=$2
backup_dir=${BACKUP_DIR:-/opt/zoe-alarm-backups}
image=zoe-alarm
services=(worker reminders bot)
stage=build
started=

notify() {
    docker compose run --rm --no-deps -T worker php artisan app:notify "$1" </dev/null >/dev/null 2>&1 ||
        echo "Notification was not sent" >&2
}

backup() {
    mkdir -p "$backup_dir" && chmod 700 "$backup_dir" || return 1
    # VACUUM INTO makes a consistent copy while the bot is running.
    docker compose run --rm --no-deps -T worker sh -c '
        test -s /data/database.sqlite || exit 0
        rm -f /data/backup.sqlite
        php -r "(new PDO(\"sqlite:/data/database.sqlite\"))->exec(\"VACUUM INTO \x27/data/backup.sqlite\x27\");" &&
        cat /data/backup.sqlite && rm -f /data/backup.sqlite' </dev/null >"$backup_dir/.new" || return 1
    if [[ -s $backup_dir/.new ]]; then
        mv "$backup_dir/.new" "$backup_dir/$(date -u +%Y%m%d-%H%M%S)-${previous:0:7}.sqlite" || return 1
    fi
    # Only the files created above are rotated.
    ls -1t "$backup_dir"/*.sqlite 2>/dev/null | tail -n +6 | xargs -r rm -f --
}

healthy() {
    local service id
    for _ in $(seq 1 12); do
        sleep 10
        local ok=1
        for service in "${services[@]}"; do
            id=$(docker compose ps -q "$service")
            [[ -n $id ]] || { ok=0; continue; }
            [[ $(docker inspect -f '{{.State.Running}} {{.RestartCount}}' "$id") == "true 0" ]] || return 1
        done
        # A bot with a wrong token fails within about 20 seconds, so wait for a stable run.
        if [[ $ok == 1 && $(($(date +%s) - started)) -ge 40 ]] &&
            docker compose exec -T worker php artisan app:health --since="$started" </dev/null; then
            return 0
        fi
    done
    return 1
}

restore() {
    git checkout --quiet --detach "$previous"
    if [[ $stage == start ]]; then
        docker compose logs --tail 30 >&2
        docker tag "$image:previous" "$image:latest" && docker compose up -d --no-build --force-recreate --remove-orphans
    fi
}

main() {
    docker tag "$image:latest" "$image:previous" || return 1
    # Built one service at a time: they share an image and the server is small.
    docker compose build --quiet migrate || { docker tag "$image:previous" "$image:latest"; return 1; }
    stage=backup
    backup || { docker tag "$image:previous" "$image:latest"; return 1; }
    stage=start
    started=$(date +%s)
    docker compose up -d --no-build --force-recreate --remove-orphans || return 1
    healthy
}

if main; then
    docker image prune -f >/dev/null
    docker builder prune -f --filter until=168h >/dev/null
    notify "Деплой ${sha:0:7} успішний"
    echo "Deployed $sha"
    exit 0
fi

echo "Deploy of $sha failed at stage: $stage" >&2
if restore; then
    notify "Деплой ${sha:0:7} не вдався (етап: $stage). Працює попередня версія ${previous:0:7}"
else
    notify "Деплой ${sha:0:7} не вдався (етап: $stage), повернути ${previous:0:7} не вийшло. Потрібна перевірка сервера"
fi
exit 1
