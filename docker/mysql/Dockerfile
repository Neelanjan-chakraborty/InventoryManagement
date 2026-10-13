# MySQL with the first-boot init script baked in.
#
# The script is COPYed rather than bind-mounted on purpose: a bind mount would
# require the project directory to be listed in Docker Desktop's File Sharing
# settings, which would break the plain `docker compose up` path on a fresh machine.
FROM mysql:8.4

COPY init/ /docker-entrypoint-initdb.d/
