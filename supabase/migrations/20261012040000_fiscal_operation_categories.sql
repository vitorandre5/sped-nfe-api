alter table public.fiscal_documents
  add column operation_categories text[] not null default '{}',
  add column cfops text[] not null default '{}',
  add column operation_nature text;

create index fiscal_documents_operation_categories_idx
  on public.fiscal_documents using gin (operation_categories);
