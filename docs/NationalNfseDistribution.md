# Consultation sources

The application queries NF-e model 55 through NFeDistribuicaoDFe (SPED-NFe) and service invoices through the National NFS-e ADN Contributor API. `POST /api/nfe/sincronizar` accepts `document_type: nfe55`, `nfse`, or `all`; the default for existing API clients remains `nfe55`. The UI defaults to both sources. Each source has its own cursor/lease/cooldown per company and environment, and failure of one does not prevent the other from importing documents.

Production ADN: `https://adn.nfse.gov.br/contribuintes/DFe/{NSU}?cnpjConsulta={CNPJ}&lote=true`. Authentication uses A1 mTLS, with HTTPS peer/hostname validation enabled. Responses provide up to 50 compressed XMLs per page. The next request uses the largest successfully stored NSU. Each sync request imports at most two NFS-e pages; the existing session automation can resume pending pages.

For NF-e received by another application, `num_nsu` performs a single consNSU lookup and stores the document without advancing the sequential distribution cursor. SEFAZ cooldowns apply; a 656 response never causes immediate retries or cursor resets.

OpenSSL 3 does not read some valid PFX containers using RC2 by default. `CertificateService` uses the native reader first, then the OpenSSL legacy provider in a dedicated subprocess only for unsupported container algorithms. The original encrypted PFX is temporary with mode 0600; the password and decrypted keys use pipes/memory and are not recorded. Production includes the OpenSSL CLI and legacy provider. Wrong passwords produce a safe, actionable error.

NFS-e archive parsing checks safe/well-formed XML, namespace, declared version, key correspondence, company participation (issuer, recipient, intermediary), number, date and amount. It preserves original XML bytes. Historical municipal documents already distributed by ADN can differ from current **emission** XSDs (MEI status 107 in v1.00, RSA-SHA256, textual RPS series); therefore the archive reader does not apply current emission validation to reject them. Events are retained as sources, and only actual cancellation events change invoice status.

Apply `supabase/migrations/20261013000100_national_nfse_distribution.sql` before deploying. The migration expands allowed source/cursor types without changing existing notes or tenant policies. Use `NFE_TPAMB=1` in production for real invoices; the frontend build uses the same environment setting.

References reviewed for this implementation:

- Official Contributor API: https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/documentacao-atual/manual-contribuintes-apis-adn-sistema-nacional-nfse.pdf
- SPED-NFe DistDFe: https://github.com/nfephp-org/sped-nfe/blob/master/docs/metodos/DistDFe.md
- https://github.com/nfse-nacional/nfse-php — `baixarDfe`
- https://github.com/mendesalexandre/php-nfse-nacional — `sincronizarDfe`, pagination and XML parsing
- https://github.com/badbrans/brans-nfe — A1/mTLS and ADN DFe
- https://github.com/rumbleh/laravel-nfse — Laravel integration with National NFS-e

These NFS-e SDKs access the same ADN source; they are references, not independent duplicate providers or additional production dependencies. CT-e distribution in SPED-CTe is a separate document model and is not part of NF-e/NFS-e import.
