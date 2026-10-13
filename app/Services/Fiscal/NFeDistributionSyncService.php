<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\DTO\NFeRequestDTO;
use App\Exceptions\FiscalException;
use Throwable;

final class NFeDistributionSyncService
{
    private const SEFAZ_COOLDOWN_SECONDS = 3900;

    private NFeService $nfeService;

    private FiscalDocumentXmlParser $parser;

    private CompanyCertificateVault $certificateVault;

    public function __construct(NFeService $nfeService, FiscalDocumentXmlParser $parser, CompanyCertificateVault $certificateVault)
    {
        $this->nfeService = $nfeService;
        $this->parser = $parser;
        $this->certificateVault = $certificateVault;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function synchronize(array $payload, string $authorization, bool $scheduled = false): array
    {
        $sourceType = (string) ($payload['document_type'] ?? 'nfe55');
        if ($sourceType === 'all') {
            $results = [];
            $errors = [];
            foreach (['nfe55', 'nfse'] as $type) {
                try {
                    $results[$type] = $this->synchronize(array_replace($payload, ['document_type' => $type]), $authorization, $scheduled);
                } catch (FiscalException $exception) {
                    $errors[$type] = ['code' => $exception->errorCode(), 'message' => 'A busca continuará automaticamente quando estiver disponível.', 'details' => $exception->details(), 'http_status' => $exception->httpStatus()];
                }
            }
            if ($results === []) {
                $first = reset($errors);
                throw new FiscalException('A busca será retomada automaticamente quando estiver disponível.', $first['code'], $first['details'], $first['http_status']);
            }
            $nextDates = array_filter(array_column($results, 'next_sync_at'));
            foreach ($errors as $error) {
                if (!empty($error['details']['next_sync_at'])) {
                    $nextDates[] = $error['details']['next_sync_at'];
                }
            }
            sort($nextDates);
            return [
                'documentos_salvos' => array_sum(array_column($results, 'documentos_salvos')),
                'next_sync_at' => $nextDates[0] ?? null,
                'message' => 'A busca continua em segundo plano. Os documentos recebidos já estão disponíveis na lista.',
                'sources' => $results,
                'source_errors' => $errors,
            ];
        }
        if (!in_array($sourceType, ['nfe55', 'nfse'], true)) {
            throw new FiscalException('Tipo de documento fiscal inválido.', 'INVALID_DOCUMENT_TYPE', [], 422);
        }
        $isNfse = $sourceType === 'nfse';
        $specificNsu = isset($payload['num_nsu']) && (string) $payload['num_nsu'] !== '' ? $this->nsu((string) $payload['num_nsu']) : null;
        if ($isNfse && $specificNsu !== null) {
            throw new FiscalException('A consulta por NSU específico é exclusiva da NF-e.', 'INVALID_DOCUMENT_TYPE', [], 422);
        }
        $accessToken = $scheduled ? $this->serviceRoleKey() : $this->bearerToken($authorization);
        $supabaseUrl = trim((string) (getenv('SUPABASE_API_URL') ?: ''));
        $anonKey = trim((string) (getenv('SUPABASE_ANON_KEY') ?: ''));
        if ($supabaseUrl === '' || (!$scheduled && $anonKey === '')) {
            throw new FiscalException('O serviço fiscal não está conectado ao banco de dados.', 'SUPABASE_CONFIGURATION_REQUIRED', [], 503);
        }

        $client = new SupabaseApiClient($supabaseUrl, $scheduled ? $accessToken : $anonKey, $accessToken);
        $userId = $scheduled ? null : $client->authenticatedUserId();
        $organizationId = $this->uuid($payload['organization_id'] ?? null, 'Organização inválida.');
        $companyId = $this->uuid($payload['company_id'] ?? null, 'Empresa inválida.');
        $companyRows = $client->select('companies', [
            'id' => $companyId,
            'organization_id' => $organizationId,
        ], 'id,organization_id,cnpj,state,name,certificate_expires_at');
        if ($companyRows === []) {
            throw new FiscalException('A empresa não pertence à sua organização.', 'COMPANY_NOT_ACCESSIBLE', [], 403);
        }
        if (!$scheduled) {
            $membership = $client->select('organization_members', [
                'organization_id' => $organizationId,
                'user_id' => (string) $userId,
            ], 'organization_id,user_id');
            if ($membership === []) {
                throw new FiscalException('Você não tem acesso a esta organização.', 'ORGANIZATION_NOT_ACCESSIBLE', [], 403);
            }
        }

        $company = $companyRows[0];
        $cnpj = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) ($company['cnpj'] ?? '')) ?? '');
        if (!preg_match('/^[A-Z0-9]{12}[0-9]{2}$/', $cnpj)) {
            throw new FiscalException('O CNPJ cadastrado não está em um formato válido.', 'INVALID_COMPANY_CNPJ', [], 422);
        }
        $state = strtoupper((string) ($company['state'] ?? ''));
        if (!preg_match('/^[A-Z]{2}$/', $state)) {
            throw new FiscalException('A UF do estabelecimento não está configurada.', 'INVALID_COMPANY_STATE', [], 422);
        }

        $environment = (int) (getenv('NFE_TPAMB') ?: 2);
        if (!in_array($environment, [1, 2], true)) {
            throw new FiscalException('O ambiente fiscal não está configurado.', 'INVALID_FISCAL_ENVIRONMENT', [], 503);
        }
        $documentType = $environment === 1 ? $sourceType : $sourceType . '_hom';
        $lockToken = $this->newUuid();
        $cursorRows = $client->rpc('acquire_nfe_distribution_cursor', [
            'p_organization_id' => $organizationId,
            'p_company_id' => $companyId,
            'p_document_type' => $documentType,
            'p_lock_token' => $lockToken,
        ]);
        $cursor = is_array($cursorRows) ? ($cursorRows[0] ?? null) : null;
        if (!is_array($cursor) || empty($cursor['acquired'])) {
            throw new FiscalException('Já existe uma sincronização em andamento para este estabelecimento.', 'SYNC_ALREADY_RUNNING', [], 409);
        }

        $lastNsu = $this->nsu((string) ($cursor['last_nsu'] ?? '000000000000000'));
        $maxNsu = isset($cursor['max_nsu']) ? $this->nsu((string) $cursor['max_nsu']) : null;
        $nextSyncAt = isset($cursor['next_sync_at']) ? (string) $cursor['next_sync_at'] : null;
        $runId = null;
        $cursorSaved = false;
        $documentsSaved = 0;
        $batches = 0;
        $status = '138';
        $message = 'Consulta concluída.';
        $manifestationRequests = 0;
        $specificNsuQueries = 0;
        $sefazBlocked = false;

        try {
            if ($nextSyncAt !== null && strtotime($nextSyncAt) !== false && strtotime($nextSyncAt) > time()) {
                $this->finishCursor($client, $organizationId, $companyId, $documentType, $lockToken, $lastNsu, $maxNsu, $nextSyncAt);
                $cursorSaved = true;
                throw new FiscalException('A busca será retomada automaticamente quando estiver disponível.', 'SYNC_COOLDOWN', ['next_sync_at' => $nextSyncAt], 429);
            }

            $run = $client->insert('sync_runs', [
                'organization_id' => $organizationId,
                'company_id' => $companyId,
                'document_type' => $documentType,
                'status' => 'running',
                'cursor_before' => $lastNsu,
                'started_at' => gmdate(DATE_ATOM),
            ]);
            $runId = (string) ($run[0]['id'] ?? '');
            if ($runId === '') {
                throw new FiscalException('Não foi possível registrar o início da sincronização.', 'SYNC_RUN_CREATE_FAILED', [], 502);
            }

            $certificate = is_array($payload['certificado'] ?? null) ? $payload['certificado'] : [];
            $certificatePath = (string) ($certificate['path'] ?? '');
            $certificatePassword = (string) ($certificate['password'] ?? '');
            $storedCertificate = $certificatePath === '' || $certificatePassword === ''
                ? $this->certificateVault->load($client, $organizationId, $companyId)
                : null;
            if ($storedCertificate !== null) {
                $certificate = $storedCertificate;
            }

            $fiscalPayload = [
                'ambiente' => $environment,
                'emitente' => [
                    'cnpj' => $cnpj,
                    'endereco' => ['UF' => $state],
                ],
                'certificado' => [
                    'path' => $certificatePath !== '' ? $certificatePath : null,
                    'base64' => $certificate['base64'] ?? null,
                    'password' => $certificatePassword !== '' ? $certificatePassword : ($certificate['password'] ?? ''),
                ],
            ];
            $nextSyncAt = null;
            $maxBatches = $specificNsu !== null ? 1 : ($isNfse ? 2 : 6);

            if (!$isNfse) {
                $this->processRegisteredManifestations(
                    $client,
                    $organizationId,
                    $companyId,
                    $cnpj,
                    (string) ($company['name'] ?? ''),
                    $environment,
                    $documentType,
                    $fiscalPayload,
                    $manifestationRequests,
                    $specificNsuQueries,
                    $sefazBlocked
                );
            }
            if ($sefazBlocked) {
                $status = '656';
                $nextSyncAt = gmdate(DATE_ATOM, time() + self::SEFAZ_COOLDOWN_SECONDS);
            }

            for ($index = 0; !$sefazBlocked && $index < $maxBatches; $index++) {
                $previousNsu = $lastNsu;
                $savedBeforeBatch = $documentsSaved;
                try {
                    $result = $isNfse
                        ? (new NationalNfseService())->distribute($fiscalPayload, $lastNsu)
                        : $this->nfeService->distribuir(new NFeRequestDTO($fiscalPayload + ['ult_nsu' => $lastNsu, 'num_nsu' => $specificNsu ?? '0']));
                } catch (FiscalException $exception) {
                    throw $exception;
                } catch (Throwable $exception) {
                    throw new FiscalException('Não foi possível consultar a distribuição oficial da NF-e. Tente novamente mais tarde.', 'SEFAZ_REQUEST_FAILED', [], 502);
                }

                $status = (string) ($result['cStat'] ?? '');
                $batches++;
                if ($status === '656') {
                    $nextSyncAt = gmdate(DATE_ATOM, time() + self::SEFAZ_COOLDOWN_SECONDS);
                    $message = 'A busca continuará automaticamente quando estiver disponível.';
                    $providerNsu = isset($result['ult_nsu']) ? $this->nsu((string) $result['ult_nsu']) : $lastNsu;
                    if ($providerNsu > $lastNsu) {
                        $lastNsu = $providerNsu;
                    }
                    break;
                }
                if ($status === '137') {
                    $returnedNsu = $this->nsu((string) ($result['ult_nsu'] ?? $lastNsu));
                    $lastNsu = max($lastNsu, $returnedNsu);
                    $nextSyncAt = gmdate(DATE_ATOM, time() + self::SEFAZ_COOLDOWN_SECONDS);
                    $message = 'A busca foi atualizada. Novos documentos aparecerão automaticamente quando estiverem disponíveis.';
                    break;
                }
                if ($status !== '138') {
                    throw new FiscalException('A SEFAZ não concluiu a consulta (cStat ' . $status . ').', 'SEFAZ_DISTRIBUTION_REJECTED', ['cStat' => $status !== '' ? $status : null], 502);
                }

                foreach (($result['documentos'] ?? []) as $source) {
                    if (!is_array($source) || !is_string($source['xml'] ?? null)) {
                        throw new FiscalException('A SEFAZ retornou um documento que não pôde ser processado.', 'DISTRIBUTED_XML_INVALID', [], 502);
                    }
                    $documentsSaved += $this->storeSource(
                        $client,
                        $source,
                        $organizationId,
                        $companyId,
                        $cnpj,
                        (string) ($company['name'] ?? ''),
                        $environment,
                        $sourceType,
                        $fiscalPayload,
                        $manifestationRequests,
                        $specificNsuQueries,
                        $sefazBlocked
                    );
                }

                if ($sefazBlocked && $specificNsu !== null) {
                    $status = '656';
                    $nextSyncAt = gmdate(DATE_ATOM, time() + self::SEFAZ_COOLDOWN_SECONDS);
                    $message = 'A busca continuará automaticamente quando estiver disponível.';
                    break;
                }

                if ($specificNsu !== null) {
                    $nextSyncAt = gmdate(DATE_ATOM, time() + 180);
                    $message = 'Documento consultado pelo NSU informado. O cursor de distribuição foi preservado.';
                    break;
                }

                $returnedNsu = $this->nsu((string) ($result['ult_nsu'] ?? $lastNsu));
                $returnedMax = $isNfse && !empty($result['has_more'])
                    ? null
                    : $this->nsu((string) ($result['max_nsu'] ?? $maxNsu ?? $returnedNsu));
                $lastNsu = max($lastNsu, $returnedNsu);
                $maxNsu = $returnedMax === null ? null : max($maxNsu ?? $returnedMax, $returnedMax);

                if ($sefazBlocked) {
                    $status = '656';
                    $nextSyncAt = gmdate(DATE_ATOM, time() + self::SEFAZ_COOLDOWN_SECONDS);
                    $message = 'A busca continuará automaticamente quando estiver disponível.';
                    break;
                }

                if ($maxNsu !== null && $lastNsu >= $maxNsu) {
                    $nextSyncAt = gmdate(DATE_ATOM, time() + self::SEFAZ_COOLDOWN_SECONDS);
                    $message = 'A busca foi atualizada. Novos documentos aparecerão automaticamente quando estiverem disponíveis.';
                    break;
                }
                if ($lastNsu === $previousNsu && $documentsSaved === $savedBeforeBatch) {
                    $nextSyncAt = gmdate(DATE_ATOM, time() + 60);
                    $message = 'A busca continuará automaticamente quando estiver disponível.';
                    break;
                }
                if ($index + 1 < $maxBatches) {
                    sleep(2);
                } else {
                    $nextSyncAt = gmdate(DATE_ATOM, time() + 2);
                    $message = 'A busca continua em segundo plano. Os documentos recebidos já estão disponíveis na lista.';
                }
            }

            if ($status === '656') {
                $maxNsu = isset($cursor['max_nsu']) ? $this->nsu((string) $cursor['max_nsu']) : $maxNsu;
            }

            $this->finishCursor($client, $organizationId, $companyId, $documentType, $lockToken, $lastNsu, $maxNsu, $nextSyncAt);
            $cursorSaved = true;

            if ($runId !== null) {
                $client->requestRunUpdate($runId, [
                    'status' => 'completed',
                    'cursor_after' => $lastNsu,
                    'document_count' => $documentsSaved,
                    'cstat' => $status,
                    'message' => $message,
                    'finished_at' => gmdate(DATE_ATOM),
                ]);
            }
            $client->updateCompany($companyId, ['last_synced_at' => gmdate(DATE_ATOM)]);

            return [
                'cStat' => $status,
                'ult_nsu' => $lastNsu,
                'max_nsu' => $maxNsu,
                'documentos_salvos' => $documentsSaved,
                'lotes_consultados' => $batches,
                'next_sync_at' => $nextSyncAt,
                'message' => $message,
            ];
        } catch (FiscalException $exception) {
            if ($runId !== null) {
                $this->markRunFailed($client, $runId, $exception->errorCode());
            }
            if (!$cursorSaved) {
                $this->finishCursorQuietly($client, $organizationId, $companyId, $documentType, $lockToken, $lastNsu, $maxNsu, $nextSyncAt);
            }
            throw $exception;
        } catch (Throwable $exception) {
            if ($runId !== null) {
                $this->markRunFailed($client, $runId, 'SYNC_FAILED');
            }
            if (!$cursorSaved) {
                $this->finishCursorQuietly($client, $organizationId, $companyId, $documentType, $lockToken, $lastNsu, $maxNsu, $nextSyncAt);
            }
            throw new FiscalException('Não foi possível concluir a sincronização. Nenhuma credencial foi armazenada.', 'SYNC_FAILED', [], 502);
        }
    }

    /** Run due company/document pairs without relying on an open browser session. */
    public function runScheduledBatch(int $limit = 10): array
    {
        $serviceRoleKey = $this->serviceRoleKey();
        $supabaseUrl = trim((string) (getenv('SUPABASE_API_URL') ?: ''));
        if ($supabaseUrl === '') {
            throw new FiscalException('O serviço fiscal não está conectado ao banco de dados.', 'SUPABASE_CONFIGURATION_REQUIRED', [], 503);
        }

        $client = new SupabaseApiClient($supabaseUrl, $serviceRoleKey, $serviceRoleKey);
        $companies = $client->selectFiltered('companies', [
            'certificate_uploaded_at' => 'not.is.null',
            'certificate_expires_at' => 'gt.' . gmdate(DATE_ATOM),
        ], 'id,organization_id', 1000);
        $environment = (int) (getenv('NFE_TPAMB') ?: 2);
        $processed = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($companies as $company) {
            foreach (['nfe55', 'nfse'] as $sourceType) {
                if ($processed + $failed >= max(1, min($limit, 50))) {
                    break 2;
                }

                $documentType = $environment === 1 ? $sourceType : $sourceType . '_hom';
                $cursors = $client->select('sync_cursors', [
                    'company_id' => (string) ($company['id'] ?? ''),
                    'document_type' => $documentType,
                ], 'next_sync_at,locked_until');
                $cursor = $cursors[0] ?? null;
                if (is_array($cursor)) {
                    $nextSyncAt = strtotime((string) ($cursor['next_sync_at'] ?? ''));
                    $lockedUntil = strtotime((string) ($cursor['locked_until'] ?? ''));
                    if (($nextSyncAt !== false && $nextSyncAt > time()) || ($lockedUntil !== false && $lockedUntil > time())) {
                        $skipped++;
                        continue;
                    }
                }

                try {
                    $this->synchronize([
                        'organization_id' => (string) $company['organization_id'],
                        'company_id' => (string) $company['id'],
                        'document_type' => $sourceType,
                    ], 'Bearer ' . $serviceRoleKey, true);
                    $processed++;
                } catch (Throwable $exception) {
                    $failed++;
                }
            }
        }

        return ['processed' => $processed, 'skipped' => $skipped, 'failed' => $failed];
    }

    /** @param array<string, mixed> $source */
    private function storeSource(
        SupabaseApiClient $client,
        array $source,
        string $organizationId,
        string $companyId,
        string $cnpj,
        string $companyName,
        int $environment,
        string $documentType,
        array $fiscalPayload,
        int &$manifestationRequests,
        int &$specificNsuQueries,
        bool &$sefazBlocked,
        bool $allowAutomaticManifestation = true
    ): int {
        $nsu = $this->nsu((string) ($source['nsu'] ?? ''));
        $schema = (string) ($source['schema'] ?? '');
        $xml = (string) $source['xml'];
        $parsed = $documentType === 'nfse'
            ? (new NfseXmlParser())->parse($source, $cnpj, $environment)
            : $this->parser->parse($xml, $schema, $cnpj, $companyName);
        $digest = hash('sha256', $xml);
        $path = sprintf('%s/%s/%s/%d/%s-%s.xml', $organizationId, $companyId, $documentType === 'nfse' ? 'nfse' : '55', $environment, $nsu, $digest);
        $client->uploadXml($path, $xml);

        $documentId = null;
        $isInvoice = !empty($parsed['is_invoice']);
        $isIncomingInvoice = $isInvoice && !empty($parsed['is_purchase']);
        $isNewDocument = false;
        if ($isIncomingInvoice) {
            $existing = $client->select('fiscal_documents', [
                'organization_id' => $organizationId,
                'access_key' => $parsed['access_key'],
            ], 'id,company_id,is_purchase,is_sale,source_company_ids,operation_categories,cfops');
            $previous = $existing[0] ?? [];
            $isNewDocument = $previous === [];
            $sourceCompanyIds = array_values(array_unique(array_merge(
                is_array($previous['source_company_ids'] ?? null) ? $previous['source_company_ids'] : [],
                [$companyId]
            )));
            $operationCategories = array_values(array_unique(array_merge(
                is_array($previous['operation_categories'] ?? null) ? $previous['operation_categories'] : [],
                is_array($parsed['operation_categories'] ?? null) ? $parsed['operation_categories'] : []
            )));
            $cfops = array_values(array_unique(array_merge(
                is_array($previous['cfops'] ?? null) ? $previous['cfops'] : [],
                is_array($parsed['cfops'] ?? null) ? $parsed['cfops'] : []
            )));
            $document = [
                'organization_id' => $organizationId,
                'company_id' => (string) ($previous['company_id'] ?? $companyId),
                'document_type' => $documentType,
                'external_id' => $parsed['access_key'],
                'access_key' => $parsed['access_key'],
                'nsu' => $nsu,
                'issuer_name' => $parsed['issuer_name'],
                'issuer_cnpj' => $parsed['issuer_cnpj'],
                'recipient_name' => $parsed['recipient_name'],
                'recipient_cnpj' => $parsed['recipient_cnpj'],
                'document_number' => $parsed['document_number'],
                'series' => $parsed['series'],
                'issued_at' => $parsed['issued_at'],
                'total_amount' => $parsed['total_amount'],
                'municipal_tax' => $parsed['municipal_tax'] ?? null,
                'situation' => $parsed['situation'],
                'storage_path' => $path,
                'xml_sha256' => $digest,
                'is_purchase' => true,
                'is_sale' => !empty($parsed['is_sale']) || !empty($previous['is_sale']),
                'source_company_ids' => $sourceCompanyIds,
                'operation_categories' => $operationCategories,
                'cfops' => $cfops,
                'operation_nature' => $parsed['operation_nature'],
            ];
            $saved = $client->upsert('fiscal_documents', $document, 'organization_id,access_key');
            $documentId = isset($saved[0]['id']) ? (string) $saved[0]['id'] : (string) ($previous['id'] ?? '');
            if ($documentId === '') {
                throw new FiscalException('Não foi possível vincular o XML à nota fiscal.', 'DOCUMENT_IMPORT_FAILED', [], 502);
            }
        } elseif (!$isInvoice) {
            $existing = $client->select('fiscal_documents', [
                'organization_id' => $organizationId,
                'access_key' => $parsed['access_key'],
            ], 'id,situation,source_company_ids');
            $documentId = isset($existing[0]['id']) ? (string) $existing[0]['id'] : null;
            $sourceCompanyIds = array_values(array_unique(array_merge(
                is_array($existing[0]['source_company_ids'] ?? null) ? $existing[0]['source_company_ids'] : [],
                [$companyId]
            )));
            if ($documentId !== null && $this->isCancellation($parsed)) {
                $client->updateDocument($documentId, [
                    'situation' => $parsed['event_description'] ?: 'Cancelamento registrado',
                ]);
            }
        }

        $client->upsert('fiscal_document_sources', [
            'organization_id' => $organizationId,
            'company_id' => $companyId,
            'fiscal_document_id' => $documentId,
            'source_type' => $documentType === 'nfse' ? 'nfse_national' : 'nfe_distribution',
            'environment' => $environment,
            'source_external_id' => $environment . ':' . $nsu,
            'access_key' => $parsed['access_key'],
            'nsu' => $nsu,
            'schema_name' => $schema,
            'raw_sha256' => $digest,
            'storage_path' => $path,
            'received_at' => gmdate(DATE_ATOM),
        ], 'company_id,source_type,source_external_id');

        if ($documentType === 'nfe55' && $isIncomingInvoice && $parsed['situation'] !== 'Resumo disponível') {
            $this->markManifestationDownloaded($client, $organizationId, $companyId, $environment, $parsed['access_key']);
        }

        if ($allowAutomaticManifestation
            && $documentType === 'nfe55'
            && $isIncomingInvoice
            && $parsed['situation'] === 'Resumo disponível') {
            $registered = $this->registerAutomaticKnowledge(
                $client,
                $organizationId,
                $companyId,
                $environment,
                $parsed['access_key'],
                $nsu,
                $fiscalPayload,
                $manifestationRequests,
                $sefazBlocked
            );
            if ($registered && $specificNsuQueries < 18 && !$sefazBlocked) {
                $this->downloadManifestedNfe(
                    $client,
                    $organizationId,
                    $companyId,
                    $cnpj,
                    $companyName,
                    $environment,
                    $documentType,
                    $parsed['access_key'],
                    $nsu,
                    $fiscalPayload,
                    $manifestationRequests,
                    $specificNsuQueries,
                    $sefazBlocked
                );
            }
        }

        if ($documentId !== null && empty($parsed['is_invoice'])) {
            $client->updateDocument($documentId, ['source_company_ids' => $sourceCompanyIds]);
        }

        return $isIncomingInvoice && $isNewDocument ? 1 : 0;
    }

    /** @param array<string, mixed> $fiscalPayload */
    private function processRegisteredManifestations(
        SupabaseApiClient $client,
        string $organizationId,
        string $companyId,
        string $cnpj,
        string $companyName,
        int $environment,
        string $documentType,
        array $fiscalPayload,
        int &$manifestationRequests,
        int &$specificNsuQueries,
        bool &$sefazBlocked
    ): void {
        $summaries = $client->select('fiscal_documents', [
            'organization_id' => $organizationId,
            'company_id' => $companyId,
            'is_purchase' => 'true',
            'situation' => 'Resumo disponível',
        ], 'access_key,nsu');
        foreach ($summaries as $summary) {
            if ($manifestationRequests >= 18 || $sefazBlocked) {
                break;
            }
            $accessKey = (string) ($summary['access_key'] ?? '');
            if ($accessKey === '') {
                continue;
            }
            $this->registerAutomaticKnowledge(
                $client,
                $organizationId,
                $companyId,
                $environment,
                $accessKey,
                $this->nsu((string) ($summary['nsu'] ?? '')),
                $fiscalPayload,
                $manifestationRequests,
                $sefazBlocked
            );
        }

        $pending = $client->select('nfe_automatic_manifestations', [
            'organization_id' => $organizationId,
            'company_id' => $companyId,
            'environment' => $environment,
            'status' => 'pending',
        ], 'access_key,nsu');
        foreach ($pending as $row) {
            if ($manifestationRequests >= 18 || $sefazBlocked) {
                break;
            }
            $this->registerAutomaticKnowledge(
                $client,
                $organizationId,
                $companyId,
                $environment,
                (string) ($row['access_key'] ?? ''),
                $this->nsu((string) ($row['nsu'] ?? '')),
                $fiscalPayload,
                $manifestationRequests,
                $sefazBlocked
            );
        }

        $registered = $client->select('nfe_automatic_manifestations', [
            'organization_id' => $organizationId,
            'company_id' => $companyId,
            'environment' => $environment,
            'status' => 'registered',
        ], 'access_key,nsu,download_attempted_at');
        foreach ($registered as $row) {
            if ($specificNsuQueries >= 18 || $sefazBlocked) {
                break;
            }
            $lastAttempt = strtotime((string) ($row['download_attempted_at'] ?? ''));
            if ($lastAttempt !== false && $lastAttempt > time() - self::SEFAZ_COOLDOWN_SECONDS) {
                continue;
            }
            $this->downloadManifestedNfe(
                $client,
                $organizationId,
                $companyId,
                $cnpj,
                $companyName,
                $environment,
                $documentType,
                (string) ($row['access_key'] ?? ''),
                $this->nsu((string) ($row['nsu'] ?? '')),
                $fiscalPayload,
                $manifestationRequests,
                $specificNsuQueries,
                $sefazBlocked
            );
        }
    }

    /** @param array<string, mixed> $fiscalPayload */
    private function registerAutomaticKnowledge(
        SupabaseApiClient $client,
        string $organizationId,
        string $companyId,
        int $environment,
        string $accessKey,
        string $nsu,
        array $fiscalPayload,
        int &$manifestationRequests,
        bool &$sefazBlocked
    ): bool {
        $rows = $client->select('nfe_automatic_manifestations', [
            'organization_id' => $organizationId,
            'company_id' => $companyId,
            'environment' => $environment,
            'access_key' => $accessKey,
        ], 'status,attempt_count,event_attempted_at,download_attempted_at');
        $existing = $rows[0] ?? [];
        if (in_array($existing['status'] ?? null, ['downloaded', 'rejected'], true)) {
            return false;
        }
        if (($existing['status'] ?? null) === 'registered') {
            $lastDownloadAttempt = strtotime((string) ($existing['download_attempted_at'] ?? ''));
            return $lastDownloadAttempt === false || $lastDownloadAttempt <= time() - self::SEFAZ_COOLDOWN_SECONDS;
        }

        $now = gmdate(DATE_ATOM);
        if ($existing === []) {
            $client->upsert('nfe_automatic_manifestations', [
                'organization_id' => $organizationId,
                'company_id' => $companyId,
                'environment' => $environment,
                'access_key' => $accessKey,
                'nsu' => $nsu,
                'status' => 'pending',
                'attempt_count' => 0,
                'updated_at' => $now,
            ], 'company_id,environment,access_key');
        }

        $lastAttempt = strtotime((string) ($existing['event_attempted_at'] ?? ''));
        if (($lastAttempt !== false && $lastAttempt > time() - self::SEFAZ_COOLDOWN_SECONDS) || $manifestationRequests >= 18) {
            return false;
        }

        $attemptCount = (int) ($existing['attempt_count'] ?? 0) + 1;
        $client->upsert('nfe_automatic_manifestations', [
            'organization_id' => $organizationId,
            'company_id' => $companyId,
            'environment' => $environment,
            'access_key' => $accessKey,
            'nsu' => $nsu,
            'status' => 'pending',
            'attempt_count' => $attemptCount,
            'event_attempted_at' => $now,
            'cstat' => null,
            'protocol' => null,
            'updated_at' => $now,
        ], 'company_id,environment,access_key');

        try {
            $manifestationRequests++;
            $result = $this->nfeService->manifestarCiencia(
                $accessKey,
                new NFeRequestDTO($fiscalPayload)
            );
            $cstats = $this->responseValues($result['response'] ?? [], 'cStat');
            if (in_array('656', $cstats, true)) {
                $sefazBlocked = true;
                $eventStatus = 'pending';
            } else {
                $accepted = array_intersect($cstats, ['135', '136', '573']) !== [];
                $eventStatus = $accepted ? 'registered' : ($cstats === [] ? 'pending' : 'rejected');
            }
            $client->upsert('nfe_automatic_manifestations', [
                'organization_id' => $organizationId,
                'company_id' => $companyId,
                'environment' => $environment,
                'access_key' => $accessKey,
                'nsu' => $nsu,
                'status' => $eventStatus,
                'attempt_count' => $attemptCount,
                'event_attempted_at' => $now,
                'cstat' => array_values(array_intersect($cstats, ['135', '136', '573']))[0] ?? ($cstats[0] ?? null),
                'protocol' => $this->responseValues($result['response'] ?? [], 'nProt')[0] ?? null,
                'updated_at' => gmdate(DATE_ATOM),
            ], 'company_id,environment,access_key');
            return $accepted;
        } catch (Throwable $exception) {
            // Keep the request queued; the next synchronization retries after the safe interval.
            return false;
        }
    }

    /** @param array<string, mixed> $fiscalPayload */
    private function downloadManifestedNfe(
        SupabaseApiClient $client,
        string $organizationId,
        string $companyId,
        string $cnpj,
        string $companyName,
        int $environment,
        string $documentType,
        string $accessKey,
        string $nsu,
        array $fiscalPayload,
        int &$manifestationRequests,
        int &$specificNsuQueries,
        bool &$sefazBlocked
    ): void {
        $now = gmdate(DATE_ATOM);
        $client->upsert('nfe_automatic_manifestations', [
            'organization_id' => $organizationId,
            'company_id' => $companyId,
            'environment' => $environment,
            'access_key' => $accessKey,
            'nsu' => $nsu,
            'status' => 'registered',
            'download_attempted_at' => $now,
            'updated_at' => $now,
        ], 'company_id,environment,access_key');
        $specificNsuQueries++;

        try {
            $result = $this->nfeService->distribuir(new NFeRequestDTO($fiscalPayload + [
                'ult_nsu' => '0',
                'num_nsu' => $nsu,
            ]));
            if ((string) ($result['cStat'] ?? '') === '656') {
                $sefazBlocked = true;
                return;
            }
            if ((string) ($result['cStat'] ?? '') !== '138') {
                return;
            }

            foreach (($result['documentos'] ?? []) as $source) {
                if (!is_array($source) || !is_string($source['xml'] ?? null)) {
                    continue;
                }
                $this->storeSource(
                    $client,
                    $source,
                    $organizationId,
                    $companyId,
                    $cnpj,
                    $companyName,
                    $environment,
                    $documentType,
                    $fiscalPayload,
                    $manifestationRequests,
                    $specificNsuQueries,
                    $sefazBlocked,
                    false
                );
            }
        } catch (Throwable $exception) {
            // Leave the accepted event queued for the next safe NSU consultation.
        }
    }

    private function markManifestationDownloaded(
        SupabaseApiClient $client,
        string $organizationId,
        string $companyId,
        int $environment,
        string $accessKey
    ): void {
        $rows = $client->select('nfe_automatic_manifestations', [
            'organization_id' => $organizationId,
            'company_id' => $companyId,
            'environment' => $environment,
            'access_key' => $accessKey,
        ], 'nsu,attempt_count,event_attempted_at,download_attempted_at');
        if ($rows === []) {
            return;
        }
        $row = $rows[0];
        $client->upsert('nfe_automatic_manifestations', [
            'organization_id' => $organizationId,
            'company_id' => $companyId,
            'environment' => $environment,
            'access_key' => $accessKey,
            'nsu' => (string) $row['nsu'],
            'status' => 'downloaded',
            'attempt_count' => (int) ($row['attempt_count'] ?? 0),
            'event_attempted_at' => $row['event_attempted_at'] ?? null,
            'download_attempted_at' => $row['download_attempted_at'] ?? null,
            'updated_at' => gmdate(DATE_ATOM),
        ], 'company_id,environment,access_key');
    }

    /** @param array<string, mixed> $source @return list<string> */
    private function responseValues(array $source, string $key): array
    {
        $values = [];
        foreach ($source as $name => $value) {
            if ((string) $name === $key && (is_string($value) || is_numeric($value))) {
                $values[] = (string) $value;
            } elseif (is_array($value)) {
                $values = array_merge($values, $this->responseValues($value, $key));
            }
        }
        return $values;
    }

    private function isCancellation(array $parsed): bool
    {
        $eventType = (string) ($parsed['event_type'] ?? '');
        if ($eventType !== '' && !ctype_digit($eventType)) {
            return in_array($eventType, ['CANCELAMENTO', 'CANCELAMENTO_POR_SUBSTITUICAO', 'CANCELAMENTO_DEFERIDO_ANALISE_FISCAL', 'CANCELAMENTO_POR_OFICIO'], true);
        }
        return $eventType === '110111'
            || stripos((string) ($parsed['event_description'] ?? ''), 'cancelamento') !== false;
    }

    private function finishCursor(
        SupabaseApiClient $client,
        string $organizationId,
        string $companyId,
        string $documentType,
        string $lockToken,
        string $lastNsu,
        ?string $maxNsu,
        ?string $nextSyncAt
    ): void {
        $result = $client->rpc('finish_nfe_distribution_cursor', [
            'p_organization_id' => $organizationId,
            'p_company_id' => $companyId,
            'p_document_type' => $documentType,
            'p_lock_token' => $lockToken,
            'p_last_nsu' => $lastNsu,
            'p_max_nsu' => $maxNsu,
            'p_next_sync_at' => $nextSyncAt,
        ]);
        if ($result !== true) {
            throw new FiscalException('Não foi possível salvar o estado da sincronização.', 'SYNC_CURSOR_SAVE_FAILED', [], 502);
        }
    }

    private function finishCursorQuietly(
        SupabaseApiClient $client,
        string $organizationId,
        string $companyId,
        string $documentType,
        string $lockToken,
        string $lastNsu,
        ?string $maxNsu,
        ?string $nextSyncAt
    ): void {
        try {
            $this->finishCursor($client, $organizationId, $companyId, $documentType, $lockToken, $lastNsu, $maxNsu, $nextSyncAt);
        } catch (Throwable $exception) {
            // A lease expira automaticamente se o banco também estiver indisponível.
        }
    }

    private function markRunFailed(SupabaseApiClient $client, string $runId, string $safeCode): void
    {
        try {
            $client->requestRunUpdate($runId, [
                'status' => 'failed',
                'message' => $safeCode,
                'finished_at' => gmdate(DATE_ATOM),
            ]);
        } catch (Throwable $exception) {
            // Não registrar payload, certificado, senha ou resposta SOAP em logs.
        }
    }

    private function bearerToken(string $authorization): string
    {
        if (!preg_match('/^Bearer\s+([A-Za-z0-9._~-]+)$/i', trim($authorization), $matches)) {
            throw new FiscalException('Entre novamente para sincronizar documentos.', 'AUTH_REQUIRED', [], 401);
        }

        return $matches[1];
    }

    private function serviceRoleKey(): string
    {
        $key = trim((string) (getenv('FISCAL_SUPABASE_SERVICE_ROLE_KEY') ?: ''));
        if ($key === '') {
            throw new FiscalException('A sincronização automática do servidor não está configurada.', 'SYNC_WORKER_CONFIGURATION_REQUIRED', [], 503);
        }
        return $key;
    }

    private function uuid($value, string $message): string
    {
        $uuid = strtolower((string) $value);
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid)) {
            throw new FiscalException($message, 'INVALID_IDENTIFIER', [], 422);
        }

        return $uuid;
    }

    private function nsu(string $value): string
    {
        if (!preg_match('/^\d{1,15}$/', $value)) {
            throw new FiscalException('A SEFAZ retornou um NSU inválido.', 'INVALID_NSU', [], 502);
        }

        return str_pad($value, 15, '0', STR_PAD_LEFT);
    }

    private function newUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }
}
