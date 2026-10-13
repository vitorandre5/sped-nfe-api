<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Exceptions\FiscalException;

final class CompanyCertificateVault
{
    private const MAX_CERTIFICATE_BYTES = 10 * 1024 * 1024;

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function save(array $payload, string $authorization): array
    {
        $client = $this->client($authorization);
        $userId = $client->authenticatedUserId();
        $organizationId = $this->uuid($payload['organization_id'] ?? null);
        $companyId = $this->uuid($payload['company_id'] ?? null);
        $companyRows = $client->select('companies', [
            'id' => $companyId,
            'organization_id' => $organizationId,
        ], 'id,organization_id,cnpj');
        $membership = $client->select('organization_members', [
            'organization_id' => $organizationId,
            'user_id' => $userId,
        ], 'organization_id,user_id');
        if ($companyRows === [] || $membership === []) {
            throw new FiscalException('A empresa não pertence à sua conta.', 'COMPANY_NOT_ACCESSIBLE', [], 403);
        }

        $certificateInput = is_array($payload['certificado'] ?? null) ? $payload['certificado'] : [];
        $path = (string) ($certificateInput['path'] ?? '');
        $password = (string) ($certificateInput['password'] ?? '');
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            throw new FiscalException('Selecione o arquivo do certificado A1.', 'CERTIFICATE_REQUIRED', [], 422);
        }
        $size = filesize($path);
        if ($size === false || $size < 1 || $size > self::MAX_CERTIFICATE_BYTES) {
            throw new FiscalException('O arquivo A1 deve ter até 10 MB.', 'CERTIFICATE_SIZE_INVALID', [], 422);
        }
        if ($password === '') {
            throw new FiscalException('Informe a senha do certificado A1.', 'CERTIFICATE_PASSWORD_REQUIRED', [], 422);
        }
        $content = file_get_contents($path);
        if (!is_string($content) || $content === '') {
            throw new FiscalException('Não foi possível ler o certificado A1.', 'CERTIFICATE_READ_FAILED', [], 422);
        }

        $certificate = (new CertificateService())->readPfx($content, $password);
        $companyCnpj = preg_replace('/\D/', '', (string) ($companyRows[0]['cnpj'] ?? '')) ?? '';
        if (substr(preg_replace('/\D/', '', $certificate->getCnpj()) ?? '', 0, 8) !== substr($companyCnpj, 0, 8)) {
            throw new FiscalException('O certificado A1 não pertence à raiz do CNPJ selecionado.', 'CERTIFICATE_COMPANY_MISMATCH', [], 422);
        }

        $expiresAt = $certificate->getValidTo()->format(DATE_ATOM);
        $envelope = $this->encrypt(json_encode([
            'pfx' => base64_encode($content),
            'password' => $password,
        ], JSON_THROW_ON_ERROR));
        $storagePath = $organizationId . '/' . $companyId . '/certificate.enc';
        $client->uploadCertificate($storagePath, $envelope);
        $client->updateCompany($companyId, [
            'certificate_expires_at' => $expiresAt,
            'certificate_uploaded_at' => gmdate(DATE_ATOM),
        ]);

        return [
            'expires_at' => $expiresAt,
            'expired' => $certificate->isExpired(),
        ];
    }

    /** @return array{base64:string,password:string} */
    public function load(SupabaseApiClient $client, string $organizationId, string $companyId): array
    {
        $path = $organizationId . '/' . $companyId . '/certificate.enc';
        $decoded = json_decode($this->decrypt($client->downloadCertificate($path)), true);
        if (!is_array($decoded) || !is_string($decoded['pfx'] ?? null) || !is_string($decoded['password'] ?? null)) {
            throw new FiscalException('Não foi possível abrir o certificado salvo. Envie o A1 novamente.', 'CERTIFICATE_STORAGE_INVALID', [], 422);
        }
        return ['base64' => $decoded['pfx'], 'password' => $decoded['password']];
    }

    private function encrypt(string $plaintext): string
    {
        $key = $this->encryptionKey();
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
        if (!is_string($ciphertext)) {
            throw new FiscalException('Não foi possível proteger o certificado para armazenamento.', 'CERTIFICATE_ENCRYPTION_FAILED', [], 503);
        }
        return json_encode([
            'version' => 1,
            'nonce' => base64_encode($nonce),
            'tag' => base64_encode($tag),
            'ciphertext' => base64_encode($ciphertext),
        ], JSON_THROW_ON_ERROR);
    }

    private function decrypt(string $envelope): string
    {
        $data = json_decode($envelope, true);
        if (!is_array($data) || ($data['version'] ?? null) !== 1) {
            throw new FiscalException('O certificado salvo está em um formato inválido.', 'CERTIFICATE_STORAGE_INVALID', [], 422);
        }
        $nonce = base64_decode((string) ($data['nonce'] ?? ''), true);
        $tag = base64_decode((string) ($data['tag'] ?? ''), true);
        $ciphertext = base64_decode((string) ($data['ciphertext'] ?? ''), true);
        if ($nonce === false || strlen($nonce) !== 12 || $tag === false || strlen($tag) !== 16 || $ciphertext === false) {
            throw new FiscalException('O certificado salvo está em um formato inválido.', 'CERTIFICATE_STORAGE_INVALID', [], 422);
        }
        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $this->encryptionKey(), OPENSSL_RAW_DATA, $nonce, $tag);
        if (!is_string($plaintext)) {
            throw new FiscalException('Não foi possível abrir o certificado salvo. Verifique a chave de criptografia do servidor.', 'CERTIFICATE_DECRYPTION_FAILED', [], 503);
        }
        return $plaintext;
    }

    private function encryptionKey(): string
    {
        $configured = trim((string) (getenv('FISCAL_CERTIFICATE_ENCRYPTION_KEY') ?: ''));
        $key = base64_decode($configured, true);
        if ($key === false || strlen($key) !== 32) {
            throw new FiscalException('A chave segura de armazenamento de certificados não está configurada no servidor.', 'CERTIFICATE_ENCRYPTION_KEY_REQUIRED', [], 503);
        }
        return $key;
    }

    private function client(string $authorization): SupabaseApiClient
    {
        if (!preg_match('/^Bearer\s+([A-Za-z0-9._~-]+)$/i', trim($authorization), $matches)) {
            throw new FiscalException('Entre novamente para salvar o certificado.', 'AUTH_REQUIRED', [], 401);
        }
        $url = trim((string) (getenv('SUPABASE_API_URL') ?: ''));
        $anonKey = trim((string) (getenv('SUPABASE_ANON_KEY') ?: ''));
        if ($url === '' || $anonKey === '') {
            throw new FiscalException('O serviço fiscal não está conectado ao banco de dados.', 'SUPABASE_CONFIGURATION_REQUIRED', [], 503);
        }
        return new SupabaseApiClient($url, $anonKey, $matches[1]);
    }

    private function uuid($value): string
    {
        $uuid = strtolower((string) $value);
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid)) {
            throw new FiscalException('Empresa ou organização inválida.', 'INVALID_IDENTIFIER', [], 422);
        }
        return $uuid;
    }
}
