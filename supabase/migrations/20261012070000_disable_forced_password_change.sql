-- Remove a exigência de alterar senha no primeiro login.
drop trigger if exists require_password_change_for_new_users on auth.users;
drop function if exists public.mark_new_users_for_password_change();

-- Limpa a marca adicionada pela migration anterior às contas existentes.
update auth.users
set raw_app_meta_data = coalesce(raw_app_meta_data, '{}'::jsonb) - 'must_change_password'
where coalesce(raw_app_meta_data, '{}'::jsonb) ? 'must_change_password';
