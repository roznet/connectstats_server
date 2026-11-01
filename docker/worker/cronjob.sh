#!/bin/bash
# Run one scheduled job with a log file and healthchecks.io pings.
#
#   cronjob <slug> <shell command>
#
# Used from the worker's crontab (supercronic). The command runs with `bash -c`,
# output goes to $CRON_LOG_DIR/<slug>_<UTC time>.log (cleaned with the other logs
# after 15 days), and supercronic only gets one summary line.
#
# healthchecks.io (when HEALTHCHECKSIO_PING_KEY is set; otherwise no pings):
#   /start before, success with the log tail after, or the exit code with a longer
#   tail on failure, to check "<HC_PREFIX><slug>" (auto-created on first ping; set
#   its schedule in the dashboard). A run that exits 0 but printed a PHP fatal or an
#   uncaught exception counts as a failure: several scripts don't set an exit code.
set -u
umask 0002

if [ $# -lt 2 ]; then
    echo "usage: cronjob <slug> <shell command>" >&2
    exit 2
fi
slug=$1; shift
cmd="$*"

log_dir="${CRON_LOG_DIR:-/var/www/log/cron}"
mkdir -p "$log_dir"
log="${log_dir}/${slug}_$(date -u +%Y%m%dT%H%M%SZ).log"
check="${HC_PREFIX:-connectstats-}${slug}"

hc() {
    # $1 = "/start", "" (success) or "/<exit code>"; body on stdin.
    if [ -z "${HEALTHCHECKSIO_PING_KEY:-}" ]; then
        cat >/dev/null
        return 0
    fi
    curl -fsS -m 10 --retry 3 --data-binary @- \
        "https://hc-ping.com/${HEALTHCHECKSIO_PING_KEY}/${check}${1}?create=1" >/dev/null 2>&1 \
        || echo "[$(date -u +%FT%TZ)] WARNING: healthchecks ping '${1:-success}' did not get through" >>"$log"
}

hc /start </dev/null
start=$(date +%s)
echo "[$(date -u +%FT%TZ)] ${slug}: ${cmd}" >>"$log"

bash -c "$cmd" >>"$log" 2>&1 </dev/null
rc=$?

if [ "$rc" -eq 0 ] && grep -qE 'PHP (Fatal|Parse) error|Uncaught [A-Za-z]+(Error|Exception)' "$log"; then
    echo "[$(date -u +%FT%TZ)] exit 0 but a PHP fatal/uncaught error is in the output: reported as failed" >>"$log"
    rc=1
fi
echo "[$(date -u +%FT%TZ)] ${slug}: exit ${rc} after $(( $(date +%s) - start ))s" >>"$log"

if [ "$rc" -eq 0 ]; then
    tail -n 20 "$log" | hc ""
else
    tail -n 50 "$log" | hc "/${rc}"
fi

echo "cronjob ${slug}: exit ${rc} (${log})"
exit "$rc"
