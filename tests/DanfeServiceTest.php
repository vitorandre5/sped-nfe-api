<?php

declare(strict_types=1);

namespace NFePHP\NFe\Tests;

use App\Exceptions\FiscalException;
use App\Services\Fiscal\DanfeService;
use PHPUnit\Framework\TestCase;

final class DanfeServiceTest extends TestCase
{
    public function testExplainsWhyASeFazSummaryCannotProduceDanfe(): void
    {
        $xml = '<resNFe xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.01">'
            . '<chNFe>35130611290482000188550010000065961003608500</chNFe>'
            . '<CNPJ>11290482000188</CNPJ><xNome>Empresa Teste</xNome>'
            . '<vNF>994.89</vNF></resNFe>';

        try {
            (new DanfeService())->generate($xml, 'nfe55');
            self::fail('A summary-only NF-e must not be rendered as a DANFE.');
        } catch (FiscalException $exception) {
            self::assertSame('NFE_SUMMARY_ONLY', $exception->errorCode());
            self::assertSame('O documento ainda não está disponível para visualização.', $exception->getMessage());
        }
    }
}
