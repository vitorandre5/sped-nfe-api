alter table public.companies
  drop constraint companies_cnpj_check,
  add constraint companies_cnpj_check
    check (cnpj ~ '^[A-Z0-9]{12}[0-9]{2}$');

alter table public.sync_cursors
  add column lock_token uuid,
  add column locked_until timestamptz;

alter table public.sync_runs
  drop constraint sync_runs_document_type_check,
  add constraint sync_runs_document_type_check
    check (document_type in ('nfe55', 'nfe55_hom', 'cte57', 'nfse'));

alter table public.fiscal_documents
  add column is_purchase boolean not null default false,
  add column is_sale boolean not null default false,
  add column source_company_ids uuid[] not null default '{}';

update public.fiscal_documents d
set is_purchase = exists (
      select 1 from public.companies c
      where c.organization_id = d.organization_id
        and c.cnpj = upper(coalesce(d.recipient_cnpj, ''))
    ),
    is_sale = exists (
      select 1 from public.companies c
      where c.organization_id = d.organization_id
        and c.cnpj = upper(coalesce(d.issuer_cnpj, ''))
    );

create table public.fiscal_document_sources (
  id uuid primary key default gen_random_uuid(),
  organization_id uuid not null,
  company_id uuid not null,
  fiscal_document_id uuid references public.fiscal_documents(id) on delete set null,
  source_type text not null check (source_type in ('legacy', 'nfe_distribution', 'local_emission')),
  environment smallint check (environment is null or environment in (1, 2)),
  source_external_id text not null,
  access_key text,
  nsu text,
  schema_name text,
  raw_sha256 text,
  storage_path text not null,
  received_at timestamptz not null default now(),
  unique (company_id, source_type, source_external_id),
  foreign key (company_id, organization_id)
    references public.companies(id, organization_id) on delete cascade
);

alter table public.fiscal_document_sources enable row level security;
create policy "members read fiscal document sources" on public.fiscal_document_sources
  for select to authenticated using (public.is_org_member(organization_id));
create policy "members create fiscal document sources" on public.fiscal_document_sources
  for insert to authenticated with check (public.is_org_member(organization_id));
create policy "members update fiscal document sources" on public.fiscal_document_sources
  for update to authenticated using (public.is_org_member(organization_id))
  with check (public.is_org_member(organization_id));

with ranked as (
  select d.id,
         first_value(d.id) over (
           partition by d.organization_id, coalesce(d.access_key, 'legacy:' || d.id::text)
           order by d.created_at, d.id
         ) as canonical_id
  from public.fiscal_documents d
), moved as (
  insert into public.fiscal_document_sources (
    organization_id, company_id, fiscal_document_id, source_type,
    source_external_id, access_key, nsu, raw_sha256, storage_path, received_at
  )
  select d.organization_id, d.company_id, ranked.canonical_id, 'legacy',
         d.id::text, d.access_key, d.nsu, d.xml_sha256, d.storage_path, d.created_at
  from public.fiscal_documents d
  join ranked on ranked.id = d.id
  on conflict (company_id, source_type, source_external_id) do nothing
  returning id
)
select count(*) from moved;

with ranked as (
  select id,
         row_number() over (
           partition by organization_id, access_key
           order by created_at, id
         ) as position
  from public.fiscal_documents
  where access_key is not null
)
delete from public.fiscal_documents d
using ranked r
where d.id = r.id and r.position > 1;

create unique index fiscal_documents_org_access_key_uidx
  on public.fiscal_documents (organization_id, access_key);
create index fiscal_document_sources_org_key_idx
  on public.fiscal_document_sources (organization_id, access_key);

update public.fiscal_documents d
set source_company_ids = coalesce((
  select array_agg(distinct s.company_id)
  from public.fiscal_document_sources s
  where s.fiscal_document_id = d.id
), '{}'::uuid[]);

create or replace function public.acquire_nfe_distribution_cursor(
  p_organization_id uuid,
  p_company_id uuid,
  p_document_type text,
  p_lock_token uuid
)
returns table (
  acquired boolean,
  last_nsu text,
  max_nsu text,
  next_sync_at timestamptz
)
language plpgsql
security definer
set search_path = pg_catalog, public
as $$
declare
  cursor_row public.sync_cursors%rowtype;
begin
  if auth.uid() is null or not public.is_org_member(p_organization_id) then
    raise exception 'Not authorized';
  end if;

  if p_document_type not in ('nfe55', 'nfe55_hom') or not exists (
    select 1 from public.companies c
    where c.id = p_company_id and c.organization_id = p_organization_id
  ) then
    raise exception 'Invalid company or document type';
  end if;

  insert into public.sync_cursors (
    organization_id, company_id, document_type, last_nsu, lock_token, locked_until
  ) values (
    p_organization_id, p_company_id, p_document_type, '000000000000000',
    p_lock_token, now() + interval '10 minutes'
  )
  on conflict (company_id, document_type) do update
    set lock_token = excluded.lock_token,
        locked_until = excluded.locked_until
    where public.sync_cursors.lock_token is null
       or public.sync_cursors.locked_until < now()
  returning * into cursor_row;

  if found then
    return query select true, cursor_row.last_nsu, cursor_row.max_nsu, cursor_row.next_sync_at;
  else
    return query select false, null::text, null::text, null::timestamptz;
  end if;
end;
$$;

create or replace function public.finish_nfe_distribution_cursor(
  p_organization_id uuid,
  p_company_id uuid,
  p_document_type text,
  p_lock_token uuid,
  p_last_nsu text,
  p_max_nsu text,
  p_next_sync_at timestamptz
)
returns boolean
language plpgsql
security definer
set search_path = pg_catalog, public
as $$
declare
  updated_count integer;
begin
  if auth.uid() is null or not public.is_org_member(p_organization_id) then
    raise exception 'Not authorized';
  end if;

  update public.sync_cursors
  set last_nsu = p_last_nsu,
      max_nsu = p_max_nsu,
      next_sync_at = p_next_sync_at,
      last_run_at = now(),
      lock_token = null,
      locked_until = null
  where organization_id = p_organization_id
    and company_id = p_company_id
    and document_type = p_document_type
    and lock_token = p_lock_token;

  get diagnostics updated_count = row_count;
  return updated_count = 1;
end;
$$;

revoke all on function public.acquire_nfe_distribution_cursor(uuid, uuid, text, uuid) from public;
revoke all on function public.finish_nfe_distribution_cursor(uuid, uuid, text, uuid, text, text, timestamptz) from public;
grant execute on function public.acquire_nfe_distribution_cursor(uuid, uuid, text, uuid) to authenticated;
grant execute on function public.finish_nfe_distribution_cursor(uuid, uuid, text, uuid, text, text, timestamptz) to authenticated;
