-- Keep all application RPCs behind the authenticated role. This is
-- intentionally repeated as a forward migration for existing databases.
revoke all on function public.is_org_member(uuid) from public, anon;
grant execute on function public.is_org_member(uuid) to authenticated;

revoke all on function public.create_organization(text) from public, anon;
grant execute on function public.create_organization(text) to authenticated;

revoke all on function public.acquire_nfe_distribution_cursor(uuid, uuid, text, uuid) from public, anon;
grant execute on function public.acquire_nfe_distribution_cursor(uuid, uuid, text, uuid) to authenticated, service_role;

revoke all on function public.finish_nfe_distribution_cursor(uuid, uuid, text, uuid, text, text, timestamptz) from public, anon;
grant execute on function public.finish_nfe_distribution_cursor(uuid, uuid, text, uuid, text, text, timestamptz) to authenticated, service_role;
