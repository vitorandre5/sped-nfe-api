<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Exceptions\FiscalException;
use DOMDocument;
use DOMXPath;

final class NfseXmlParser
{
    /** @param array<string, mixed> $source @return array<string, mixed> */
    public function parse(array $source, string $companyCnpj, int $environment = 1): array
    {
        $xml = (string) ($source['xml'] ?? '');
        $key = (string) ($source['access_key'] ?? '');
        if ($xml === '' || strlen($xml) > 20 * 1024 * 1024 || stripos($xml, '<!DOCTYPE') !== false || !preg_match('/^\d{50}$/', $key)) {
            throw new FiscalException('O Ambiente Nacional retornou uma NFS-e inválida.', 'INVALID_DISTRIBUTED_XML', [], 502);
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $dom = new DOMDocument();
            $dom->resolveExternals = false;
            $dom->substituteEntities = false;
            if (!$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS) || !$dom->documentElement) {
                throw new FiscalException('O XML da NFS-e é inválido.', 'INVALID_DISTRIBUTED_XML', [], 502);
            }
            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('n', 'http://www.sped.fazenda.gov.br/nfse');
            $text = static function (string $path) use ($xpath): ?string {
                $value = trim((string) $xpath->evaluate('string(' . $path . ')'));
                return $value === '' ? null : $value;
            };
            $isInvoice = ($source['document_kind'] ?? '') === 'NFSE';
            $version = $dom->documentElement->getAttribute('versao');
            if (!in_array($version, ['1.00', '1.01'], true)) {
                throw new FiscalException('A versão do XML da NFS-e não é suportada.', 'NFSE_VERSION_NOT_SUPPORTED', [], 502);
            }
            // This is an archive reader for documents already distributed by the
            // authenticated government API, not an emission validator. Municipal
            // historical XMLs returned by ADN may not fit the current emission XSD
            // (MEI status 107, RSA-SHA256, textual RPS series). Validate the fiscal
            // identity/fields below and preserve the government's original bytes.
            if ($isInvoice && ($dom->documentElement->localName !== 'NFSe'
                || $text('/n:NFSe/n:infNFSe/@Id') !== 'NFS' . $key)) {
                throw new FiscalException('A chave do XML não corresponde à NFS-e distribuída.', 'INVALID_ACCESS_KEY', [], 502);
            }
            if ($isInvoice && $text('//n:infDPS/n:tpAmb') !== (string) $environment) {
                throw new FiscalException('O ambiente informado no XML da NFS-e é inválido.', 'NFSE_ENVIRONMENT_MISMATCH', [], 502);
            }
            if (!$isInvoice && (($source['document_kind'] ?? '') !== 'EVENTO'
                || $text('//n:chNFSe') !== $key)) {
                throw new FiscalException('O documento distribuído não é uma NFS-e ou evento válido.', 'NFSE_DOCUMENT_NOT_SUPPORTED', [], 502);
            }
            $issuer = $text('/n:NFSe/n:infNFSe/n:emit/n:CNPJ') ?? $text('/n:NFSe/n:infNFSe/n:emit/n:CPF');
            $recipient = $text('//n:infDPS/n:toma/n:CNPJ') ?? $text('//n:infDPS/n:toma/n:CPF');
            $intermediary = $text('//n:infDPS/n:interm/n:CNPJ');
            if ($isInvoice && !in_array($companyCnpj, [$issuer, $recipient, $intermediary], true)) {
                throw new FiscalException('A NFS-e não pertence ao CNPJ consultado.', 'NFSE_COMPANY_MISMATCH', [], 502);
            }
            $amount = $text('//n:infDPS/n:valores/n:vServPrest/n:vServ');
            if ($isInvoice && ($amount === null || !preg_match('/^\d{1,13}(\.\d{1,2})?$/', $amount))) {
                throw new FiscalException('O valor da NFS-e é inválido.', 'NFSE_AMOUNT_INVALID', [], 502);
            }
            $issuedAt = $text('//n:infDPS/n:dhEmi') ?? $text('/n:NFSe/n:infNFSe/n:dhProc');
            if ($isInvoice && ($issuedAt === null || strtotime($issuedAt) === false)) {
                throw new FiscalException('A data da NFS-e é inválida.', 'NFSE_DATE_INVALID', [], 502);
            }
            $number = $text('/n:NFSe/n:infNFSe/n:nNFSe');
            if ($isInvoice && ($number === null || !preg_match('/^\d{1,15}$/', $number))) {
                throw new FiscalException('O número da NFS-e é inválido.', 'NFSE_NUMBER_INVALID', [], 502);
            }
            $municipalTaxPath = '//n:infDPS/n:valores/n:trib/n:tribMun';
            $municipalTaxCode = $text($municipalTaxPath . '/n:tribISSQN');
            $municipalTax = [
                'status' => 'not_informed',
                'tax_type' => $municipalTaxCode,
                'incidence_municipality_code' => $text('/n:NFSe/n:infNFSe/n:cLocIncid'),
                'incidence_municipality' => $text('/n:NFSe/n:infNFSe/n:xLocIncid'),
                'municipal_service_code' => $text('//n:infDPS/n:serv/n:cServ/n:cTribMun'),
                'municipal_service_description' => $text('//n:infDPS/n:serv/n:cServ/n:xTribMun'),
                'special_regime' => $text('//n:infDPS/n:prest/n:regTrib/n:regEspTrib'),
                'immunity_type' => $text($municipalTaxPath . '/n:tpImunidade'),
                'suspension_type' => $text($municipalTaxPath . '/n:exigSusp/n:tpSusp'),
                'suspension_process' => $text($municipalTaxPath . '/n:exigSusp/n:nProcesso'),
                'benefit_type' => $text($municipalTaxPath . '/n:tpBM'),
                'benefit_amount' => $text($municipalTaxPath . '/n:vCalcBM'),
                'deductions' => $text('//n:infDPS/n:valores/n:vDedRed/n:vDR') ?? $text('//n:infDPS/n:valores/n:vDedRed/n:vCalcDR'),
                'unconditional_discount' => $text('//n:infDPS/n:valores/n:vDescIncond'),
                'tax_base' => $text('/n:NFSe/n:infNFSe/n:valores/n:vBC'),
                'rate' => $text('/n:NFSe/n:infNFSe/n:valores/n:pAliqAplic'),
                'retention_type' => $text($municipalTaxPath . '/n:tpRetISSQN'),
                'amount' => $text('/n:NFSe/n:infNFSe/n:valores/n:vISSQN'),
            ];
            if ($municipalTaxCode === '4') {
                $municipalTax['status'] = 'not_subject';
            } elseif (count(array_filter([
                $municipalTax['tax_type'], $municipalTax['immunity_type'], $municipalTax['suspension_type'],
                $municipalTax['suspension_process'], $municipalTax['benefit_type'], $municipalTax['benefit_amount'],
                $municipalTax['deductions'], $municipalTax['unconditional_discount'], $municipalTax['tax_base'],
                $municipalTax['rate'], $municipalTax['retention_type'], $municipalTax['amount'],
            ], static fn ($value): bool => $value !== null)) > 0) {
                $municipalTax['status'] = 'available';
            }
            return [
                'access_key' => $key,
                'is_invoice' => $isInvoice,
                'issuer_name' => $text('/n:NFSe/n:infNFSe/n:emit/n:xNome'),
                'issuer_cnpj' => $issuer,
                'recipient_name' => $text('//n:infDPS/n:toma/n:xNome'),
                'recipient_cnpj' => $recipient,
                'document_number' => $number,
                'series' => $text('//n:infDPS/n:serie'),
                'issued_at' => $issuedAt,
                'total_amount' => $amount,
                'municipal_tax' => $municipalTax,
                'is_purchase' => $recipient === $companyCnpj,
                'is_sale' => $issuer === $companyCnpj,
                'operation_nature' => $text('//n:cServ/n:xDescServ'),
                'cfops' => [],
                'operation_categories' => [],
                'situation' => 'Documento recebido',
                'event_type' => (string) ($source['event_kind'] ?? ''),
                'event_description' => (string) ($source['event_kind'] ?? ''),
            ];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
