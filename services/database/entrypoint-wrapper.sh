#!/bin/bash
set -euo pipefail

# Sustituye variables de entorno (FLAG_DATABASE, DB_APP_USER, DB_APP_PASSWORD, PIVOT_SSH_PASSWORD) en la
# plantilla SQL y deja el resultado donde el entrypoint oficial de MariaDB espera los scripts de
# inicializacion. Se hace aqui, en runtime, para que ningun valor real quede commiteado en git (ver
# CLAUDE.md, "Reglas para mantener consistencia entre flags").
mkdir -p /docker-entrypoint-initdb.d
envsubst '${DB_APP_USER} ${DB_APP_PASSWORD} ${FLAG_DATABASE} ${PIVOT_SSH_PASSWORD}' \
    < /docker-entrypoint-initdb.templates/01-seed.sql.template \
    > /docker-entrypoint-initdb.d/01-seed.sql

exec docker-entrypoint.sh "$@"
