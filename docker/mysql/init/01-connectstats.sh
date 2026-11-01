#!/bin/bash
# Runs once, on an empty datadir (mysql image's /docker-entrypoint-initdb.d).
# Users and grants are not part of a mysqldump, so the app user is created here.
#
# Env (from .env via compose):
#   CS_DB_USER, CS_DB_PASSWORD   app user (caching_sha2_password, the 8.4 default)
#   CS_DATABASES                 space-separated list of databases to create
#   CS_DEV_MARKER_DB             optional: database that gets the `dev` marker table,
#                                which allows reset_schema() (local/test only, never prod)
#
# The mysql entrypoint *sources* this file (it isn't executable), so no `set -e` here.

: "${CS_DB_USER:?CS_DB_USER not set}"
: "${CS_DB_PASSWORD:?CS_DB_PASSWORD not set}"
: "${CS_DATABASES:?CS_DATABASES not set}"

sql="CREATE USER IF NOT EXISTS '${CS_DB_USER}'@'%' IDENTIFIED BY '${CS_DB_PASSWORD}';"
for db in ${CS_DATABASES}; do
    sql+="CREATE DATABASE IF NOT EXISTS \`${db}\` CHARACTER SET utf8mb4;"
    sql+="GRANT ALL PRIVILEGES ON \`${db}\`.* TO '${CS_DB_USER}'@'%';"
done
if [[ -n "${CS_DEV_MARKER_DB:-}" ]]; then
    sql+="CREATE TABLE IF NOT EXISTS \`${CS_DEV_MARKER_DB}\`.\`dev\` (id INT);"
fi
sql+="FLUSH PRIVILEGES;"

mysql -uroot -p"${MYSQL_ROOT_PASSWORD}" -e "${sql}"
echo "connectstats init: user ${CS_DB_USER}, databases: ${CS_DATABASES}${CS_DEV_MARKER_DB:+, dev marker in ${CS_DEV_MARKER_DB}}"
