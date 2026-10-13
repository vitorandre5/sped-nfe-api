<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\DTO\NFeRequestDTO;
use App\Exceptions\FiscalException;
use Throwable;

final class NFeDistributionSyncService
{
    private NFeService $nfeService;

    private FiscalDocumentXmlParser $parser;

    public function __construct(NFeService $nfeService, FiscalDocumentXmlParser $parser)
    {
        $this->nfeService = $nfeService;
        $this->parser = $parser;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function synchronize(array $payload, string $authorization): array
    {
        $sourceType = (string) ($payload['document_type'] ?? 'nfe55');
        if ($sourceType === 'all') {
            $results = [];
            $errors = [];
            foreach (['nfe55', 'nfse'] as $type) {
                try {
                    $results[$type] = $this->synchronize(array_replace($payload, ['document_type' => $type]), $authorization);
                } catch (FiscalException $exception) {
                    $errors[$type] = ['code' => $exception->errorCode(), 'message' => $exception->getMessage(), 'details' => $exception->details(), 'http_status' => $exception->httpStatus()];
                }
            }
            if ($results === []) {
                $first = reset($errors);
                throw new FiscalException($first['message'], $first['code'], $first['details'], $first['http_status']);
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
                'message' => implode(' ', array_map(static fn (string $type, array $result): string => ($type === 'nfse' ? 'NFS-e: ' : 'NF-e: ') . $result['message'], array_keys($results), array_values($results)))
                    . ($errors !== [] ? ' ' . implode(' ', array_map(static fn (array $error): string => $error['message'], $errors)) : ''),
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
        $accessToken = $this->bearerToken($authorization);
        $supabaseUrl = trim((string) (getenv('SUPABASE_API_URL') ?: ''));
        $anonKey = trim((string) (getenv('SUPABASE_ANON_KEY') ?: ''));
        if ($supabaseUrl === '' || $anonKey === '') {
            throw new FiscalException('O serviço fiscal não está conectado ao banco de dados.', 'SUPABASE_CONFIGURATION_REQUIRED', [], 503);
        }

        $client = new SupabaseApiClient($supabaseUrl, $anonKey, $accessToken);
        $userId = $client->authenticatedUserId();
        $organizationId = $this->uuid($payload['organization_id'] ?? null, 'Organização inválida.');
        $companyId = $this->uuid($payload['company_id'] ?? null, 'Empresa inválida.');
        $companyRows = $client->select('companies', [
            'id' => $companyId,
            'organization_id' => $organizationId,
        ], 'id,organization_id,cnpj,state,name');
        if ($companyRows === []) {
            throw new FiscalException('A empresa não pertence à sua organização.', 'COMPANY_NOT_ACCESSIBLE', [], 403);
        }
        $membership = $client->select('organization_members', [
            'organization_id' => $organizationId,
            'user_id' => $userId,
        ], 'organization_id,user_id');
        if ($membership === []) {
            throw new FiscalException('Você não tem acesso a esta organização.', 'ORGANIZATION_NOT_ACCESSIBLE', [], 403);
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

        try {
            if ($nextSyncAt !== null && strtotime($nextSyncAt) !== false && strtotime($nextSyncAt) > time()) {
                $this->finishCursor($client, $organizationId, $companyId, $documentType, $lockToken, $lastNsu, $maxNsu, $nextSyncAt);
                $cursorSaved = true;
                throw new FiscalException(($isNfse ? 'O Ambiente Nacional da NFS-e' : 'A SEFAZ') . ' pediu que você aguarde antes de consultar este CNPJ novamente.', 'SYNC_COOLDOWN', ['next_sync_at' => $nextSyncAt], 429);
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
            if ($certificatePath === '' || $certificatePassword === '') {
                throw new FiscalException('Envie o certificado A1 e sua senha para iniciar a sincronização.', 'CERTIFICATE_REQUIRED', [], 422);
            }

            $fiscalPayload = [
                'ambiente' => $environment,
                'emitente' => [
                    'cnpj' => $cnpj,
                    'endereco' => ['UF' => $state],
                ],
                'certificado' => [
                    'path' => $certificatePath,
                    'password' => $certificatePassword,
                ],
            ];
            $nextSyncAt = null;
            $maxBatches = $specificNsu !== null ? 1 : ($isNfse ? 2 : 6);

            for ($index = 0; $index < $maxBatches; $index++) {
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
                    $nextSyncAt = gmdate(DATE_ATOM, time() + 3600);
                    $message = 'A SEFAZ bloqueou novas consultas por uma hora. Outro sistema pode estar consultando este CNPJ com um NSU diferente. Aguarde o horário indicado e confira o último NSU usado pelo outro sistema.';
                    $providerNsu = isset($result['ult_nsu']) ? $this->nsu((string) $result['ult_nsu']) : $lastNsu;
                    if ($providerNsu > $lastNsu) {
                        $lastNsu = $providerNsu;
                        $message .= ' O último NSU informado pela SEFAZ foi salvo para retomar a busca de novas notas. Documentos anteriores podem ser recuperados pelo NSU específico.';
                    }
                    break;
                }
                if ($status === '137') {
                    $returnedNsu = $this->nsu((string) ($result['ult_nsu'] ?? $lastNsu));
                    $lastNsu = max($lastNsu, $returnedNsu);
                    $nextSyncAt = gmdate(DATE_ATOM, time() + 3600);
                    $message = 'Não há novos documentos disponíveis. A próxima consulta poderá ser feita após o horário indicado.';
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
                        $environment,
                        $sourceType
                    );
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

                if ($maxNsu !== null && $lastNsu >= $maxNsu) {
                    $nextSyncAt = gmdate(DATE_ATOM, time() + 3600);
                    $message = 'Todos os documentos disponíveis foram sincronizados. A próxima consulta poderá ser feita após o horário indicado.';
                    break;
                }
                if ($lastNsu === $previousNsu && $documentsSaved === $savedBeforeBatch) {
                    $nextSyncAt = gmdate(DATE_ATOM, time() + 60);
                    $message = 'A distribuição não avançou o NSU. A consulta foi pausada para evitar novas chamadas sem progresso.';
                    break;
                }
                if ($index + 1 < $maxBatches) {
                    sleep(2);
                } else {
                    $nextSyncAt = gmdate(DATE_ATOM, time() + 2);
                    $message = 'A sincronização foi pausada no limite seguro de consultas. Ela poderá ser retomada após o horário indicado.';
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

    /** @param array<string, mixed> $source */
    private function storeSource(
        SupabaseApiClient $client,
        array $source,
        string $organizationId,
        string $companyId,
        string $cnpj,
        int $environment,
        string $documentType = 'nfe55'
    ): int {
        $nsu = $this->nsu((string) ($source['nsu'] ?? ''));
        $schema = (string) ($source['schema'] ?? '');
        $xml = (string) $source['xml'];
        $parsed = $documentType === 'nfse'
            ? (new NfseXmlParser())->parse($source, $cnpj, $environment)
            : $this->parser->parse($xml, $schema, $cnpj);
        $digest = hash('sha256', $xml);
        $path = sprintf('%s/%s/%s/%d/%s-%s.xml', $organizationId, $companyId, $documentType === 'nfse' ? 'nfse' : '55', $environment, $nsu, $digest);
        $client->uploadXml($path, $xml);

        $documentId = null;
        if (!empty($parsed['is_invoice'])) {
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
                'situation' => $parsed['situation'],
                'storage_path' => $path,
                'xml_sha256' => $digest,
                'is_purchase' => !empty($parsed['is_purchase']) || !empty($previous['is_purchase']),
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
        } else {
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

        if ($documentId !== null && empty($parsed['is_invoice'])) {
            $client->updateDocument($documentId, ['source_company_ids' => $sourceCompanyIds]);
        }

        return !empty($parsed['is_invoice']) && $isNewDocument ? 1 : 0;
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
