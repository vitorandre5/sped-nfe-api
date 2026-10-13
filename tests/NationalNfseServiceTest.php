<?php

declare(strict_types=1);

namespace NFePHP\NFe\Tests;

use App\Exceptions\FiscalException;
use App\Services\Fiscal\NationalNfseService;
use PHPUnit\Framework\TestCase;

final class NationalNfseServiceTest extends TestCase
{
    public function testDecodesDocumentsAndAdvancesNsu(): void
    {
        $xml = '<NFSe xmlns="http://www.sped.fazenda.gov.br/nfse" versao="1.00"/>';
        $result = (new NationalNfseService())->decodeResponse([
            'TipoAmbiente' => 'PRODUCAO', 'StatusProcessamento' => 'DOCUMENTOS_LOCALIZADOS',
            'LoteDFe' => [['NSU' => 51, 'ChaveAcesso' => str_repeat('1', 50), 'TipoDocumento' => 'NFSE', 'ArquivoXml' => base64_encode(gzencode($xml))]],
        ], '000000000000050', 1);
        self::assertSame('000000000000051', $result['ult_nsu']);
        self::assertSame($xml, $result['documentos'][0]['xml']);
        self::assertFalse($result['has_more']);
    }

    public function testEmptyMailboxPreservesCursor(): void
    {
        $result = (new NationalNfseService())->decodeResponse(['TipoAmbiente' => 'PRODUCAO', 'StatusProcessamento' => 'NENHUM_DOCUMENTO_LOCALIZADO', 'LoteDFe' => []], '000000000000123', 1);
        self::assertSame('137', $result['cStat']);
        self::assertSame('000000000000123', $result['ult_nsu']);
    }

    public function testRejectsAnUnexpectedEnvironment(): void
    {
        $this->expectException(FiscalException::class);
        (new NationalNfseService())->decodeResponse(['TipoAmbiente' => 'HOMOLOGACAO', 'StatusProcessamento' => 'NENHUM_DOCUMENTO_LOCALIZADO'], '0', 1);
    }

    public function testRejectsCorruptGzip(): void
    {
        $this->expectException(FiscalException::class);
        (new NationalNfseService())->decodeResponse(['TipoAmbiente' => 'PRODUCAO', 'StatusProcessamento' => 'DOCUMENTOS_LOCALIZADOS', 'LoteDFe' => [['NSU' => 1, 'ArquivoXml' => 'bad']]], '0', 1);
    }

    public function testRejectsRepeatedNsuInsteadOfLooping(): void
    {
        $this->expectException(FiscalException::class);
        (new NationalNfseService())->decodeResponse(['TipoAmbiente' => 'PRODUCAO', 'StatusProcessamento' => 'DOCUMENTOS_LOCALIZADOS', 'LoteDFe' => [['NSU' => 50, 'ArquivoXml' => base64_encode(gzencode('<xml/>'))]]], '000000000000050', 1);
    }
}
