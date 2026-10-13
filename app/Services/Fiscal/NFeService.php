<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\DTO\NFeRequestDTO;

final class NFeService
{
    private const MODEL = 55;

    private SefazService $sefazService;

    public function __construct(SefazService $sefazService)
    {
        $this->sefazService = $sefazService;
    }

    /**
     * @return array<string, mixed>
     */
    public function validarCertificado(NFeRequestDTO $dto): array
    {
        return $this->sefazService->validateCertificate($dto->payload(), self::MODEL);
    }

    /**
     * Consulta um lote do serviço de Distribuição DF-e (modelo 55).
     * O chamador deve persistir ult_nsu e usá-lo na próxima consulta.
     *
     * @return array<string, mixed>
     */
    public function distribuir(NFeRequestDTO $dto): array
    {
        return $this->sefazService->distribuir($dto->payload(), self::MODEL);
    }

    /**
     * @return array<string, mixed>
     */
    public function manifestarCiencia(string $accessKey, NFeRequestDTO $dto): array
    {
        return $this->sefazService->manifestarCiencia($dto->payload(), self::MODEL, $accessKey);
    }

    /**
     * @return array<string, mixed>
     */
    public function criar(NFeRequestDTO $dto): array
    {
        return $this->sefazService->create($dto->payload(), self::MODEL);
    }

    /**
     * @return array<string, mixed>
     */
    public function assinar(NFeRequestDTO $dto): array
    {
        return $this->sefazService->sign($dto->payload(), self::MODEL);
    }

    /**
     * @return array<string, mixed>
     */
    public function validarXml(NFeRequestDTO $dto): array
    {
        return $this->sefazService->validateXml($dto->payload(), self::MODEL);
    }

    /**
     * @return array<string, mixed>
     */
    public function enviar(NFeRequestDTO $dto): array
    {
        return $this->sefazService->send($dto->payload(), self::MODEL);
    }

    /**
     * @return array<string, mixed>
     */
    public function consultar(string $chave, NFeRequestDTO $dto): array
    {
        return $this->sefazService->consultByKey($dto->payload(), self::MODEL, $chave);
    }

    /**
     * @return array<string, mixed>
     */
    public function consultarRecibo(NFeRequestDTO $dto): array
    {
        return $this->sefazService->consultReceipt($dto->payload(), self::MODEL);
    }

    /**
     * @return array<string, mixed>
     */
    public function status(NFeRequestDTO $dto): array
    {
        return $this->sefazService->status($dto->payload(), self::MODEL);
    }

    /**
     * @return array<string, mixed>
     */
    public function cancelar(string $chave, NFeRequestDTO $dto): array
    {
        return $this->sefazService->cancel($dto->payload(), self::MODEL, $chave);
    }

    /**
     * @return array<string, mixed>
     */
    public function inutilizar(NFeRequestDTO $dto): array
    {
        return $this->sefazService->inutilize($dto->payload(), self::MODEL);
    }

    /**
     * @return array<string, mixed>
     */
    public function cadastro(NFeRequestDTO $dto): array
    {
        return $this->sefazService->cadastro($dto->payload(), self::MODEL);
    }

    /**
     * @return array<string, mixed>
     */
    public function danfe(string $chave, NFeRequestDTO $dto): array
    {
        return $this->sefazService->danfe($dto->payload(), self::MODEL, $chave);
    }
}
