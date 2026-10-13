<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Exceptions\FiscalException;

final class NationalNfseService
{
    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function distribute(array $payload, string $lastNsu): array
    {
        $context = (new CompanyContextResolver())->resolve($payload, 55, false);
        (new CertificateService())->assertAvailable($context);
        $certificate = (new CertificateService())->readPfx(
            (string) file_get_contents($context['certificado']['path']),
            (string) $context['certificado']['password']
        );
        if ($certificate->isExpired()) {
            throw new FiscalException('O certificado A1 está vencido.', 'CERTIFICATE_EXPIRED', [], 422);
        }
        if (substr($certificate->getCnpj(), 0, 8) !== substr($context['config']['cnpj'], 0, 8)) {
            throw new FiscalException('O certificado A1 não pertence à raiz do CNPJ selecionado.', 'CERTIFICATE_COMPANY_MISMATCH', [], 422);
        }
        $baseUrl = $context['tpAmb'] === 1
            ? 'https://adn.nfse.gov.br/contribuintes'
            : 'https://adn.producaorestrita.nfse.gov.br/contribuintes';
        $url = $baseUrl . '/DFe/' . (int) $lastNsu . '?' . http_build_query(['cnpjConsulta' => $context['config']['cnpj'], 'lote' => 'true']);
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSLCERT_BLOB => (string) $certificate->publicKey . "\n" . (string) $certificate->chainKeys,
            CURLOPT_SSLKEY_BLOB => (string) $certificate->privateKey,
        ]);
        try {
            $body = curl_exec($curl);
            $httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            if (!is_string($body)) {
                throw new FiscalException('Não foi possível conectar ao Ambiente Nacional da NFS-e.', 'NFSE_CONNECTION_FAILED', [], 502);
            }
        } finally {
            curl_close($curl);
        }
        $response = json_decode($body, true);
        if (!is_array($response) || !in_array($httpStatus, [200, 404], true)) {
            throw new FiscalException('O Ambiente Nacional não concluiu a consulta de NFS-e.', 'NFSE_REQUEST_FAILED', ['http_status' => $httpStatus], 502);
        }
        return $this->decodeResponse($response, $lastNsu, $context['tpAmb']);
    }

    /** @param array<string, mixed> $response @return array<string, mixed> */
    public function decodeResponse(array $response, string $lastNsu, int $environment): array
    {
        $expected = $environment === 1 ? 'PRODUCAO' : 'HOMOLOGACAO';
        if (($response['TipoAmbiente'] ?? null) !== $expected) {
            throw new FiscalException('O Ambiente Nacional retornou um ambiente diferente do solicitado.', 'NFSE_ENVIRONMENT_MISMATCH', [], 502);
        }
        $status = (string) ($response['StatusProcessamento'] ?? '');
        if (!in_array($status, ['DOCUMENTOS_LOCALIZADOS', 'NENHUM_DOCUMENTO_LOCALIZADO'], true)) {
            $codes = array_map(static fn (array $error): string => (string) ($error['Codigo'] ?? ''), $response['Erros'] ?? []);
            throw new FiscalException('O Ambiente Nacional rejeitou a consulta de NFS-e.', 'NFSE_DISTRIBUTION_REJECTED', ['codes' => $codes], 502);
        }
        $documents = [];
        $nextNsu = str_pad($lastNsu, 15, '0', STR_PAD_LEFT);
        foreach ($response['LoteDFe'] ?? [] as $source) {
            $nsu = (string) ($source['NSU'] ?? '');
            if (!preg_match('/^\d{1,15}$/', $nsu)) {
                throw new FiscalException('O Ambiente Nacional retornou um NSU inválido.', 'INVALID_NSU', [], 502);
            }
            $nsu = str_pad($nsu, 15, '0', STR_PAD_LEFT);
            if ($nsu <= $nextNsu) {
                throw new FiscalException('O lote de NFS-e não avançou o NSU.', 'NFSE_INVALID_SEQUENCE', [], 502);
            }
            $encoded = base64_decode((string) ($source['ArquivoXml'] ?? ''), true);
            $xml = $encoded === false ? false : @gzdecode($encoded, 20 * 1024 * 1024);
            if ($xml === false || $xml === '') {
                throw new FiscalException('Não foi possível descompactar uma NFS-e.', 'DISTRIBUTED_XML_INVALID', [], 502);
            }
            $documents[] = [
                'nsu' => $nsu,
                'schema' => 'nfse_nacional',
                'access_key' => (string) ($source['ChaveAcesso'] ?? ''),
                'document_kind' => (string) ($source['TipoDocumento'] ?? ''),
                'event_kind' => (string) ($source['TipoEvento'] ?? ''),
                'xml' => $xml,
            ];
            $nextNsu = $nsu;
        }
        if ($status === 'DOCUMENTOS_LOCALIZADOS' && $documents === []) {
            throw new FiscalException('O Ambiente Nacional retornou um lote vazio.', 'NFSE_EMPTY_BATCH', [], 502);
        }
        return [
            'cStat' => $documents === [] ? '137' : '138',
            'ult_nsu' => $nextNsu,
            'max_nsu' => null,
            'documentos' => $documents,
            'has_more' => count($documents) >= 50,
        ];
    }
}
