# MySQL 8.4 with ConnectStats settings and first-run init baked in.
# (Bind-mounting the init script from macOS made the entrypoint try to execute it.)
FROM mysql:8.4

COPY --chmod=644 connectstats.cnf /etc/mysql/conf.d/connectstats.cnf
COPY --chmod=644 init/ /docker-entrypoint-initdb.d/
