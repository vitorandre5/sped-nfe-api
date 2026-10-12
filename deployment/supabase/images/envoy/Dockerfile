FROM envoyproxy/envoy:v1.39.1

COPY deployment/supabase/coolify-config/api/envoy/envoy.yaml /etc/envoy/envoy.yaml
COPY deployment/supabase/coolify-config/api/envoy/cds.yaml /etc/envoy/cds.yaml
COPY deployment/supabase/coolify-config/api/envoy/lds.template.yaml /etc/envoy/lds.template.yaml
COPY deployment/supabase/coolify-config/api/envoy/docker-entrypoint.sh /docker-entrypoint.sh

ENTRYPOINT ["/bin/sh", "/docker-entrypoint.sh"]
