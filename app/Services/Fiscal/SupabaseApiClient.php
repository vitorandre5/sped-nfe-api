<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Exceptions\FiscalException;

final class SupabaseApiClient
{
    private string $baseUrl;

    private string $anonKey;

    private string $accessToken;

    public function __construct(string $baseUrl, string $anonKey, string $accessToken)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->anonKey = $anonKey;
        $this->accessToken = $accessToken;
    }

    public function authenticatedUserId(): string
    {
        $user = $this->request('GET', '/auth/v1/user');
        $id = is_array($user) ? (string) ($user['id'] ?? '') : '';
        if (!preg_match('/^[0-9a-f-]{36}$/i', $id)) {
            throw new FiscalException('Sessão inválida. Entre novamente.', 'AUTH_REQUIRED', [], 401);
        }

        return strtolower($id);
    }

    /**
     * @param array<string, string> $filters
     * @return list<array<string, mixed>>
     */
    public function select(string $table, array $filters, string $columns = '*'): array
    {
        $rawFilters = [];
        foreach ($filters as $column => $value) {
            $rawFilters[$column] = 'eq.' . $value;
        }

        return $this->selectFiltered($table, $rawFilters, $columns);
    }

    /**
     * @param array<string, string> $filters PostgREST filter expressions.
     * @return list<array<string, mixed>>
     */
    public function selectFiltered(string $table, array $filters, string $columns = '*', int $limit = 1000): array
    {
        $query = ['select' => $columns];
        foreach ($filters as $column => $value) {
            $query[$column] = $value;
        }
        $query['limit'] = (string) max(1, min($limit, 1000));
        $result = $this->request('GET', '/rest/v1/' . rawurlencode($table) . '?' . http_build_query($query));

        return is_array($result) ? array_values(array_filter($result, 'is_array')) : [];
    }

    /**
     * @param array<string, mixed> $values
     * @return list<array<string, mixed>>
     */
    public function insert(string $table, array $values): array
    {
        $result = $this->request('POST', '/rest/v1/' . rawurlencode($table), $values, [
            'Prefer: return=representation',
        ]);

        return is_array($result) ? array_values(array_filter($result, 'is_array')) : [];
    }

    /**
     * @param array<string, mixed> $values
     * @return list<array<string, mixed>>
     */
    public function upsert(string $table, array $values, string $conflictColumns): array
    {
        $result = $this->request(
            'POST',
            '/rest/v1/' . rawurlencode($table) . '?' . http_build_query(['on_conflict' => $conflictColumns]),
            $values,
            ['Prefer: resolution=merge-duplicates,return=representation']
        );

        return is_array($result) ? array_values(array_filter($result, 'is_array')) : [];
    }

    /** @param array<string, mixed> $values */
    public function updateDocument(string $id, array $values): void
    {
        $this->update('fiscal_documents', ['id' => $id], $values);
    }

    /** @param array<string, mixed> $values */
    public function requestRunUpdate(string $id, array $values): void
    {
        $this->update('sync_runs', ['id' => $id], $values);
    }

    /** @param array<string, mixed> $values */
    public function updateCompany(string $id, array $values): void
    {
        $this->update('companies', ['id' => $id], $values);
    }

    /**
     * @param array<string, string> $filters
     * @param array<string, mixed> $values
     */
    private function update(string $table, array $filters, array $values): void
    {
        $query = [];
        foreach ($filters as $column => $value) {
            $query[$column] = 'eq.' . $value;
        }
        $this->request('PATCH', '/rest/v1/' . rawurlencode($table) . '?' . http_build_query($query), $values, [
            'Prefer: return=minimal',
        ]);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return mixed
     */
    public function rpc(string $function, array $arguments)
    {
        return $this->request('POST', '/rest/v1/rpc/' . rawurlencode($function), $arguments);
    }

    public function uploadXml(string $path, string $xml): void
    {
        $segments = array_map('rawurlencode', explode('/', trim($path, '/')));
        $this->request(
            'POST',
            '/storage/v1/object/fiscal-xml/' . implode('/', $segments),
            $xml,
            ['Content-Type: application/xml', 'x-upsert: true'],
            false
        );
    }

    public function uploadCertificate(string $path, string $encryptedContent): void
    {
        $segments = array_map('rawurlencode', explode('/', trim($path, '/')));
        $this->request(
            'POST',
            '/storage/v1/object/fiscal-certificates/' . implode('/', $segments),
            $encryptedContent,
            ['Content-Type: application/octet-stream', 'x-upsert: true'],
            false
        );
    }

    public function downloadCertificate(string $path): string
    {
        $segments = array_map('rawurlencode', explode('/', trim($path, '/')));
        $content = $this->request(
            'GET',
            '/storage/v1/object/fiscal-certificates/' . implode('/', $segments),
            null,
            [],
            false
        );
        if (!is_string($content) || $content === '') {
            throw new FiscalException('Certificado salvo não encontrado. Envie o A1 novamente.', 'CERTIFICATE_STORAGE_NOT_FOUND', [], 422);
        }
        return $content;
    }

    /**
     * @param array<string, string> $extraHeaders
     * @return mixed
     */
    private function request(
        string $method,
        string $path,
        $body = null,
        array $extraHeaders = [],
        bool $jsonBody = true
    ) {
        if (!function_exists('curl_init')) {
            throw new FiscalException('Serviço de dados indisponível.', 'SUPABASE_CLIENT_UNAVAILABLE', [], 503);
        }

        $handle = curl_init($this->baseUrl . $path);
        if ($handle === false) {
            throw new FiscalException('Serviço de dados indisponível.', 'SUPABASE_CLIENT_UNAVAILABLE', [], 503);
        }

        $headers = [
            'apikey: ' . $this->anonKey,
            'Authorization: Bearer ' . $this->accessToken,
            'Accept: application/json',
        ];
        $headers = array_merge($headers, $extraHeaders);

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        if ($body !== null) {
            $encoded = $jsonBody ? json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $body;
            if (!is_string($encoded)) {
                curl_close($handle);
                throw new FiscalException('Não foi possível preparar os dados fiscais.', 'SUPABASE_REQUEST_INVALID', [], 500);
            }
            curl_setopt($handle, CURLOPT_POSTFIELDS, $encoded);
            if ($jsonBody) {
                $headers[] = 'Content-Type: application/json';
                curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
            }
        }

        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if (!is_string($response)) {
            throw new FiscalException('Não foi possível acessar o serviço de dados.', 'SUPABASE_REQUEST_FAILED', [], 502);
        }

        if ($status < 200 || $status >= 300) {
            $message = $path === '/auth/v1/user'
                ? 'Sessão inválida. Entre novamente.'
                : 'Não foi possível salvar os dados fiscais com segurança.';
            $code = $path === '/auth/v1/user' ? 'AUTH_REQUIRED' : 'SUPABASE_REQUEST_FAILED';
            throw new FiscalException($message, $code, [], $path === '/auth/v1/user' ? 401 : 502);
        }

        if ($response === '') {
            return null;
        }

        if (!$jsonBody) {
            return $response;
        }

        $decoded = json_decode($response, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }
}
