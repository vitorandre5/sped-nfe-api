<?php

declare(strict_types=1);

namespace App\Controllers;

use App\DTO\NFeRequestDTO;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Services\Fiscal\NFeDistributionSyncService;
use App\Services\Fiscal\NFeService;

final class NFeController
{
    private NFeService $service;

    private NFeDistributionSyncService $syncService;

    public function __construct(NFeService $service, NFeDistributionSyncService $syncService)
    {
        $this->service = $service;
        $this->syncService = $syncService;
    }

    public function validarCertificado(Request $request): JsonResponse
    {
        return $this->success('Certificado validado com sucesso.', $this->service->validarCertificado(NFeRequestDTO::fromArray($request->body())));
    }

    public function distribuir(Request $request): JsonResponse
    {
        return $this->success(
            'Consulta de distribuição de NF-e realizada com sucesso.',
            $this->service->distribuir(NFeRequestDTO::fromArray($request->body()))
        );
    }

    public function sincronizar(Request $request): JsonResponse
    {
        return $this->success(
            'Sincronização de documentos fiscais concluída.',
            $this->syncService->synchronize($request->body(), (string) $request->header('authorization', ''))
        );
    }

    public function criar(Request $request): JsonResponse
    {
        return $this->success('NF-e criada com sucesso.', $this->service->criar(NFeRequestDTO::fromArray($request->body())));
    }

    public function assinar(Request $request): JsonResponse
    {
        return $this->success('NF-e assinada com sucesso.', $this->service->assinar(NFeRequestDTO::fromArray($request->body())));
    }

    public function validarXml(Request $request): JsonResponse
    {
        return $this->success('XML validado com sucesso.', $this->service->validarXml(NFeRequestDTO::fromArray($request->body())));
    }

    public function enviar(Request $request): JsonResponse
    {
        return $this->success('NF-e enviada com sucesso.', $this->service->enviar(NFeRequestDTO::fromArray($request->body())));
    }

    public function consultar(Request $request): JsonResponse
    {
        return $this->success(
            'Consulta da NF-e realizada com sucesso.',
            $this->service->consultar((string) $request->attribute('chave'), NFeRequestDTO::fromArray($request->body()))
        );
    }

    public function consultarRecibo(Request $request): JsonResponse
    {
        return $this->success('Consulta de recibo realizada com sucesso.', $this->service->consultarRecibo(NFeRequestDTO::fromArray($request->body())));
    }

    public function status(Request $request): JsonResponse
    {
        return $this->success('Status do serviço consultado com sucesso.', $this->service->status(NFeRequestDTO::fromArray($request->body())));
    }

    public function cancelar(Request $request): JsonResponse
    {
        return $this->success(
            'NF-e cancelada com sucesso.',
            $this->service->cancelar((string) $request->attribute('chave'), NFeRequestDTO::fromArray($request->body()))
        );
    }

    public function inutilizar(Request $request): JsonResponse
    {
        return $this->success('Numeração inutilizada com sucesso.', $this->service->inutilizar(NFeRequestDTO::fromArray($request->body())));
    }

    public function cadastro(Request $request): JsonResponse
    {
        return $this->success('Consulta de cadastro realizada com sucesso.', $this->service->cadastro(NFeRequestDTO::fromArray($request->body())));
    }

    public function danfe(Request $request): JsonResponse
    {
        return $this->success(
            'DANFE gerado com sucesso.',
            $this->service->danfe((string) $request->attribute('chave'), NFeRequestDTO::fromArray($request->body()))
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function success(string $message, array $data): JsonResponse
    {
        return new JsonResponse(200, [
            'success' => true,
            'message' => $message,
            'data' => $data,
        ]);
    }
}
