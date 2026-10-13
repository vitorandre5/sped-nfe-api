alter table public.companies
  add column if not exists certificate_expires_at timestamptz,
  add column if not exists certificate_uploaded_at timestamptz;

insert into storage.buckets (id, name, public, file_size_limit, allowed_mime_types)
values ('fiscal-certificates', 'fiscal-certificates', false, 16777216, array['application/octet-stream'])
on conflict (id) do update set
  name = excluded.name,
  public = false,
  file_size_limit = excluded.file_size_limit,
  allowed_mime_types = excluded.allowed_mime_types;

do $$
begin
  if not exists (select 1 from pg_policies where schemaname = 'storage' and tablename = 'objects' and policyname = 'members read their encrypted fiscal certificates') then
    create policy "members read their encrypted fiscal certificates" on storage.objects
      for select to authenticated
      using (bucket_id = 'fiscal-certificates' and public.is_org_member((storage.foldername(name))[1]::uuid));
  end if;
  if not exists (select 1 from pg_policies where schemaname = 'storage' and tablename = 'objects' and policyname = 'members upload their encrypted fiscal certificates') then
    create policy "members upload their encrypted fiscal certificates" on storage.objects
      for insert to authenticated
      with check (bucket_id = 'fiscal-certificates' and public.is_org_member((storage.foldername(name))[1]::uuid));
  end if;
  if not exists (select 1 from pg_policies where schemaname = 'storage' and tablename = 'objects' and policyname = 'members update their encrypted fiscal certificates') then
    create policy "members update their encrypted fiscal certificates" on storage.objects
      for update to authenticated
      using (bucket_id = 'fiscal-certificates' and public.is_org_member((storage.foldername(name))[1]::uuid))
      with check (bucket_id = 'fiscal-certificates' and public.is_org_member((storage.foldername(name))[1]::uuid));
  end if;
end
$$;
