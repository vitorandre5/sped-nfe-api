-- PostgREST exposes graphql_public by default in the self-hosted stack.
-- Ensure the schema exists even when the base database image did not create it.
create schema if not exists graphql_public;

grant usage on schema graphql_public to anon, authenticated, service_role;
