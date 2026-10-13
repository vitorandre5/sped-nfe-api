create table if not exists public.nfe_automatic_manifestations (
  id uuid primary key default gen_random_uuid(),
  organization_id uuid not null,
  company_id uuid not null,
  environment smallint not null check (environment in (1, 2)),
  access_key text not null check (access_key ~ '^[A-Z0-9]{44}$'),
  nsu text not null check (nsu ~ '^\d{15}$'),
  status text not null default 'pending'
    check (status in ('pending', 'registered', 'downloaded', 'rejected')),
  attempt_count integer not null default 0 check (attempt_count >= 0),
  event_attempted_at timestamptz,
  download_attempted_at timestamptz,
  cstat text,
  protocol text,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  unique (company_id, environment, access_key),
  foreign key (company_id, organization_id)
    references public.companies(id, organization_id) on delete cascade
);

create index if not exists nfe_automatic_manifestations_ready_idx
  on public.nfe_automatic_manifestations (company_id, status, updated_at);

alter table public.nfe_automatic_manifestations enable row level security;

drop policy if exists "members read automatic NF-e manifestations" on public.nfe_automatic_manifestations;
create policy "members read automatic NF-e manifestations"
  on public.nfe_automatic_manifestations
  for select to authenticated
  using (public.is_org_member(organization_id));

drop policy if exists "members create automatic NF-e manifestations" on public.nfe_automatic_manifestations;
create policy "members create automatic NF-e manifestations"
  on public.nfe_automatic_manifestations
  for insert to authenticated
  with check (public.is_org_member(organization_id));

drop policy if exists "members update automatic NF-e manifestations" on public.nfe_automatic_manifestations;
create policy "members update automatic NF-e manifestations"
  on public.nfe_automatic_manifestations
  for update to authenticated
  using (public.is_org_member(organization_id))
  with check (public.is_org_member(organization_id));

-- The private server worker uses the Supabase service role. User initiated
-- calls still require an authenticated organization member.
create or replace function public.acquire_nfe_distribution_cursor(
  p_organization_id uuid,
  p_company_id uuid,
  p_document_type text,
  p_lock_token uuid
)
returns table (acquired boolean, last_nsu text, max_nsu text, next_sync_at timestamptz)
language plpgsql
security definer
set search_path = pg_catalog, public
as $$
declare
  cursor_row public.sync_cursors%rowtype;
begin
  if auth.role() is distinct from 'service_role'
     and (auth.uid() is null or not public.is_org_member(p_organization_id)) then
    raise exception 'Not authorized';
  end if;
  if p_document_type not in ('nfe55', 'nfe55_hom', 'nfse', 'nfse_hom') or not exists (
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
  if auth.role() is distinct from 'service_role'
     and (auth.uid() is null or not public.is_org_member(p_organization_id)) then
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
grant execute on function public.acquire_nfe_distribution_cursor(uuid, uuid, text, uuid) to authenticated, service_role;
grant execute on function public.finish_nfe_distribution_cursor(uuid, uuid, text, uuid, text, text, timestamptz) to authenticated, service_role;
