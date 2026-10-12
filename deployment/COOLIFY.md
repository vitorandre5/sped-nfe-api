# Aplicações no Coolify

As três aplicações devem ficar no projeto `Fiscal`, ambiente `production`:

| Aplicação | Compose | Domínio | Função |
| --- | --- | --- | --- |
| `supabase-bd` | `docker-compose.supabase.yml` | `supabase-fiscal.oc2.freeddns.org` | Banco e serviços internos do Supabase |
| `redis-bd` | `docker-compose.redis.yml` | nenhum | Cache/fila privado; ainda não é consumido pela API fiscal |
| `fiscal.web` | `docker-compose.fiscal.yml` | `fiscal.oc2.freeddns.org` | Interface web e API PHP |

## Comunicação e persistência

As três aplicações compartilham a rede Docker externa `fiscal-private`. Ela permite que os serviços mantenham os endereços internos já usados (`db`, `api-gw`, `redis`) sem publicar portas de banco/cache. O gateway do Supabase e a interface web também se conectam à rede `coolify` para receber tráfego do proxy.

Os volumes externos referenciam os volumes que já existem no servidor (`pb3gplmtorrozsuon9hnhj50_fiscal-postgres-data`, `pb3gplmtorrozsuon9hnhj50_fiscal-storage-data`, `pb3gplmtorrozsuon9hnhj50_db-config` e `pb3gplmtorrozsuon9hnhj50_redis-data`). Não troque esses nomes ao editar os Compose: isso faria os serviços iniciarem com volumes vazios.

As migrações são copiadas para a imagem de `fiscal-migrations`. Não monte `./supabase/migrations` a partir do host: no deploy atual do Coolify esse diretório gerado estava vazio e as migrações não rodaram.

## Variáveis por aplicação

- `supabase-bd`: segredos `SERVICE_PASSWORD_64_POSTGRES`, `SERVICE_PASSWORD_JWT`, `SERVICE_SUPABASEANON_JWT`, `SERVICE_SUPABASESERVICE_JWT`, `SERVICE_PASSWORD_64_DASHBOARD`, `SERVICE_BASE64_64_SECRETKEYBASE`, `SERVICE_HEX_32_PGMETACRYPTO`, `SERVICE_HEX_32_REALTIMEKEY`, `SERVICE_HEX_32_S3ACCESSID`, `SERVICE_PASSWORD_64_S3ACCESSSECRET` e `SERVICE_HEX_32_VAULTENCKEY`. Preserve os valores atuais ao mudar o Compose desta aplicação.
- `redis-bd`: sem variáveis; a senha não é necessária porque o Redis não publica portas e só participa da rede privada.
- `fiscal.web`: `SERVICE_PASSWORD_64_APIPROXY`, `NFE_TPAMB`, `NFE_RAZAO_SOCIAL`, `NFE_CNPJ`, `NFE_SIGLA_UF`, `NFE_SCHEMES`, `NFE_VERSAO`, `NFE_VERAPLIC`. Use uma shared variable de projeto chamada `FISCAL_SUPABASE_ANON_KEY` com a chave anon do Supabase e referencie-a em `fiscal.web` como `{{project.FISCAL_SUPABASE_ANON_KEY}}`. O modo `2` é homologação.

Os segredos ficam no Coolify e não devem ser copiados para `.env.example`. A URL e a chave anon do Supabase são embutidas no build da interface; ao alterá-las, reconstrua `fiscal.web`.

## Ordem de implantação

1. Garantir a rede externa `fiscal-private` no servidor Docker.
2. Implantar `supabase-bd`; confirmar banco saudável e migrations concluídas.
3. Implantar `redis-bd`.
4. Implantar `fiscal.web` e testar login e carregamento da organização.

O Postgres preserva o banco existente. Antes de substituir os três containers de uma vez, confirme a saúde dos recursos novos e mantenha os volumes listados acima.
