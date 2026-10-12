FROM postgres:17-alpine

COPY supabase/migrations/ /migrations/supabase/
COPY deployment/supabase/migrate.sh /migrations/migrate.sh

RUN chmod 0555 /migrations/migrate.sh

CMD ["/bin/sh", "/migrations/migrate.sh"]
