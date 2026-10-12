FROM supabase/postgres:17.6.1.136

COPY deployment/supabase/coolify-config/db/realtime.sql /docker-entrypoint-initdb.d/migrations/99-realtime.sql
COPY deployment/supabase/coolify-config/db/webhooks.sql /docker-entrypoint-initdb.d/init-scripts/98-webhooks.sql
COPY deployment/supabase/coolify-config/db/roles.sql /docker-entrypoint-initdb.d/init-scripts/99-roles.sql
COPY deployment/supabase/coolify-config/db/jwt.sql /docker-entrypoint-initdb.d/init-scripts/99-jwt.sql
COPY deployment/supabase/coolify-config/db/_supabase.sql /docker-entrypoint-initdb.d/migrations/97-_supabase.sql
COPY deployment/supabase/coolify-config/db/logs.sql /docker-entrypoint-initdb.d/migrations/99-logs.sql
COPY deployment/supabase/coolify-config/db/pooler.sql /docker-entrypoint-initdb.d/migrations/99-pooler.sql
