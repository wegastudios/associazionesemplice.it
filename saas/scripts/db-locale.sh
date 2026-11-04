#!/usr/bin/env bash
# Prepara PostgreSQL 16 in locale (sviluppo e CI) con i due ruoli del SaaS.
#
#   as_owner  proprietario dei database e delle tabelle, esegue le migrazioni
#   as_app    usato dalle richieste web e dalle code: niente superuser, niente BYPASSRLS
#
# Sul server i ruoli li crea cPanel; questo script serve solo in locale e in CI.
# Le password di sviluppo sono finte e valgono solo qui. Va eseguito con un ruolo superuser:
#   sudo -u postgres scripts/db-locale.sh            (in locale)
#   PGHOST=localhost PGUSER=postgres scripts/db-locale.sh   (in CI)
set -euo pipefail

OWNER="${AS_DB_OWNER:-as_owner}"
OWNER_PASSWORD="${AS_DB_OWNER_PASSWORD:-owner-locale}"
APP="${AS_DB_APP:-as_app}"
APP_PASSWORD="${AS_DB_APP_PASSWORD:-app-locale}"
DATABASES="${AS_DATABASES:-as_central as_test as_test_dedicato}"

psql -v ON_ERROR_STOP=1 -q <<SQL
DO \$\$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '${OWNER}') THEN
    CREATE ROLE ${OWNER} LOGIN NOSUPERUSER NOBYPASSRLS NOCREATEROLE NOCREATEDB PASSWORD '${OWNER_PASSWORD}';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '${APP}') THEN
    CREATE ROLE ${APP} LOGIN NOSUPERUSER NOBYPASSRLS NOCREATEROLE NOCREATEDB PASSWORD '${APP_PASSWORD}';
  END IF;
END
\$\$;
SQL

for db in ${DATABASES}; do
  if ! psql -tAc "SELECT 1 FROM pg_database WHERE datname = '${db}'" | grep -q 1; then
    psql -v ON_ERROR_STOP=1 -q -c "CREATE DATABASE ${db} OWNER ${OWNER} ENCODING 'UTF8' TEMPLATE template0"
  fi
  # Nessuno oltre ai due ruoli si collega; il ruolo dell'applicazione non crea nulla in public.
  psql -v ON_ERROR_STOP=1 -q -d "${db}" <<SQL
REVOKE ALL ON DATABASE ${db} FROM PUBLIC;
GRANT CONNECT ON DATABASE ${db} TO ${OWNER}, ${APP};
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
ALTER SCHEMA public OWNER TO ${OWNER};
SQL
  echo "pronto: ${db}"
done
