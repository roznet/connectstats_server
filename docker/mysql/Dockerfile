# MySQL 8.4 with ConnectStats settings and first-run init baked in.
# (Bind-mounting the init script from macOS made the entrypoint try to execute it.)
# Pinned by version and digest: with a floating tag, every `docker compose build` re-resolves
# mysql:8.4 from Docker Hub, so a new patch release silently rebuilds this image and the next
# `up -d` restarts (and upgrades) the database. Bump deliberately, in its own deploy:
#   docker buildx imagetools inspect mysql:8.4.N   (Digest line)
FROM mysql:8.4.13@sha256:a81d043930e51745522bd5c430961cf8286f0a23f0a9a2a6e0108f7ea71b0902

COPY --chmod=644 connectstats.cnf /etc/mysql/conf.d/connectstats.cnf
COPY --chmod=644 init/ /docker-entrypoint-initdb.d/
