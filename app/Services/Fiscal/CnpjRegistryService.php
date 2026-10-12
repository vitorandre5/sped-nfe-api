<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Exceptions\FiscalException;

final class CnpjRegistryService
{
    private string $supabaseUrl;
    private string $anonKey;

    public function __construct(string $supabaseUrl, string $anonKey)
    {
        $this->supabaseUrl = trim($supabaseUrl);
        $this->anonKey = trim($anonKey);
    }

    /** @param array<string, mixed> $payload
     *  @return array{found:bool,data:?array<string,mixed>,message:string}
     */
    public function lookup(array $payload, string $authorization): array
    {
        if ($this->supabaseUrl === '' || $this->anonKey === '') {
            throw new FiscalException('O serviço de empresas não está conectado ao banco de dados.', 'SUPABASE_CONFIGURATION_REQUIRED', [], 503);
        }
        if (!preg_match('/^Bearer\s+([A-Za-z0-9._~-]+)$/i', trim($authorization), $matches)) {
            throw new FiscalException('Entre novamente para consultar os dados da empresa.', 'AUTH_REQUIRED', [], 401);
        }

        $organizationId = strtolower((string) ($payload['organization_id'] ?? ''));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $organizationId)) {
            throw new FiscalException('Organização inválida.', 'INVALID_ORGANIZATION', [], 422);
        }
        $cnpj = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) ($payload['cnpj'] ?? '')) ?? '');
        if (!preg_match('/^[A-Z0-9]{12}[0-9]{2}$/', $cnpj)) {
            throw new FiscalException('Informe um CNPJ com 14 caracteres válidos.', 'INVALID_CNPJ', [], 422);
        }

        $client = new SupabaseApiClient($this->supabaseUrl, $this->anonKey, $matches[1]);
        $userId = $client->authenticatedUserId();
        $membership = $client->select('organization_members', [
            'organization_id' => $organizationId,
            'user_id' => $userId,
        ], 'organization_id,user_id');
        if ($membership === []) {
            throw new FiscalException('Você não tem acesso a esta organização.', 'ORGANIZATION_NOT_ACCESSIBLE', [], 403);
        }

        $url = 'https://api.cnpj.pw/cnpj/' . rawurlencode($cnpj);
        $handle = curl_init($url);
        if ($handle === false) {
            return $this->unavailable();
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: Fiscal/1.0'],
        ]);
        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if (!is_string($response) || $status === 429 || $status >= 500 || $status === 0) {
            return $this->unavailable();
        }
        if ($status === 404) {
            return ['found' => false, 'data' => null, 'message' => 'Não encontramos dados públicos para este CNPJ. Você pode preencher o cadastro manualmente.'];
        }
        if ($status < 200 || $status >= 300) {
            return $this->unavailable();
        }

        $record = json_decode($response, true);
        if (!is_array($record)) {
            return $this->unavailable();
        }
        $returnedCnpj = strtoupper((string) ($record['cnpj_base'] ?? '') . (string) ($record['cnpj_ordem'] ?? '') . (string) ($record['cnpj_dv'] ?? ''));
        if ($returnedCnpj === '' || !hash_equals($cnpj, $returnedCnpj)) {
            return ['found' => false, 'data' => null, 'message' => 'A consulta não confirmou este CNPJ. Você pode preencher o cadastro manualmente.'];
        }

        // Allowlist: do not return or persist the API's socios array or arbitrary upstream fields.
        $data = [];
        foreach ([
            'nome_empresarial', 'nome_fantasia', 'natureza_juridica', 'natureza_juridica_desc',
            'capital_social', 'porte_empresa', 'porte_empresa_descricao', 'situacao_cadastral',
            'situacao_cadastral_descricao', 'data_situacao_cadastral', 'data_inicio_atividade',
            'cnae_fiscal_principal', 'cnae_fiscal_principal_descricao', 'tipo_logradouro',
            'logradouro', 'numero', 'complemento', 'bairro', 'cep', 'uf', 'municipio',
            'municipio_desc', 'ddd1', 'telefone_1', 'correio_eletronico', 'opcao_simples', 'opcao_mei',
        ] as $key) {
            if (array_key_exists($key, $record) && (is_string($record[$key]) || is_int($record[$key]) || is_float($record[$key]) || is_bool($record[$key]) || $record[$key] === null)) {
                $data[$key] = $record[$key];
            }
        }
        $data['cnae_secundarios'] = [];
        foreach (($record['cnaes_fiscais_secundarios'] ?? []) as $cnae) {
            if (!is_array($cnae)) {
                continue;
            }
            $code = $cnae['codigo'] ?? $cnae['cnae'] ?? null;
            if (is_int($code) || (is_string($code) && ctype_digit($code))) {
                $data['cnae_secundarios'][] = (string) $code;
            }
        }
        $data['fonte'] = 'cnpj.pw';
        $data['consultado_em'] = gmdate(DATE_ATOM);

        return ['found' => true, 'data' => $data, 'message' => 'Dados cadastrais públicos encontrados. Confira os campos antes de salvar.'];
    }

    /** @return array{found:false,data:null,message:string} */
    private function unavailable(): array
    {
        return ['found' => false, 'data' => null, 'message' => 'A consulta pública está indisponível no momento. Você pode preencher o cadastro manualmente.'];
    }
}
