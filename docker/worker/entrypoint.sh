#!/bin/bash
# Worker container entrypoint (runs as www-data, under tini).
#
# - Owns the queue: `queuectl.php start` every QUEUE_CHECK_INTERVAL seconds starts any
#   missing `runqueue.php` workers. Workers are tracked by PID (`ps ax`), so queuectl and
#   the workers must share this container's PID namespace. Web requests never spawn
#   workers here (config 'queue_autostart' => false).
# - Runs scheduled jobs with supercronic from $CRONTAB, if the file exists.
# - On SIGTERM (docker compose stop worker): asks workers to stop gracefully, then exits.
set -u
# Group-writable files on the Volume (host group dockerapp).
umask 0002

API_DIR=/var/www/html/prod/api
CRONTAB="${CRONTAB:-/var/www/html/prod/docker/worker/crontab}"
INTERVAL="${QUEUE_CHECK_INTERVAL:-60}"

log() { printf '%s worker: %s\n' "$(date -u +%FT%TZ)" "$*"; }

stopping=0
shutdown() {
    stopping=1
    log "stopping queues"
    [[ -n "${cron_pid:-}" ]] && kill "${cron_pid}" 2>/dev/null
    (cd "${API_DIR}/queue" && php queuectl.php stop)
    # Workers see status 'stop' between tasks (every queue_sleep seconds) and exit;
    # wait for them so a running task isn't killed mid-way. Docker's stop_grace_period
    # (120s) is the upper bound.
    while pgrep -f 'runqueue.php' >/dev/null; do
        sleep 1
    done
    log "all queues stopped"
    exit 0
}
trap shutdown TERM INT

# Wait for the database before the first start.
for i in $(seq 1 60); do
    php -r 'include "config.php"; mysqli_report(MYSQLI_REPORT_OFF);
            $m = @new mysqli($api_config["db_host"], $api_config["db_username"], $api_config["db_password"]);
            exit($m->connect_errno ? 1 : 0);' && break
    log "waiting for database (${i})"
    sleep 2
done

if [[ -s "${CRONTAB}" ]]; then
    log "starting supercronic with ${CRONTAB}"
    supercronic -passthrough-logs "${CRONTAB}" &
    cron_pid=$!
else
    log "no crontab at ${CRONTAB}, scheduled jobs disabled"
fi

while (( ! stopping )); do
    (cd "${API_DIR}/queue" && php queuectl.php start) | grep -vE '^\s*$' || true
    sleep "${INTERVAL}" &
    wait $!
done
