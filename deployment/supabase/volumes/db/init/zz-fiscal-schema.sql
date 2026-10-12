create extension if not exists pgcrypto with schema extensions;

create table public.organizations (
  id uuid primary key default gen_random_uuid(),
  name text not null check (length(trim(name)) between 1 and 100),
  created_at timestamptz not null default now()
);

create table public.organization_members (
  organization_id uuid not null references public.organizations(id) on delete cascade,
  user_id uuid not null references auth.users(id) on delete cascade,
  role text not null default 'admin' check (role in ('admin', 'member')),
  created_at timestamptz not null default now(),
  primary key (organization_id, user_id),
  unique (user_id)
);

create table public.companies (
  id uuid primary key default gen_random_uuid(),
  organization_id uuid not null references public.organizations(id) on delete cascade,
  cnpj text not null check (cnpj ~ '^\d{14}$'),
  name text not null check (length(trim(name)) between 1 and 160),
  state char(2) not null check (state in ('AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO')),
  last_synced_at timestamptz,
  created_at timestamptz not null default now(),
  unique (organization_id, cnpj),
  unique (id, organization_id)
);

create table public.sync_cursors (
  id uuid primary key default gen_random_uuid(),
  organization_id uuid not null,
  company_id uuid not null,
  document_type text not null check (document_type in ('nfe55', 'cte57', 'nfse')),
  last_nsu text not null default '000000000000000' check (last_nsu ~ '^\d{15}$'),
  max_nsu text check (max_nsu is null or max_nsu ~ '^\d{15}$'),
  next_sync_at timestamptz,
  last_run_at timestamptz,
  created_at timestamptz not null default now(),
  unique (company_id, document_type),
  foreign key (company_id, organization_id) references public.companies(id, organization_id) on delete cascade
);

create table public.sync_runs (
  id uuid primary key default gen_random_uuid(),
  organization_id uuid not null,
  company_id uuid not null,
  document_type text not null check (document_type in ('nfe55', 'cte57', 'nfse')),
  status text not null check (status in ('running', 'completed', 'failed')),
  cursor_before text not null,
  cursor_after text,
  document_count integer not null default 0 check (document_count >= 0),
  cstat text,
  message text,
  started_at timestamptz not null default now(),
  finished_at timestamptz,
  foreign key (company_id, organization_id) references public.companies(id, organization_id) on delete cascade
);

create table public.fiscal_documents (
  id uuid primary key default gen_random_uuid(),
  organization_id uuid not null,
  company_id uuid not null,
  document_type text not null,
  external_id text not null,
  access_key text,
  nsu text not null,
  issuer_name text,
  issuer_cnpj text,
  recipient_name text,
  recipient_cnpj text,
  document_number text,
  series text,
  issued_at timestamptz,
  total_amount numeric(15,2) check (total_amount is null or total_amount >= 0),
  situation text not null,
  storage_path text not null,
  xml_sha256 text,
  created_at timestamptz not null default now(),
  unique (company_id, document_type, external_id),
  foreign key (company_id, organization_id) references public.companies(id, organization_id) on delete cascade
);

create index fiscal_documents_org_issued_at_idx on public.fiscal_documents (organization_id, issued_at desc nulls last);
create index fiscal_documents_org_company_idx on public.fiscal_documents (organization_id, company_id);
create index fiscal_documents_access_key_idx on public.fiscal_documents (access_key) where access_key is not null;
create index sync_runs_company_started_idx on public.sync_runs (company_id, started_at desc);

create or replace function public.is_org_member(target_org uuid)
returns boolean
language sql
stable
security definer
set search_path = pg_catalog, public
as $$
  select exists (
    select 1 from public.organization_members m
    where m.organization_id = target_org and m.user_id = auth.uid()
  );
$$;

create or replace function public.create_organization(org_name text)
returns uuid
language plpgsql
security definer
set search_path = pg_catalog, public
as $$
declare
  new_org_id uuid;
  clean_name text := trim(coalesce(org_name, ''));
begin
  if auth.uid() is null then
    raise exception 'Authentication required';
  end if;
  if length(clean_name) not between 1 and 100 then
    raise exception 'Organization name must contain 1 to 100 characters';
  end if;
  if exists (select 1 from public.organization_members where user_id = auth.uid()) then
    raise exception 'This user already belongs to an organization';
  end if;
  insert into public.organizations (name) values (clean_name) returning id into new_org_id;
  insert into public.organization_members (organization_id, user_id, role) values (new_org_id, auth.uid(), 'admin');
  return new_org_id;
end;
$$;

revoke all on function public.is_org_member(uuid) from public;
grant execute on function public.is_org_member(uuid) to authenticated;
revoke all on function public.create_organization(text) from public;
grant execute on function public.create_organization(text) to authenticated;

alter table public.organizations enable row level security;
alter table public.organization_members enable row level security;
alter table public.companies enable row level security;
alter table public.sync_cursors enable row level security;
alter table public.sync_runs enable row level security;
alter table public.fiscal_documents enable row level security;

create policy "members read their organizations" on public.organizations
  for select to authenticated using (public.is_org_member(id));
create policy "members read organization membership" on public.organization_members
  for select to authenticated using (user_id = auth.uid() or public.is_org_member(organization_id));
create policy "members manage their companies" on public.companies
  for all to authenticated using (public.is_org_member(organization_id))
  with check (public.is_org_member(organization_id));
create policy "members manage sync cursors" on public.sync_cursors
  for all to authenticated using (public.is_org_member(organization_id))
  with check (public.is_org_member(organization_id));
create policy "members manage sync runs" on public.sync_runs
  for all to authenticated using (public.is_org_member(organization_id))
  with check (public.is_org_member(organization_id));
create policy "members manage fiscal documents" on public.fiscal_documents
  for all to authenticated using (public.is_org_member(organization_id))
  with check (public.is_org_member(organization_id));

insert into storage.buckets (id, name, public, file_size_limit, allowed_mime_types)
values ('fiscal-xml', 'fiscal-xml', false, 20971520, array['application/xml', 'text/xml']);

create policy "members read their fiscal xml" on storage.objects
  for select to authenticated
  using (bucket_id = 'fiscal-xml' and public.is_org_member((storage.foldername(name))[1]::uuid));
create policy "members upload their fiscal xml" on storage.objects
  for insert to authenticated
  with check (bucket_id = 'fiscal-xml' and public.is_org_member((storage.foldername(name))[1]::uuid));
create policy "members update their fiscal xml" on storage.objects
  for update to authenticated
  using (bucket_id = 'fiscal-xml' and public.is_org_member((storage.foldername(name))[1]::uuid))
  with check (bucket_id = 'fiscal-xml' and public.is_org_member((storage.foldername(name))[1]::uuid));


alter table public.sync_cursors
  drop constraint sync_cursors_document_type_check,
  add constraint sync_cursors_document_type_check
    check (document_type in ('nfe55', 'nfe55_hom', 'cte57', 'nfse'));

alter table public.sync_runs
  drop constraint sync_runs_document_type_check,
  add constraint sync_runs_document_type_check
    check (document_type in ('nfe55', 'nfe55_hom', 'cte57', 'nfse'));
