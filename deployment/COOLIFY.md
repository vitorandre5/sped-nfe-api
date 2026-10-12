# Aplicações no Coolify

> **Validação (2026-10-12):** o Service Supabase nativo `supabase-bd-native` está funcional; o domínio de produção e TLS estão no Kong nativo, e os dados de Auth, organizações, empresas e documentos foram migrados. A aplicação antiga `supabase-bd` está sem domínio, preservada para rollback até a confirmação final de exclusão no Coolify. O usuário existente está vinculado como `admin` à organização `Teste`. A correção de `fiscal.web` (retentativa, novo login e criação de organização quando necessário) foi publicada no commit `1c2b04f`; o deploy terminou com sucesso e a API ficou saudável. Sessões antigas precisam entrar novamente. O Redis nativo `redis-bd-native` continua privado e não é usado pela API fiscal.

Recursos esperados no projeto `Fiscal`, ambiente `production`:

| Recurso | Tipo no Coolify | Compose/configuração | Domínio | Função |
| --- | --- | --- | --- |
| `supabase-bd` | Application legado, sem domínio; exclusão pendente | `docker-compose.supabase.yml` | nenhum | Preservado para rollback até concluir a confirmação no Coolify |
| `supabase-bd-native` | Service Supabase nativo | Template Supabase do Coolify | `supabase-fiscal.oc2.freeddns.org` | Produção ativa; Auth e banco validados |
| `redis-bd-native` | Database Redis | imagem `redis:7.4-alpine`; recurso nativo do Coolify | nenhum; acesso privado | Cache/fila privado; ainda não é consumido pela API fiscal |
| `redis-bd` | Application (legado para rollback) | `docker-compose.redis.yml` | nenhum | Recurso anterior, preservado temporariamente |
| `fiscal.web` | Application | `docker-compose.fiscal.yml` | `fiscal.oc2.freeddns.org` | Interface web e API PHP |

## Comunicação e persistência

Na configuração legada em Compose, `supabase-bd`, `redis-bd` e `fiscal.web` compartilham a rede Docker externa `fiscal-private`. Ela permite que os serviços mantenham os endereços internos já usados (`db`, `api-gw`, `redis`) sem publicar portas de banco/cache. O gateway do Supabase e a interface web também se conectam à rede `coolify` para receber tráfego do proxy. O novo Database Redis está configurado como privado no Coolify; ainda não conecte a API a ele até confirmar que o recurso e a aplicação compartilham o destino/rede necessários.

Os volumes externos legados referenciam os volumes que já existem no servidor (`pb3gplmtorrozsuon9hnhj50_fiscal-postgres-data`, `pb3gplmtorrozsuon9hnhj50_fiscal-storage-data`, `pb3gplmtorrozsuon9hnhj50_db-config` e `pb3gplmtorrozsuon9hnhj50_redis-data`). Não troque esses nomes ao editar os Compose: isso faria os serviços iniciarem com volumes vazios. O Database Redis nativo criou volume próprio `redis-data-gzz3qkd6on1nsp6tokmsouc1`; ele é independente do volume legado.

As migrações são copiadas para a imagem de `fiscal-migrations`. Não monte `./supabase/migrations` a partir do host: no deploy atual do Coolify esse diretório gerado estava vazio e as migrações não rodaram.

## Variáveis por aplicação

No Coolify, mantenha manualmente apenas as variáveis listadas abaixo. Entradas associadas a serviços como `studio`, `api-gw` e `storage` são geradas pelo Compose; variáveis herdadas do Compose antigo podem continuar listadas, mas não têm efeito quando não são referenciadas pelo Compose atual.

- `supabase-bd`: segredos `SERVICE_PASSWORD_64_POSTGRES`, `SERVICE_PASSWORD_JWT`, `SERVICE_SUPABASEANON_JWT`, `SERVICE_SUPABASESERVICE_JWT`, `SERVICE_PASSWORD_64_DASHBOARD`, `SERVICE_BASE64_64_SECRETKEYBASE`, `SERVICE_HEX_32_PGMETACRYPTO`, `SERVICE_HEX_32_S3ACCESSID`, `SERVICE_PASSWORD_64_S3ACCESSSECRET`, `SERVICE_HEX_32_VAULTENCKEY` e `FISCAL_REALTIME_DB_ENC_KEY` (16 caracteres, obrigatório para AES-128 no Realtime). Preserve os valores atuais ao mudar o Compose desta aplicação.
- O Studio do Supabase é acessado em `https://supabase-fiscal.oc2.freeddns.org`; usuário `fiscaladmin` e senha na variável `SERVICE_PASSWORD_ADMIN` de `supabase-bd-native`.
- `SERVICE_HEX_32_REALTIMEKEY` é legado e não é usado pelo Compose atual. Ele permanece apenas para evitar uma exclusão permanente; a chave ativa é `FISCAL_REALTIME_DB_ENC_KEY`.
- `redis-bd-native`: use as credenciais geradas pelo Coolify e mantenha `Access: Private`; não publique a porta. Não há necessidade de copiar segredo Redis para `fiscal.web` enquanto a aplicação não o consome.
- `redis-bd` (legado): as variáveis do antigo Compose permanecem apenas para rollback; não exclua o recurso nem o volume antigo durante esta migração.
- `fiscal.web`: `SERVICE_PASSWORD_64_APIPROXY`, `NFE_TPAMB`, `NFE_RAZAO_SOCIAL`, `NFE_CNPJ`, `NFE_SIGLA_UF`, `NFE_SCHEMES`, `NFE_VERSAO`, `NFE_VERAPLIC`. Use uma shared variable de projeto chamada `FISCAL_SUPABASE_ANON_KEY` com a chave anon do Supabase e referencie-a em `fiscal.web` como `{{project.FISCAL_SUPABASE_ANON_KEY}}`. O modo `2` é homologação.

Os segredos ficam no Coolify e não devem ser copiados para `.env.example`. A URL e a chave anon do Supabase são embutidas no build da interface; ao alterá-las, reconstrua `fiscal.web`.

## Ordem de implantação

1. O Supabase nativo e o corte do domínio já foram validados; sessões antigas do app precisam autenticar novamente.
2. Recarregar `fiscal.web`, entrar novamente com a sessão atualizada e validar acesso à organização.
3. Concluir a confirmação final para excluir `supabase-bd` legado e renomear o Service nativo.

O Postgres preserva o banco existente. Antes de substituir os três containers de uma vez, confirme a saúde dos recursos novos e mantenha os volumes listados acima.
