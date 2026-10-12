<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Exceptions\FiscalException;

final class CompanyContextResolver
{
    private const UF_CPF_BLOCKLIST = ['CE', 'PR', 'SP'];

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function resolve(array $payload, int $model, bool $requireNfceCsc = true): array
    {
        if (!empty($payload['empresa_id']) && empty($payload['emitente'])) {
            throw new FiscalException(
                'empresa_id foi enviado, mas este workspace não possui integração com seu repositório de empresas. Você precisa ligar este fluxo ao seu banco real.',
                'COMPANY_REPOSITORY_NOT_IMPLEMENTED',
                ['required_mapping' => ['empresa_id -> emitente', 'empresa_id -> certificado']],
                501
            );
        }

        $emitente = is_array($payload['emitente'] ?? null) ? $payload['emitente'] : [];
        $emitenteEndereco = is_array($emitente['endereco'] ?? null) ? $emitente['endereco'] : [];
        $certificado = is_array($payload['certificado'] ?? null) ? $payload['certificado'] : [];
        $uploadedCertificatePath = $this->extractUploadedCertificatePath($payload, $certificado);
        $ambiente = $this->normalizeEnvironment($payload['ambiente'] ?? $payload['tpAmb'] ?? getenv('NFE_TPAMB') ?: '2');
        $uf = strtoupper((string) ($payload['uf'] ?? $emitenteEndereco['UF'] ?? $emitenteEndereco['uf'] ?? getenv('NFE_SIGLA_UF') ?: ''));

        $documento = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) ($emitente['cnpj'] ?? $payload['cnpj'] ?? getenv('NFE_CNPJ') ?: '')));
        $cpf = preg_replace('/\D/', '', (string) ($emitente['cpf'] ?? $payload['cpf'] ?? ''));
        $isCpfEmitter = strlen($cpf) === 11 || (strlen($documento) === 11 && strlen($cpf) === 0);

        if ($isCpfEmitter && in_array($uf, self::UF_CPF_BLOCKLIST, true)) {
            throw new FiscalException(
                'A UF informada não aceita emissão com eCPF.',
                'ECPF_NOT_ALLOWED_FOR_UF',
                [
                    'uf' => $uf,
                    'blocked_ufs' => self::UF_CPF_BLOCKLIST,
                ],
                422
            );
        }

        $configCnpj = strlen($documento) >= 11 ? $documento : $cpf;
        $context = [
            'ambiente' => $ambiente['label'],
            'tpAmb' => $ambiente['tpAmb'],
            'modelo' => $model,
            'uf' => $uf,
            'emitente' => [
                'xNome' => $emitente['razao_social'] ?? $emitente['xNome'] ?? $payload['razaosocial'] ?? getenv('NFE_RAZAO_SOCIAL') ?: '',
                'xFant' => $emitente['nome_fantasia'] ?? $emitente['xFant'] ?? null,
                'IE' => $emitente['ie'] ?? $emitente['IE'] ?? null,
                'IEST' => $emitente['iest'] ?? $emitente['IEST'] ?? null,
                'IM' => $emitente['im'] ?? $emitente['IM'] ?? null,
                'CNAE' => $emitente['cnae'] ?? $emitente['CNAE'] ?? null,
                'CRT' => $emitente['crt'] ?? $emitente['CRT'] ?? null,
                'CNPJ' => strlen($documento) === 14 && preg_match('/^[A-Z0-9]{12}[0-9]{2}$/', $documento) ? $documento : null,
                'CPF' => strlen($cpf) === 11 ? $cpf : (strlen($documento) === 11 ? $documento : null),
                'enderEmit' => [
                    'xLgr' => $emitenteEndereco['xLgr'] ?? $emitenteEndereco['logradouro'] ?? null,
                    'nro' => $emitenteEndereco['nro'] ?? $emitenteEndereco['numero'] ?? null,
                    'xCpl' => $emitenteEndereco['xCpl'] ?? $emitenteEndereco['complemento'] ?? null,
                    'xBairro' => $emitenteEndereco['xBairro'] ?? $emitenteEndereco['bairro'] ?? null,
                    'cMun' => $emitenteEndereco['cMun'] ?? $emitenteEndereco['codigo_municipio'] ?? null,
                    'xMun' => $emitenteEndereco['xMun'] ?? $emitenteEndereco['municipio'] ?? null,
                    'UF' => $uf,
                    'CEP' => preg_replace('/\D/', '', (string) ($emitenteEndereco['CEP'] ?? $emitenteEndereco['cep'] ?? '')),
                    'cPais' => $emitenteEndereco['cPais'] ?? '1058',
                    'xPais' => $emitenteEndereco['xPais'] ?? 'BRASIL',
                    'fone' => preg_replace('/\D/', '', (string) ($emitenteEndereco['fone'] ?? $emitenteEndereco['telefone'] ?? '')),
                ],
            ],
            'certificado' => [
                'path' => $certificado['path']
                    ?? $payload['certPath']
                    ?? $uploadedCertificatePath
                    ?? ((getenv('NFE_CERT_PATH') ?: null)),
                'base64' => $certificado['base64'] ?? $payload['certBase64'] ?? null,
                'password' => $certificado['password']
                    ?? $payload['certPassword']
                    ?? $payload['certificado_password']
                    ?? ((getenv('NFE_CERT_PASSWORD') ?: null)),
            ],
            'config' => [
                'atualizacao' => date('Y-m-d H:i:s'),
                'tpAmb' => $ambiente['tpAmb'],
                'razaosocial' => $emitente['razao_social'] ?? $emitente['xNome'] ?? $payload['razaosocial'] ?? getenv('NFE_RAZAO_SOCIAL') ?: '',
                'cnpj' => $configCnpj,
                'siglaUF' => $uf,
                'schemes' => $payload['schemes'] ?? getenv('NFE_SCHEMES') ?: 'PL_009_V4',
                'versao' => $payload['versao'] ?? getenv('NFE_VERSAO') ?: '4.00',
                'tokenIBPT' => $payload['tokenIBPT'] ?? null,
                'CSC' => $payload['CSC'] ?? $payload['csc'] ?? getenv('NFE_CSC') ?: null,
                'CSCid' => $payload['CSCid'] ?? $payload['idCSC'] ?? getenv('NFE_CSC_ID') ?: null,
            ],
        ];

        $this->validateResolvedContext($context, $requireNfceCsc);
        return $context;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function validateResolvedContext(array $context, bool $requireNfceCsc): void
    {
        if (empty($context['uf']) || strlen((string) $context['uf']) !== 2) {
            throw new FiscalException(
                'UF do emitente inválida.',
                'INVALID_UF',
                ['required_field' => 'uf'],
                422
            );
        }

        $emitente = $context['emitente'];
        if (empty($emitente['CNPJ']) && empty($emitente['CPF'])) {
            throw new FiscalException(
                'Emitente sem CNPJ/CPF.',
                'EMITTER_DOCUMENT_REQUIRED',
                ['required_fields' => ['emitente.cnpj', 'emitente.cpf']],
                422
            );
        }

        if (
            $requireNfceCsc
            && $context['modelo'] === 65
            && (empty($context['config']['CSC']) || empty($context['config']['CSCid']))
        ) {
            throw new FiscalException(
                'NFC-e exige CSC e ID CSC.',
                'NFCE_CSC_REQUIRED',
                ['required_fields' => ['CSC', 'CSCid']],
                422
            );
        }
    }

    /**
     * @param mixed $raw
     * @return array{tpAmb:int,label:string}
     */
    private function normalizeEnvironment($raw): array
    {
        if (is_int($raw) || ctype_digit((string) $raw)) {
            $tpAmb = (int) $raw;
        } else {
            $value = strtolower(trim((string) $raw));
            $tpAmb = in_array($value, ['1', 'producao', 'producaoo', 'produção'], true) ? 1 : 2;
        }

        if (!in_array($tpAmb, [1, 2], true)) {
            throw new FiscalException(
                'Ambiente inválido. Use produção/1 ou homologação/2.',
                'INVALID_ENVIRONMENT',
                ['value' => $raw],
                422
            );
        }

        return [
            'tpAmb' => $tpAmb,
            'label' => $tpAmb === 1 ? 'producao' : 'homologacao',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $certificado
     */
    private function extractUploadedCertificatePath(array $payload, array $certificado): ?string
    {
        $candidates = [
            $certificado['file'] ?? null,
            $payload['certificado_arquivo'] ?? null,
            $payload['certFile'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }

            if (is_array($candidate) && !empty($candidate['tmp_name'])) {
                return (string) $candidate['tmp_name'];
            }
        }

        return null;
    }
}
