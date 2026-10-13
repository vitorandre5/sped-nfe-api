alter table public.companies
  add column certificate_expires_at timestamptz,
  add column certificate_uploaded_at timestamptz;

insert into storage.buckets (id, name, public, file_size_limit, allowed_mime_types)
values ('fiscal-certificates', 'fiscal-certificates', false, 16777216, array['application/octet-stream']);

create policy "members read their encrypted fiscal certificates" on storage.objects
  for select to authenticated
  using (bucket_id = 'fiscal-certificates' and public.is_org_member((storage.foldername(name))[1]::uuid));
create policy "members upload their encrypted fiscal certificates" on storage.objects
  for insert to authenticated
  with check (bucket_id = 'fiscal-certificates' and public.is_org_member((storage.foldername(name))[1]::uuid));
create policy "members update their encrypted fiscal certificates" on storage.objects
  for update to authenticated
  using (bucket_id = 'fiscal-certificates' and public.is_org_member((storage.foldername(name))[1]::uuid))
  with check (bucket_id = 'fiscal-certificates' and public.is_org_member((storage.foldername(name))[1]::uuid));
