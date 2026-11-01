# ConnectStats server image: Apache + mod_php, also used for the queue worker.
#
# Build from the repo root:  docker build -f docker/Dockerfile -t connectstats .
# Targets:
#   app   (default) Apache + PHP CLI + supercronic, code baked in at /var/www/html/prod
#   test  app + Python venv for setup/test.py (local only, never deployed)
#
# Paths match the old droplet (/var/www/html/prod, /var/www/log, ...) so absolute
# task_cwd values stored in queue.tasks keep resolving after the migration.

FROM php:8.5-apache AS app

ARG SUPERCRONIC_VERSION=v0.2.49
ARG SUPERCRONIC_SHA256_AMD64=a53ae236602c7338aba3fbaff40bda6300eae3b9fedb8261eb06cfe3724430c1
ARG SUPERCRONIC_SHA256_ARM64=02aa0cb229ba09050cba6638059dadb9eedc2276632ea43d6a57a2f8c1629dd5
ARG TARGETARCH

# zsh/jq/curl: the cron wrapper scripts use them. procps: Queue::find_running_queues() calls `ps ax`.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libzip-dev zsh jq curl procps ca-certificates tini \
    && docker-php-ext-install mysqli zip \
    && rm -rf /var/lib/apt/lists/*

RUN case "${TARGETARCH:-amd64}" in \
        amd64) SHA="${SUPERCRONIC_SHA256_AMD64}" ;; \
        arm64) SHA="${SUPERCRONIC_SHA256_ARM64}" ;; \
        *) echo "unsupported arch ${TARGETARCH}" && exit 1 ;; \
    esac \
    && curl -fsSL -o /usr/local/bin/supercronic \
        "https://github.com/aptible/supercronic/releases/download/${SUPERCRONIC_VERSION}/supercronic-linux-${TARGETARCH:-amd64}" \
    && echo "${SHA}  /usr/local/bin/supercronic" | sha256sum -c - \
    && chmod +x /usr/local/bin/supercronic

RUN a2enmod rewrite headers remoteip \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/apache/connectstats.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php/connectstats.ini "$PHP_INI_DIR/conf.d/zz-connectstats.ini"
COPY docker/worker/entrypoint.sh /usr/local/bin/connectstats-worker
RUN chmod +x /usr/local/bin/connectstats-worker

# Writable runtime dirs (bind-mounted from the host/Volume in compose).
RUN mkdir -p /var/www/log /var/www/tmp /var/www/bugs /var/www/state /var/www/data \
    && chown -R www-data:www-data /var/www/log /var/www/tmp /var/www/bugs /var/www/state /var/www/data

# Code. config.php / config_bugreport.php are NOT in the image: mounted at runtime.
COPY --chown=www-data:www-data . /var/www/html/prod

WORKDIR /var/www/html/prod

# ---------------------------------------------------------------------------
FROM app AS test

RUN apt-get update \
    && apt-get install -y --no-install-recommends python3 python3-venv \
    && rm -rf /var/lib/apt/lists/* \
    && python3 -m venv /opt/venv \
    && /opt/venv/bin/pip install --no-cache-dir mysql-connector-python==9.1.0 urllib3==2.2.3

ENV PATH="/opt/venv/bin:${PATH}"
