<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Exceptions\FiscalException;
use DOMDocument;
use DOMElement;
use DOMXPath;

final class FiscalDocumentXmlParser
{
    /**
     * @return array<string, mixed>
     */
    public function parse(string $xml, string $schemaName, string $companyCnpj, ?string $companyName = null): array
    {
        if ($xml === '' || strlen($xml) > 20 * 1024 * 1024 || stripos($xml, '<!DOCTYPE') !== false) {
            throw new FiscalException('A SEFAZ retornou um documento fiscal inválido.', 'INVALID_DISTRIBUTED_XML', [], 422);
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument('1.0', 'UTF-8');
            $document->resolveExternals = false;
            $document->substituteEntities = false;
            if (!$document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS) || !$document->documentElement) {
                throw new FiscalException('A SEFAZ retornou um documento fiscal inválido.', 'INVALID_DISTRIBUTED_XML', [], 422);
            }

            $this->validateAgainstSchema($document, $schemaName);
            $xpath = new DOMXPath($document);
            $rootName = $document->documentElement->localName;
            $isInvoice = in_array($rootName, ['resNFe', 'procNFe', 'nfeProc'], true);

            $issuer = $this->firstElement($xpath, 'emit');
            $recipient = $this->firstElement($xpath, 'dest');
            $info = $this->firstElement($xpath, 'infNFe');
            $ide = $this->firstElement($xpath, 'ide');
            $totals = $this->firstElement($xpath, 'ICMSTot');
            $eventInfo = $this->firstElement($xpath, 'infEvento');
            $operationNature = $this->childText($xpath, $ide, 'natOp');
            $cfops = $this->cfops($xpath);
            $accessKey = $this->firstText($xpath, 'chNFe')
                ?? ($info instanceof DOMElement ? $info->getAttribute('Id') : null);
            if (is_string($accessKey)) {
                $accessKey = preg_replace('/^NFe/', '', $accessKey);
                $accessKey = strtoupper($accessKey);
            }

            if ($accessKey === null || !preg_match('/^[A-Z0-9]{44}$/', $accessKey)) {
                throw new FiscalException('O documento retornado não contém uma chave de acesso válida.', 'INVALID_ACCESS_KEY', [], 422);
            }

            $companyCnpj = $this->taxId($companyCnpj) ?? '';
            $recipientCnpj = $this->taxId($this->childText($xpath, $recipient, 'CNPJ'))
                ?? $this->taxId($this->childText($xpath, $recipient, 'CPF'));
            $recipientName = $this->childText($xpath, $recipient, 'xNome');
            if ($rootName === 'resNFe') {
                // The official distribution service sends resNFe summaries only to
                // the recipient. The summary itself omits the destination block.
                $recipientCnpj = $companyCnpj;
                $recipientName = trim((string) $companyName) !== '' ? trim((string) $companyName) : null;
            }

            $issuerCnpj = $this->taxId($this->childText($xpath, $issuer, 'CNPJ'))
                ?? $this->taxId($this->childText($xpath, $issuer, 'CPF'))
                ?? ($rootName === 'resNFe'
                    ? ($this->taxId($this->firstText($xpath, 'CNPJ')) ?? $this->taxId($this->firstText($xpath, 'CPF')))
                    : null);
            $eventType = $this->childText($xpath, $eventInfo, 'tpEvento');
            $eventDescription = $this->childText($xpath, $eventInfo, 'descEvento');

            return [
                'access_key' => $accessKey,
                'is_invoice' => $isInvoice,
                'issuer_name' => $this->childText($xpath, $issuer, 'xNome')
                    ?? ($rootName === 'resNFe' ? $this->firstText($xpath, 'xNome') : null),
                'issuer_cnpj' => $issuerCnpj,
                'recipient_name' => $recipientName,
                'recipient_cnpj' => $recipientCnpj,
                'document_number' => $this->childText($xpath, $ide, 'nNF'),
                'series' => $this->childText($xpath, $ide, 'serie'),
                'issued_at' => $this->issuedAt($xpath, $ide),
                'total_amount' => $this->money($this->childText($xpath, $totals, 'vNF')
                    ?? ($rootName === 'resNFe' ? $this->firstText($xpath, 'vNF') : null)),
                'is_purchase' => $recipientCnpj !== null && hash_equals($companyCnpj, $recipientCnpj),
                'is_sale' => $issuerCnpj !== null && hash_equals($companyCnpj, $issuerCnpj),
                'operation_nature' => $operationNature,
                'cfops' => $cfops,
                'operation_categories' => $this->operationCategories(
                    $cfops,
                    $operationNature,
                    $this->childText($xpath, $ide, 'finNFe')
                ),
                'situation' => $rootName === 'resNFe' ? 'Resumo disponível' : 'Documento recebido',
                'event_type' => $eventType,
                'event_description' => $eventDescription,
            ];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function validateAgainstSchema(DOMDocument $document, string $schemaName): void
    {
        if (!preg_match('/^[A-Za-z0-9_.-]+\.xsd$/', $schemaName)) {
            throw new FiscalException('A SEFAZ retornou um schema não reconhecido.', 'INVALID_DISTRIBUTED_SCHEMA', [], 422);
        }

        $scheme = (string) (getenv('NFE_SCHEMES') ?: 'PL_009_V4');
        if (!preg_match('/^[A-Za-z0-9_.-]+$/', $scheme)) {
            throw new FiscalException('O schema fiscal não está configurado.', 'FISCAL_SCHEMA_UNAVAILABLE', [], 503);
        }

        $root = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'schemes';
        $base = realpath($root . DIRECTORY_SEPARATOR . $scheme);
        $path = realpath($root . DIRECTORY_SEPARATOR . $scheme . DIRECTORY_SEPARATOR . $schemaName);
        if ($base === false || $path === false || strpos($path, $base . DIRECTORY_SEPARATOR) !== 0) {
            throw new FiscalException('O schema fiscal retornado não está disponível para validação.', 'FISCAL_SCHEMA_UNAVAILABLE', [], 503);
        }

        if (!$document->schemaValidate($path, LIBXML_NONET)) {
            throw new FiscalException('O documento retornado não corresponde ao schema fiscal informado.', 'DISTRIBUTED_XML_SCHEMA_INVALID', [], 422);
        }
    }

    private function firstElement(DOMXPath $xpath, string $name): ?DOMElement
    {
        $nodes = $xpath->query('//*[local-name()="' . $name . '"]');
        $node = $nodes !== false ? $nodes->item(0) : null;

        return $node instanceof DOMElement ? $node : null;
    }

    private function firstText(DOMXPath $xpath, string $name): ?string
    {
        $nodes = $xpath->query('/*//*[local-name()="' . $name . '"]');
        $value = $nodes !== false ? trim((string) $nodes->item(0)?->textContent) : '';

        return $value !== '' ? $value : null;
    }

    /** @return list<string> */
    private function cfops(DOMXPath $xpath): array
    {
        $nodes = $xpath->query('//*[local-name()="CFOP"]');
        if ($nodes === false) {
            return [];
        }

        $cfops = [];
        foreach ($nodes as $node) {
            $value = trim($node->textContent);
            if (preg_match('/^[0-9]{4}$/', $value)) {
                $cfops[] = $value;
            }
        }

        return array_values(array_unique($cfops));
    }

    /** @param list<string> $cfops
     *  @return list<string>
     */
    private function operationCategories(array $cfops, ?string $nature, ?string $finality): array
    {
        $categories = [];
        $normalizedNature = strtolower($nature ?? '');
        $hasCfop = static fn (string $pattern): bool => count(array_filter(
            $cfops,
            static fn (string $cfop): bool => preg_match($pattern, $cfop) === 1
        )) > 0;

        if ($finality === '4' || preg_match('/devolu/iu', $normalizedNature)) {
            $categories[] = 'return';
        }
        if (preg_match('/transfer[eê]ncia|transferir/iu', $normalizedNature)
            || $hasCfop('/^[1256]15[1-9]$/')) {
            $categories[] = 'transfer';
        }
        if (preg_match('/remessa|retorno/iu', $normalizedNature)
            || $hasCfop('/^[1-7]9(0[1-9]|1[0-9]|2[0-9])$/')) {
            $categories[] = 'remittance_return';
        }
        if (preg_match('/bonifica[cç][aã]o|brinde/iu', $normalizedNature)
            || $hasCfop('/^[1-7]910$/')) {
            $categories[] = 'bonus';
        }
        if (preg_match('/industrializa[cç][aã]o/iu', $normalizedNature)
            || $hasCfop('/^[1-7](90[1-3]|12[3-5])$/')) {
            $categories[] = 'industrialization';
        }
        if (preg_match('/importa[cç][aã]o|exporta[cç][aã]o/iu', $normalizedNature)
            || $hasCfop('/^[37][0-9]{3}$/')) {
            $categories[] = 'import_export';
        }

        return $categories;
    }

    private function childText(DOMXPath $xpath, ?DOMElement $parent, string $name): ?string
    {
        if (!$parent) {
            return null;
        }

        $nodes = $xpath->query('.//*[local-name()="' . $name . '"]', $parent);
        $value = $nodes !== false ? trim((string) $nodes->item(0)?->textContent) : '';

        return $value !== '' ? $value : null;
    }

    private function taxId(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $normalized = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $value) ?? '');

        return $normalized !== '' ? $normalized : null;
    }

    private function issuedAt(DOMXPath $xpath, ?DOMElement $ide): ?string
    {
        $value = $this->childText($xpath, $ide, 'dhEmi') ?? $this->childText($xpath, $ide, 'dEmi');
        if ($value === null) {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->format(DATE_ATOM);
        } catch (\Throwable $exception) {
            return null;
        }
    }

    private function money(?string $value): ?string
    {
        if ($value === null || !preg_match('/^\d+(?:\.\d{1,2})?$/', $value)) {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }
}
