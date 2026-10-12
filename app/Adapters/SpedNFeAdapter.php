<?php

declare(strict_types=1);

namespace App\Adapters;

use App\Exceptions\FiscalException;
use DOMDocument;
use NFePHP\Common\Certificate;
use NFePHP\Common\UFList;
use NFePHP\NFe\Common\Standardize;
use NFePHP\NFe\Complements;
use NFePHP\NFe\Make;
use NFePHP\NFe\Tools;

final class SpedNFeAdapter
{
    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $payload
     */
    public function createUnsignedXml(array $context, array $payload): string
    {
        $ide = is_array($payload['ide'] ?? null) ? $payload['ide'] : [];
        $dest = is_array($payload['destinatario'] ?? null) ? $payload['destinatario'] : [];
        $products = is_array($payload['produtos'] ?? null) ? $payload['produtos'] : [];
        $totais = is_array($payload['totais'] ?? null) ? $payload['totais'] : [];
        $transporte = is_array($payload['transporte'] ?? null) ? $payload['transporte'] : [];
        $pagamento = is_array($payload['pagamento'] ?? null) ? $payload['pagamento'] : [];
        $cobranca = is_array($payload['cobranca'] ?? null) ? $payload['cobranca'] : [];
        $infAdic = is_array($payload['informacoes_adicionais'] ?? null) ? $payload['informacoes_adicionais'] : [];
        $respTec = is_array($payload['responsavel_tecnico'] ?? null) ? $payload['responsavel_tecnico'] : [];
        $make = new Make();

        $make->taginfNFe((object) [
            'versao' => $context['config']['versao'],
            'Id' => null,
        ]);

        $emitente = $context['emitente'];
        $enderEmit = $emitente['enderEmit'];

        $make->tagide((object) [
            'cUF' => UFList::getCodeByUF($context['uf']),
            'cNF' => $ide['cNF'] ?? str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'natOp' => $ide['natOp'] ?? 'VENDA',
            'mod' => $context['modelo'],
            'serie' => (int) ($payload['serie'] ?? $ide['serie'] ?? 1),
            'nNF' => (int) ($payload['numero'] ?? $ide['nNF'] ?? 1),
            'dhEmi' => $ide['dhEmi'] ?? date('c'),
            'dhSaiEnt' => $ide['dhSaiEnt'] ?? null,
            'tpNF' => (int) ($ide['tpNF'] ?? 1),
            'idDest' => (int) ($ide['idDest'] ?? 1),
            'cMunFG' => $ide['cMunFG'] ?? $enderEmit['cMun'] ?? null,
            'tpImp' => (int) ($ide['tpImp'] ?? ($context['modelo'] === 65 ? 4 : 1)),
            'tpEmis' => (int) ($ide['tpEmis'] ?? 1),
            'tpAmb' => $context['tpAmb'],
            'finNFe' => (int) ($ide['finNFe'] ?? 1),
            'indFinal' => (int) ($ide['indFinal'] ?? ($context['modelo'] === 65 ? 1 : 0)),
            'indPres' => (int) ($ide['indPres'] ?? ($context['modelo'] === 65 ? 1 : 0)),
            'procEmi' => (int) ($ide['procEmi'] ?? 0),
            'verProc' => (string) ($ide['verProc'] ?? 'app-fiscal-bridge-1.0'),
        ]);

        $make->tagemit((object) array_filter([
            'xNome' => $emitente['xNome'],
            'xFant' => $emitente['xFant'],
            'IE' => $emitente['IE'],
            'IEST' => $emitente['IEST'],
            'IM' => $emitente['IM'],
            'CNAE' => $emitente['CNAE'],
            'CRT' => $emitente['CRT'],
            'CNPJ' => $emitente['CNPJ'],
            'CPF' => $emitente['CPF'],
        ], static fn ($value) => $value !== null && $value !== ''));

        $make->tagenderEmit((object) array_filter($enderEmit, static fn ($value) => $value !== null && $value !== ''));

        if (!empty($dest)) {
            $make->tagdest((object) array_filter([
                'xNome' => $dest['xNome'] ?? $dest['nome'] ?? null,
                'indIEDest' => $dest['indIEDest'] ?? 9,
                'IE' => $dest['IE'] ?? $dest['ie'] ?? null,
                'ISUF' => $dest['ISUF'] ?? null,
                'IM' => $dest['IM'] ?? null,
                'email' => $dest['email'] ?? null,
                'CNPJ' => isset($dest['cnpj']) ? preg_replace('/\D/', '', (string) $dest['cnpj']) : null,
                'CPF' => isset($dest['cpf']) ? preg_replace('/\D/', '', (string) $dest['cpf']) : null,
                'idEstrangeiro' => $dest['idEstrangeiro'] ?? null,
            ], static fn ($value) => $value !== null && $value !== ''));

            $enderDest = is_array($dest['endereco'] ?? null) ? $dest['endereco'] : [];
            if (!empty($enderDest)) {
                $make->tagenderDest((object) array_filter([
                    'xLgr' => $enderDest['xLgr'] ?? $enderDest['logradouro'] ?? null,
                    'nro' => $enderDest['nro'] ?? $enderDest['numero'] ?? null,
                    'xCpl' => $enderDest['xCpl'] ?? $enderDest['complemento'] ?? null,
                    'xBairro' => $enderDest['xBairro'] ?? $enderDest['bairro'] ?? null,
                    'cMun' => $enderDest['cMun'] ?? $enderDest['codigo_municipio'] ?? null,
                    'xMun' => $enderDest['xMun'] ?? $enderDest['municipio'] ?? null,
                    'UF' => $enderDest['UF'] ?? $enderDest['uf'] ?? null,
                    'CEP' => isset($enderDest['CEP']) ? preg_replace('/\D/', '', (string) $enderDest['CEP']) : (isset($enderDest['cep']) ? preg_replace('/\D/', '', (string) $enderDest['cep']) : null),
                    'cPais' => $enderDest['cPais'] ?? '1058',
                    'xPais' => $enderDest['xPais'] ?? 'BRASIL',
                    'fone' => isset($enderDest['fone']) ? preg_replace('/\D/', '', (string) $enderDest['fone']) : (isset($enderDest['telefone']) ? preg_replace('/\D/', '', (string) $enderDest['telefone']) : null),
                ], static fn ($value) => $value !== null && $value !== ''));
            }
        }

        foreach (array_values($products) as $index => $product) {
            if (!is_array($product)) {
                continue;
            }

            $item = $index + 1;
            $make->tagprod((object) array_filter([
                'item' => $item,
                'cProd' => $product['cProd'] ?? null,
                'cEAN' => $product['cEAN'] ?? 'SEM GTIN',
                'xProd' => $product['xProd'] ?? null,
                'NCM' => $product['NCM'] ?? null,
                'CFOP' => $product['CFOP'] ?? null,
                'uCom' => $product['uCom'] ?? null,
                'qCom' => $product['qCom'] ?? null,
                'vUnCom' => $product['vUnCom'] ?? null,
                'vProd' => $product['vProd'] ?? null,
                'cEANTrib' => $product['cEANTrib'] ?? ($product['cEAN'] ?? 'SEM GTIN'),
                'uTrib' => $product['uTrib'] ?? ($product['uCom'] ?? null),
                'qTrib' => $product['qTrib'] ?? ($product['qCom'] ?? null),
                'vUnTrib' => $product['vUnTrib'] ?? ($product['vUnCom'] ?? null),
                'vFrete' => $product['vFrete'] ?? null,
                'vSeg' => $product['vSeg'] ?? null,
                'vDesc' => $product['vDesc'] ?? null,
                'vOutro' => $product['vOutro'] ?? null,
                'indTot' => $product['indTot'] ?? 1,
                'CEST' => $product['CEST'] ?? null,
            ], static fn ($value) => $value !== null && $value !== ''));

            $imposto = is_array($product['imposto'] ?? null) ? $product['imposto'] : [];
            $make->tagimposto((object) [
                'item' => $item,
                'vTotTrib' => $imposto['vTotTrib'] ?? null,
            ]);

            if (!empty($imposto['icms']) && is_array($imposto['icms'])) {
                $icms = $imposto['icms'];
                $icms['item'] = $item;
                if (isset($icms['CSOSN'])) {
                    $make->tagICMSSN((object) $icms);
                } else {
                    $make->tagICMS((object) $icms);
                }
            }

            if (!empty($imposto['pis']) && is_array($imposto['pis'])) {
                $pis = $imposto['pis'];
                $pis['item'] = $item;
                $make->tagPIS((object) $pis);
            }

            if (!empty($imposto['cofins']) && is_array($imposto['cofins'])) {
                $cofins = $imposto['cofins'];
                $cofins['item'] = $item;
                $make->tagCOFINS((object) $cofins);
            }

            if (!empty($imposto['ipi']) && is_array($imposto['ipi'])) {
                $ipi = $imposto['ipi'];
                $ipi['item'] = $item;
                $make->tagIPI((object) $ipi);
            }
        }

        if (!empty($totais['icmsTot']) && is_array($totais['icmsTot'])) {
            $make->tagICMSTot((object) $totais['icmsTot']);
        }

        if (!empty($transporte)) {
            $make->tagtransp((object) [
                'modFrete' => $transporte['modFrete'] ?? 9,
            ]);

            if (!empty($transporte['transportadora']) && is_array($transporte['transportadora'])) {
                $carrier = $transporte['transportadora'];
                $make->tagtransporta((object) array_filter([
                    'xNome' => $carrier['xNome'] ?? null,
                    'IE' => $carrier['IE'] ?? null,
                    'xEnder' => $carrier['xEnder'] ?? null,
                    'xMun' => $carrier['xMun'] ?? null,
                    'UF' => $carrier['UF'] ?? null,
                    'CNPJ' => isset($carrier['cnpj']) ? preg_replace('/\D/', '', (string) $carrier['cnpj']) : null,
                    'CPF' => isset($carrier['cpf']) ? preg_replace('/\D/', '', (string) $carrier['cpf']) : null,
                ], static fn ($value) => $value !== null && $value !== ''));
            }

            foreach ($transporte['volumes'] ?? [] as $volumeIndex => $volume) {
                if (!is_array($volume)) {
                    continue;
                }
                $make->tagvol((object) array_filter([
                    'item' => $volumeIndex + 1,
                    'qVol' => $volume['qVol'] ?? null,
                    'esp' => $volume['esp'] ?? null,
                    'marca' => $volume['marca'] ?? null,
                    'nVol' => $volume['nVol'] ?? null,
                    'pesoL' => $volume['pesoL'] ?? null,
                    'pesoB' => $volume['pesoB'] ?? null,
                ], static fn ($value) => $value !== null && $value !== ''));
            }
        } else {
            $make->tagtransp((object) ['modFrete' => 9]);
        }

        if (!empty($pagamento)) {
            if (array_key_exists('vTroco', $pagamento)) {
                $make->tagpag((object) ['vTroco' => $pagamento['vTroco']]);
            }

            foreach ($pagamento['detalhes'] ?? [] as $detalhe) {
                if (!is_array($detalhe)) {
                    continue;
                }
                $make->tagdetPag((object) array_filter([
                    'indPag' => $detalhe['indPag'] ?? null,
                    'tPag' => $detalhe['tPag'] ?? null,
                    'xPag' => $detalhe['xPag'] ?? null,
                    'vPag' => $detalhe['vPag'] ?? null,
                    'CNPJ' => isset($detalhe['cnpj']) ? preg_replace('/\D/', '', (string) $detalhe['cnpj']) : null,
                    'tBand' => $detalhe['tBand'] ?? null,
                    'cAut' => $detalhe['cAut'] ?? null,
                    'tpIntegra' => $detalhe['tpIntegra'] ?? null,
                ], static fn ($value) => $value !== null && $value !== ''));
            }
        }

        if (!empty($cobranca['fat']) && is_array($cobranca['fat'])) {
            $make->tagfat((object) $cobranca['fat']);
        }

        foreach ($cobranca['duplicatas'] ?? [] as $duplicata) {
            if (!is_array($duplicata)) {
                continue;
            }
            $make->tagdup((object) $duplicata);
        }

        if (!empty($infAdic)) {
            $make->taginfAdic((object) array_filter([
                'infAdFisco' => $infAdic['infAdFisco'] ?? null,
                'infCpl' => $infAdic['infCpl'] ?? null,
            ], static fn ($value) => $value !== null && $value !== ''));
        }

        if (!empty($respTec)) {
            $make->taginfRespTec((object) array_filter([
                'CNPJ' => isset($respTec['cnpj']) ? preg_replace('/\D/', '', (string) $respTec['cnpj']) : null,
                'xContato' => $respTec['xContato'] ?? null,
                'email' => $respTec['email'] ?? null,
                'fone' => isset($respTec['fone']) ? preg_replace('/\D/', '', (string) $respTec['fone']) : null,
            ], static fn ($value) => $value !== null && $value !== ''));
        }

        $make->montaNFe();
        $xml = $make->getXML();
        $errors = $make->getErrors();

        if ($xml === '' || !empty($errors)) {
            throw new FiscalException(
                'Falha ao montar o XML fiscal.',
                'XML_BUILD_ERROR',
                ['errors' => $errors],
                422
            );
        }

        return $xml;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function validateCertificate(array $context): array
    {
        $certificate = $this->readCertificate($context);
        $pfx = Certificate::readPfx($certificate['content'], $certificate['password']);

        return [
            'subject' => $pfx->publicKey->commonName ?? null,
            'validFrom' => $pfx->publicKey->validFrom ?? null,
            'validTo' => $pfx->publicKey->validTo ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    public function signXml(array $context, string $xml): string
    {
        return $this->tools($context)->signNFe($xml);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function validateXml(array $context, string $xml): bool
    {
        return $this->tools($context)->sefazValidate($xml);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function sendXml(array $context, string $xml, ?string $lote = null, int $sincrono = 1): array
    {
        $responseXml = $this->tools($context)->sefazEnviaLote([$xml], $lote ?? date('His'), $sincrono);
        $standardized = $this->toArray($responseXml);
        $authorizedXml = null;

        if (($standardized['cStat'] ?? null) === '104') {
            $authorizedXml = Complements::toAuthorize($xml, $responseXml);
        }

        return [
            'response_xml' => $responseXml,
            'response' => $standardized,
            'authorized_xml' => $authorizedXml,
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function consultByKey(array $context, string $chave): array
    {
        $xml = $this->tools($context)->sefazConsultaChave($chave, $context['tpAmb']);
        return ['response_xml' => $xml, 'response' => $this->toArray($xml)];
    }

    /**
     * Consulta um lote do Ambiente Nacional por NSU e descompacta os documentos
     * devolvidos pela SEFAZ. Não há filtro de data nesse webservice.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function distributeNFe(array $context, int $ultNsu): array
    {
        $responseXml = $this->tools($context)->sefazDistDFe($ultNsu);
        $response = $this->toArray($responseXml);
        $documents = [];

        $dom = new DOMDocument('1.0', 'UTF-8');
        if ($dom->loadXML($responseXml, LIBXML_NONET | LIBXML_NOBLANKS) !== false) {
            foreach ($dom->getElementsByTagName('docZip') as $docZip) {
                $encoded = trim((string) $docZip->nodeValue);
                $decoded = base64_decode($encoded, true);
                $xml = $decoded === false ? false : gzdecode($decoded, 20 * 1024 * 1024);

                $documents[] = [
                    'nsu' => $docZip->getAttribute('NSU'),
                    'schema' => $docZip->getAttribute('schema'),
                    'xml' => $xml === false ? null : $xml,
                    'decode_error' => $xml === false ? 'Não foi possível descompactar o documento retornado.' : null,
                ];
            }
        }

        return [
            'cStat' => $response['cStat'] ?? null,
            'xMotivo' => $response['xMotivo'] ?? null,
            'ult_nsu' => $response['ultNSU'] ?? str_pad((string) $ultNsu, 15, '0', STR_PAD_LEFT),
            'max_nsu' => $response['maxNSU'] ?? null,
            'documentos' => $documents,
            'response_xml' => $responseXml,
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function consultReceipt(array $context, string $recibo): array
    {
        $xml = $this->tools($context)->sefazConsultaRecibo($recibo, $context['tpAmb']);
        return ['response_xml' => $xml, 'response' => $this->toArray($xml)];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function serviceStatus(array $context): array
    {
        $xml = $this->tools($context)->sefazStatus($context['uf'], $context['tpAmb']);
        return ['response_xml' => $xml, 'response' => $this->toArray($xml)];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function cancel(array $context, string $chave, string $protocolo, string $justificativa): array
    {
        $xml = $this->tools($context)->sefazCancela($chave, $justificativa, $protocolo);
        return ['response_xml' => $xml, 'response' => $this->toArray($xml)];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function inutilize(
        array $context,
        int $serie,
        int $numeroInicial,
        int $numeroFinal,
        string $justificativa,
        ?string $ano = null
    ): array {
        $xml = $this->tools($context)->sefazInutiliza(
            $serie,
            $numeroInicial,
            $numeroFinal,
            $justificativa,
            $context['tpAmb'],
            $ano
        );

        return ['response_xml' => $xml, 'response' => $this->toArray($xml)];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function cadastro(array $context, string $uf, string $cnpj = '', string $ie = '', string $cpf = ''): array
    {
        $xml = $this->tools($context)->sefazCadastro($uf, $cnpj, $ie, $cpf);
        return ['response_xml' => $xml, 'response' => $this->toArray($xml)];
    }

    /**
     * @return array<string, mixed>
     */
    public function extractQrCode(string $xml): array
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->loadXML($xml);

        return [
            'qrCode' => $dom->getElementsByTagName('qrCode')->item(0)
                ? $dom->getElementsByTagName('qrCode')->item(0)->nodeValue
                : null,
            'urlChave' => $dom->getElementsByTagName('urlChave')->item(0)
                ? $dom->getElementsByTagName('urlChave')->item(0)->nodeValue
                : null,
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function tools(array $context): Tools
    {
        $certificate = $this->readCertificate($context);
        $tools = new Tools(
            json_encode($context['config'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            Certificate::readPfx($certificate['content'], $certificate['password'])
        );
        $tools->model((int) $context['modelo']);

        $verAplic = getenv('NFE_VERAPLIC');
        if ($verAplic !== false && $verAplic !== '') {
            $tools->setVerAplic($verAplic);
        }

        return $tools;
    }

    /**
     * @param array<string, mixed> $context
     * @return array{content:string,password:string}
     */
    private function readCertificate(array $context): array
    {
        $certificate = $context['certificado'] ?? [];
        $password = (string) ($certificate['password'] ?? '');
        $base64 = $certificate['base64'] ?? null;
        $path = $certificate['path'] ?? null;

        if (!empty($base64)) {
            $decoded = base64_decode((string) $base64, true);
            if ($decoded === false) {
                throw new FiscalException(
                    'Conteúdo base64 do certificado inválido.',
                    'CERTIFICATE_BASE64_INVALID',
                    [],
                    422
                );
            }

            return ['content' => $decoded, 'password' => $password];
        }

        $content = @file_get_contents((string) $path);
        if ($content === false) {
            throw new FiscalException(
                'Falha ao ler o arquivo do certificado.',
                'CERTIFICATE_READ_ERROR',
                ['path' => $path],
                422
            );
        }

        return ['content' => $content, 'password' => $password];
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(string $xml): array
    {
        $standard = new Standardize($xml);
        $data = $standard->toStd();
        return json_decode(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true) ?? [];
    }
}
