-- Preserve records and tenant isolation while enabling national NFS-e distribution.
alter table public.sync_cursors
  drop constraint sync_cursors_document_type_check,
  add constraint sync_cursors_document_type_check
    check (document_type in ('nfe55', 'nfe55_hom', 'cte57', 'nfse', 'nfse_hom'));
alter table public.sync_runs
  drop constraint sync_runs_document_type_check,
  add constraint sync_runs_document_type_check
    check (document_type in ('nfe55', 'nfe55_hom', 'cte57', 'nfse', 'nfse_hom'));
alter table public.fiscal_document_sources
  drop constraint fiscal_document_sources_source_type_check,
  add constraint fiscal_document_sources_source_type_check
    check (source_type in ('legacy', 'nfe_distribution', 'local_emission', 'nfse_national'));

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
  if auth.uid() is null or not public.is_org_member(p_organization_id) then
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
    set lock_token = excluded.lock_token, locked_until = excluded.locked_until
    where public.sync_cursors.lock_token is null or public.sync_cursors.locked_until < now()
  returning * into cursor_row;
  if found then
    return query select true, cursor_row.last_nsu, cursor_row.max_nsu, cursor_row.next_sync_at;
  else
    return query select false, null::text, null::text, null::timestamptz;
  end if;
end;
$$;
revoke all on function public.acquire_nfe_distribution_cursor(uuid, uuid, text, uuid) from public;
grant execute on function public.acquire_nfe_distribution_cursor(uuid, uuid, text, uuid) to authenticated;
