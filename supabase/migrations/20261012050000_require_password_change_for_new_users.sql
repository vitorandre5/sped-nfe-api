-- Marca contas criadas por qualquer caminho do Supabase Auth (incluindo Studio)
-- para troca obrigatória antes de a aplicação carregar os dados da organização.
create or replace function public.mark_new_users_for_password_change()
returns trigger
language plpgsql
security definer
set search_path = pg_catalog
as $$
begin
  new.raw_app_meta_data := coalesce(new.raw_app_meta_data, '{}'::jsonb)
    || jsonb_build_object('must_change_password', true);
  return new;
end;
$$;

revoke all on function public.mark_new_users_for_password_change() from public, anon, authenticated;

drop trigger if exists require_password_change_for_new_users on auth.users;
create trigger require_password_change_for_new_users
before insert on auth.users
for each row execute function public.mark_new_users_for_password_change();

-- A conta solicitada passa a ser forçada a definir senha pessoal no próximo acesso.
update auth.users
set raw_app_meta_data = coalesce(raw_app_meta_data, '{}'::jsonb)
  || jsonb_build_object('must_change_password', true)
where lower(email) = 'teste@example.com';
