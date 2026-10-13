alter table public.fiscal_documents
  add column municipal_tax jsonb;

comment on column public.fiscal_documents.municipal_tax is
  'ISSQN and municipal tax details extracted from the original NFS-e XML when provided.';
