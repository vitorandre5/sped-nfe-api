<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Exceptions\FiscalException;
use DOMDocument;

final class DanfeService
{
    /**
     * @return array<string, mixed>
     */
    public function generate(string $xml, string $documentType): array
    {
        if ($xml === '' || strlen($xml) > 20 * 1024 * 1024) {
            throw new FiscalException('O XML está vazio ou excede 20 MiB.', 'INVALID_XML_SIZE', [], 413);
        }

        if (preg_match('/<!DOCTYPE/i', $xml) === 1) {
            throw new FiscalException('O XML contém uma declaração não permitida.', 'INVALID_XML', [], 422);
        }

        $document = new DOMDocument();
        $previousLibxmlState = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previousLibxmlState);
        if (!$loaded) {
            throw new FiscalException('O XML do documento está inválido.', 'INVALID_XML', [], 422);
        }

        $root = $document->documentElement?->localName;
        $normalizedType = strtolower($documentType);
        if (in_array($normalizedType, ['nfe55', 'nfe55_hom'], true) && $root === 'resNFe') {
            throw new FiscalException(
                'O documento ainda não está disponível para visualização.',
                'NFE_SUMMARY_ONLY',
                [],
                422
            );
        }

        if (in_array($normalizedType, ['nfe55', 'nfe55_hom'], true) && in_array($root, ['NFe', 'nfeProc'], true)) {
            $className = \NFePHP\DA\NFe\Danfe::class;
        } elseif (in_array($normalizedType, ['nfse', 'nfse_hom'], true) && $root === 'NFSe') {
            $className = \NFePHP\DA\NFSe\Danfse::class;
        } else {
            throw new FiscalException('Tipo de documento ou raiz XML não compatível com a geração do DANFE/DANFSe.', 'UNSUPPORTED_DOCUMENT_TYPE', [], 422);
        }

        if (!class_exists($className)) {
            throw new FiscalException(
                'Geração do documento depende do pacote nfephp-org/sped-da, que não está instalado neste projeto.',
                'DANFE_DEPENDENCY_MISSING',
                ['composer_require' => 'nfephp-org/sped-da'],
                501
            );
        }

        $generator = new $className($xml);
        if ($generator instanceof \NFePHP\DA\NFSe\Danfse) {
            $generator->setPrintCanhoto(false);
        }
        $pdf = $generator->render();

        return [
            'pdf_base64' => base64_encode($pdf),
            'content_type' => 'application/pdf',
        ];
    }
}
