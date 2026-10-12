#!/bin/sh
set -eu

psql -v ON_ERROR_STOP=1 -c \
  'CREATE SCHEMA IF NOT EXISTS supabase_migrations;
   CREATE TABLE IF NOT EXISTS supabase_migrations.schema_migrations (
     version text PRIMARY KEY,
     inserted_at timestamptz NOT NULL DEFAULT now()
   );'

for migration in /migrations/supabase/*.sql; do
  version="$(basename "$migration" .sql)"
  applied="$(psql -At -v ON_ERROR_STOP=1 -c \
    "SELECT EXISTS (SELECT 1 FROM supabase_migrations.schema_migrations WHERE version = '${version}');")"
  if [ "$applied" = "t" ]; then
    continue
  fi

  psql --single-transaction -v ON_ERROR_STOP=1 -f "$migration" -c \
    "INSERT INTO supabase_migrations.schema_migrations (version) VALUES ('${version}');"
done
