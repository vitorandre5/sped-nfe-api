<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Exceptions\FiscalException;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Services\Fiscal\DanfeService;

final class DocumentPdfController
{
    private DanfeService $service;

    public function __construct(DanfeService $service)
    {
        $this->service = $service;
    }

    public function generate(Request $request): JsonResponse
    {
        $xml = $request->input('xml');
        $documentType = $request->input('document_type');
        if (!is_string($xml) || !is_string($documentType)) {
            throw new FiscalException('Informe o XML e o tipo do documento.', 'INVALID_REQUEST', [], 400);
        }

        return new JsonResponse(200, [
            'success' => true,
            'message' => 'PDF fiscal gerado com sucesso.',
            'data' => $this->service->generate($xml, $documentType),
        ]);
    }
}
