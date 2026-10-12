alter table public.companies
  add column cnpj_registry_data jsonb not null default '{}'::jsonb,
  add column cnpj_registry_fetched_at timestamptz;

comment on column public.companies.cnpj_registry_data is
  'Allowlisted public company registry details returned by the cnpj.pw lookup; excludes partner records.';
