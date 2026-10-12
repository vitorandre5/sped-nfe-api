<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\JsonResponse;
use App\Http\Request;
use App\Services\Fiscal\CnpjRegistryService;

final class CnpjRegistryController
{
    private CnpjRegistryService $service;

    public function __construct(CnpjRegistryService $service)
    {
        $this->service = $service;
    }

    public function lookup(Request $request): JsonResponse
    {
        return new JsonResponse(200, [
            'success' => true,
            'data' => $this->service->lookup($request->body(), (string) $request->header('authorization', '')),
        ]);
    }
}
