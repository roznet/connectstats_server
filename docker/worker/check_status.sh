#!/bin/bash
# Status canary for the live API (run twice a day through cronjob). Exits non-zero
# with the details on stdout when something is wrong, so healthchecks alerts.
#
#   1. status/cache?detail=1 must answer with "status": 1 (cache processing healthy)
#   2. a signed search for CHECK_TOKEN_ID must return more than one activity (the
#      whole request path: TLS, auth, database)
#
# Goes through the public URL on purpose (DNS, TLS, Caddy), not the container.
set -u

base="${CHECK_BASE_URL:-https://connectstats.app/prod}"
token="${CHECK_TOKEN_ID:-1}"
config="${CHECK_CONFIG:-/etc/connectstats/config.php}"
setup_dir="${CHECK_SETUP_DIR:-/var/www/html/prod/setup}"
problems=0

status=$(curl -sS -m 15 "${base}/api/status/cache?detail=1" 2>&1)
if [ "$(jq -r '.status' <<<"$status" 2>/dev/null)" != "1" ]; then
    echo "Issues with ${base}/api/status/cache"
    jq . <<<"$status" 2>/dev/null || echo "$status"
    problems=1
fi

url="${base}/api/connectstats/search?token_id=${token}"
response=$(cd "$setup_dir" && timeout 30 python3 query.py -c "$config" -t="$token" "$url" 2>&1)
total=$(jq -r '.paging.total // empty' <<<"$response" 2>/dev/null)
if [ -z "$total" ] || [ "$total" -le 1 ]; then
    echo "Issues with ${url} (total: ${total:-none})"
    jq . <<<"$response" 2>/dev/null || echo "$response"
    problems=1
else
    echo "search for token ${token}: ${total} activities"
fi

[ "$problems" -eq 0 ] && echo "status OK"
exit "$problems"
