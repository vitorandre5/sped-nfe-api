<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Adapters\SpedNFeAdapter;
use App\Exceptions\FiscalException;

final class SefazService
{
    private CompanyContextResolver $contextResolver;

    private CertificateService $certificateService;

    private SpedNFeAdapter $adapter;

    private DanfeService $danfeService;

    public function __construct(
        CompanyContextResolver $contextResolver,
        CertificateService $certificateService,
        SpedNFeAdapter $adapter,
        DanfeService $danfeService
    ) {
        $this->contextResolver = $contextResolver;
        $this->certificateService = $certificateService;
        $this->adapter = $adapter;
        $this->danfeService = $danfeService;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function validateCertificate(array $payload, int $model): array
    {
        $context = $this->contextResolver->resolve($payload, $model, false);
        $this->certificateService->assertAvailable($context);

        return $this->adapter->validateCertificate($context);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function distribuir(array $payload, int $model): array
    {
        if ($model !== 55) {
            throw new FiscalException(
                'A distribuição DF-e desta API é exclusiva para NF-e modelo 55.',
                'DIST_DFE_MODEL_NOT_SUPPORTED',
                ['modelo' => $model],
                422
            );
        }

        $ultNsu = preg_replace('/\D/', '', (string) ($payload['ult_nsu'] ?? 0));
        if (strlen($ultNsu) > 15) {
            throw new FiscalException(
                'ult_nsu deve ter no máximo 15 dígitos.',
                'INVALID_ULT_NSU',
                ['required_field' => 'ult_nsu'],
                422
            );
        }

        $context = $this->contextResolver->resolve($payload, $model, false);
        $this->certificateService->assertAvailable($context);
        $numNsu = (string) ($payload['num_nsu'] ?? '0');
        if (!preg_match('/^\d{1,15}$/', $numNsu)) {
            throw new FiscalException('O NSU específico deve conter até 15 dígitos.', 'INVALID_NSU', [], 422);
        }
        return $this->adapter->distributeNFe($context, (int) $ultNsu, (int) $numNsu);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(array $payload, int $model): array
    {
        $context = $this->contextResolver->resolve($payload, $model);
        $this->assertCreatePayload($payload, $model);

        $xml = $this->adapter->createUnsignedXml($context, $payload);

        return [
            'modelo' => $model,
            'ambiente' => $context['ambiente'],
            'xml' => $xml,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function sign(array $payload, int $model): array
    {
        $context = $this->contextResolver->resolve($payload, $model);
        $this->certificateService->assertAvailable($context);
        $xml = $this->resolveXmlForSignOrSend($payload, $context);
        $signedXml = $this->adapter->signXml($context, $xml);
        $qrcode = $model === 65 ? $this->adapter->extractQrCode($signedXml) : [];

        return [
            'modelo' => $model,
            'xml' => $signedXml,
            'qrcode' => $qrcode['qrCode'] ?? null,
            'url_chave' => $qrcode['urlChave'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function validateXml(array $payload, int $model): array
    {
        $context = $this->contextResolver->resolve($payload, $model, false);
        $this->certificateService->assertAvailable($context);
        $xml = (string) ($payload['xml'] ?? '');
        if ($xml === '') {
            throw new FiscalException(
                'Informe o XML a ser validado.',
                'XML_REQUIRED',
                ['required_field' => 'xml'],
                422
            );
        }

        return [
            'valid' => $this->adapter->validateXml($context, $xml),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function send(array $payload, int $model): array
    {
        $context = $this->contextResolver->resolve($payload, $model);
        $this->certificateService->assertAvailable($context);
        $xml = $this->resolveXmlForSignOrSend($payload, $context);
        $signedXml = $this->adapter->signXml($context, $xml);
        $result = $this->adapter->sendXml(
            $context,
            $signedXml,
            isset($payload['lote']) ? (string) $payload['lote'] : null,
            isset($payload['sincrono']) && (int) $payload['sincrono'] === 0 ? 0 : 1
        );

        $response = $result['response'];
        $infRec = $response['infRec'] ?? [];
        $prot = $response['protNFe']['infProt'] ?? [];

        return [
            'chave' => $prot['chNFe'] ?? null,
            'recibo' => $infRec['nRec'] ?? null,
            'protocolo' => $prot['nProt'] ?? null,
            'cStat' => $response['cStat'] ?? null,
            'xMotivo' => $response['xMotivo'] ?? null,
            'xml' => $result['authorized_xml'] ?? $signedXml,
            'response_xml' => $result['response_xml'],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function consultByKey(array $payload, int $model, string $chave): array
    {
        $context = $this->contextResolver->resolve($payload, $model, false);
        $this->certificateService->assertAvailable($context);
        return $this->adapter->consultByKey($context, preg_replace('/\D/', '', $chave));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function consultReceipt(array $payload, int $model): array
    {
        $recibo = preg_replace('/\D/', '', (string) ($payload['recibo'] ?? ''));
        if ($recibo === '') {
            throw new FiscalException(
                'Informe o recibo para consulta de protocolo.',
                'RECEIPT_REQUIRED',
                ['required_field' => 'recibo'],
                422
            );
        }

        $context = $this->contextResolver->resolve($payload, $model);
        $this->certificateService->assertAvailable($context);
        return $this->adapter->consultReceipt($context, $recibo);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function status(array $payload, int $model): array
    {
        $context = $this->contextResolver->resolve($payload, $model, false);
        $this->certificateService->assertAvailable($context);
        return $this->adapter->serviceStatus($context);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function cancel(array $payload, int $model, string $chave): array
    {
        $context = $this->contextResolver->resolve($payload, $model, false);
        $this->certificateService->assertAvailable($context);
        $protocolo = preg_replace('/\D/', '', (string) ($payload['protocolo'] ?? ''));
        $justificativa = trim((string) ($payload['justificativa'] ?? ''));

        if ($protocolo === '' || $justificativa === '') {
            throw new FiscalException(
                'Cancelamento exige protocolo e justificativa.',
                'CANCEL_REQUIRED_FIELDS',
                ['required_fields' => ['protocolo', 'justificativa']],
                422
            );
        }

        return $this->adapter->cancel($context, preg_replace('/\D/', '', $chave), $protocolo, $justificativa);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function inutilize(array $payload, int $model): array
    {
        $context = $this->contextResolver->resolve($payload, $model);
        $this->certificateService->assertAvailable($context);

        $serie = (int) ($payload['serie'] ?? 0);
        $numeroInicial = (int) ($payload['numero_inicial'] ?? 0);
        $numeroFinal = (int) ($payload['numero_final'] ?? 0);
        $justificativa = trim((string) ($payload['justificativa'] ?? ''));

        if ($serie <= 0 || $numeroInicial <= 0 || $numeroFinal <= 0 || $justificativa === '') {
            throw new FiscalException(
                'Inutilização exige série, número inicial, número final e justificativa.',
                'INUTILIZE_REQUIRED_FIELDS',
                [
                    'required_fields' => ['serie', 'numero_inicial', 'numero_final', 'justificativa'],
                ],
                422
            );
        }

        return $this->adapter->inutilize(
            $context,
            $serie,
            $numeroInicial,
            $numeroFinal,
            $justificativa,
            isset($payload['ano']) ? (string) $payload['ano'] : null
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function cadastro(array $payload, int $model): array
    {
        $context = $this->contextResolver->resolve($payload, $model);
        $this->certificateService->assertAvailable($context);

        $uf = strtoupper((string) ($payload['uf_consulta'] ?? $payload['uf'] ?? $context['uf']));
        $cnpj = preg_replace('/\D/', '', (string) ($payload['cnpj'] ?? ''));
        $ie = preg_replace('/\D/', '', (string) ($payload['ie'] ?? ''));
        $cpf = preg_replace('/\D/', '', (string) ($payload['cpf'] ?? ''));

        if ($cnpj === '' && $ie === '' && $cpf === '') {
            throw new FiscalException(
                'Consulta cadastro exige CNPJ, IE ou CPF.',
                'CADASTRO_FILTER_REQUIRED',
                ['required_fields' => ['cnpj', 'ie', 'cpf']],
                422
            );
        }

        return $this->adapter->cadastro($context, $uf, $cnpj, $ie, $cpf);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function danfe(array $payload, int $model, string $chave): array
    {
        $xml = (string) ($payload['xml'] ?? '');
        if ($xml === '') {
            throw new FiscalException(
                'Para gerar DANFE neste esqueleto é necessário enviar o XML autorizado no corpo da requisição.',
                'AUTHORIZED_XML_REQUIRED',
                ['required_field' => 'xml', 'chave' => $chave],
                422
            );
        }

        return $this->danfeService->generate($xml);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function qrCode(array $payload): array
    {
        $signed = $this->sign($payload, 65);

        return [
            'qrcode' => $signed['qrcode'],
            'url_chave' => $signed['url_chave'],
            'xml' => $signed['xml'],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $context
     */
    private function resolveXmlForSignOrSend(array $payload, array $context): string
    {
        $xml = (string) ($payload['xml'] ?? '');
        if ($xml !== '') {
            return $xml;
        }

        $this->assertCreatePayload($payload, (int) $context['modelo']);
        return $this->adapter->createUnsignedXml($context, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assertCreatePayload(array $payload, int $model): void
    {
        $required = ['emitente', 'ide', 'produtos', 'totais'];
        foreach ($required as $field) {
            if (empty($payload[$field]) || !is_array($payload[$field])) {
                throw new FiscalException(
                    'Payload incompleto para criação do documento fiscal.',
                    'DOCUMENT_PAYLOAD_INVALID',
                    ['required_field' => $field],
                    422
                );
            }
        }

        if (!is_array($payload['produtos']) || $payload['produtos'] === []) {
            throw new FiscalException(
                'É necessário informar ao menos um produto.',
                'PRODUCTS_REQUIRED',
                ['required_field' => 'produtos'],
                422
            );
        }

        foreach (array_values($payload['produtos']) as $index => $product) {
            if (!is_array($product)) {
                throw new FiscalException(
                    'Cada produto deve ser um objeto.',
                    'PRODUCT_FORMAT_INVALID',
                    ['index' => $index],
                    422
                );
            }

            foreach (['cProd', 'xProd', 'NCM', 'CFOP', 'uCom', 'qCom', 'vUnCom', 'vProd'] as $field) {
                if (!array_key_exists($field, $product)) {
                    throw new FiscalException(
                        'Produto sem campos obrigatórios.',
                        'PRODUCT_REQUIRED_FIELDS',
                        ['index' => $index, 'required_field' => $field],
                        422
                    );
                }
            }

            $tax = is_array($product['imposto'] ?? null) ? $product['imposto'] : [];
            foreach (['icms', 'pis', 'cofins'] as $taxField) {
                if (empty($tax[$taxField]) || !is_array($tax[$taxField])) {
                    throw new FiscalException(
                        'Produto sem impostos obrigatórios.',
                        'PRODUCT_TAX_REQUIRED',
                        ['index' => $index, 'required_field' => "produtos.$index.imposto.$taxField"],
                        422
                    );
                }
            }
        }

        $totais = is_array($payload['totais'] ?? null) ? $payload['totais'] : [];
        if (empty($totais['icmsTot']) || !is_array($totais['icmsTot'])) {
            throw new FiscalException(
                'Totais do documento são obrigatórios.',
                'TOTALS_REQUIRED',
                ['required_field' => 'totais.icmsTot'],
                422
            );
        }

        if ($model === 65) {
            $pagamento = is_array($payload['pagamento'] ?? null) ? $payload['pagamento'] : [];
            if (empty($pagamento['detalhes']) || !is_array($pagamento['detalhes'])) {
                throw new FiscalException(
                    'NFC-e exige detalhamento de pagamento.',
                    'NFCE_PAYMENT_REQUIRED',
                    ['required_field' => 'pagamento.detalhes'],
                    422
                );
            }
        }
    }
}
