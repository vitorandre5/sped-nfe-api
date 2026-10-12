<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Exceptions\FiscalException;

final class AccountPasswordService
{
    private string $baseUrl;
    private string $anonKey;
    private string $serviceRoleKey;

    public function __construct(string $baseUrl, string $anonKey, string $serviceRoleKey)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->anonKey = $anonKey;
        $this->serviceRoleKey = $serviceRoleKey;
    }

    public function changeForAuthenticatedUser(string $authorization, string $password): void
    {
        if (strlen($password) < 8 || $password === 'teste123') {
            throw new FiscalException('Escolha uma senha pessoal com pelo menos 8 caracteres.', 'PASSWORD_TOO_WEAK', [], 422);
        }
        if ($this->baseUrl === '' || $this->anonKey === '' || $this->serviceRoleKey === '') {
            throw new FiscalException('A troca segura de senha está indisponível. Configure o serviço de autenticação.', 'PASSWORD_CHANGE_UNAVAILABLE', [], 503);
        }
        if (!preg_match('/^Bearer\s+([A-Za-z0-9._~-]+)$/i', trim($authorization), $matches)) {
            throw new FiscalException('Sessão inválida. Entre novamente.', 'AUTH_REQUIRED', [], 401);
        }

        $token = $matches[1];
        $user = $this->request('GET', '/auth/v1/user', null, $this->anonKey, $token);
        $userId = is_array($user) ? (string) ($user['id'] ?? '') : '';
        if (!preg_match('/^[0-9a-f-]{36}$/i', $userId)) {
            throw new FiscalException('Sessão inválida. Entre novamente.', 'AUTH_REQUIRED', [], 401);
        }

        $adminUser = $this->request('GET', '/auth/v1/admin/users/' . rawurlencode($userId), null, $this->serviceRoleKey, $this->serviceRoleKey);
        $appMetadata = is_array($adminUser['app_metadata'] ?? null) ? $adminUser['app_metadata'] : [];
        $appMetadata['must_change_password'] = false;

        $this->request('PUT', '/auth/v1/admin/users/' . rawurlencode($userId), [
            'password' => $password,
            'app_metadata' => $appMetadata,
        ], $this->serviceRoleKey, $this->serviceRoleKey);
    }

    /** @param array<string, mixed>|null $body
     *  @return mixed
     */
    private function request(string $method, string $path, ?array $body, string $apiKey, string $bearer)
    {
        if (!function_exists('curl_init')) {
            throw new FiscalException('Serviço de autenticação indisponível.', 'AUTH_SERVICE_UNAVAILABLE', [], 503);
        }

        $handle = curl_init($this->baseUrl . $path);
        if ($handle === false) {
            throw new FiscalException('Serviço de autenticação indisponível.', 'AUTH_SERVICE_UNAVAILABLE', [], 503);
        }

        $headers = [
            'apikey: ' . $apiKey,
            'Authorization: Bearer ' . $bearer,
            'Accept: application/json',
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if (!is_string($response)) {
            throw new FiscalException('Não foi possível acessar o serviço de autenticação.', 'AUTH_SERVICE_UNAVAILABLE', [], 502);
        }
        if ($status < 200 || $status >= 300) {
            $isUserLookup = $path === '/auth/v1/user';
            throw new FiscalException(
                $isUserLookup ? 'Sessão inválida. Entre novamente.' : 'Não foi possível atualizar a senha com segurança.',
                $isUserLookup ? 'AUTH_REQUIRED' : 'PASSWORD_CHANGE_FAILED',
                [],
                $isUserLookup ? 401 : 502
            );
        }

        $decoded = json_decode($response, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }
}
