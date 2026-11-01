# SSH tunnel to the old droplet's MySQL, used only while the new box replicates
# (docker-compose.migration.yml). The old MySQL listens on 127.0.0.1 only, so the
# replica reaches it as `source-tunnel:3306` through this container.
FROM alpine:3.22

RUN apk add --no-cache openssh-client autossh

# autossh restarts ssh when the connection drops; keepalives detect a dead link.
ENTRYPOINT ["sh", "-c", "exec autossh -M 0 -N \
  -o ServerAliveInterval=30 -o ServerAliveCountMax=3 \
  -o ExitOnForwardFailure=yes -o BatchMode=yes \
  -o UserKnownHostsFile=/ssh/known_hosts -o StrictHostKeyChecking=yes \
  -i /ssh/id_ed25519 \
  -L 0.0.0.0:3306:127.0.0.1:3306 \
  \"$SOURCE_SSH\""]
