<?php

declare(strict_types=1);

namespace NFePHP\NFe\Tests;

use App\Exceptions\FiscalException;
use App\Services\Fiscal\NfseXmlParser;
use PHPUnit\Framework\TestCase;

final class NfseXmlParserTest extends TestCase
{
    public function testParsesHistoricalMeiNoteWithSha256AndDescriptionWhitespace(): void
    {
        $source = $this->source();
        $original = $source['xml'];
        $result = (new NfseXmlParser())->parse($source, '12345678000195');
        self::assertSame('123', $result['document_number']);
        self::assertSame('32.97', $result['total_amount']);
        self::assertSame('available', $result['municipal_tax']['status']);
        self::assertSame('3550308', $result['municipal_tax']['incidence_municipality_code']);
        self::assertSame('23.08', $result['municipal_tax']['tax_base']);
        self::assertSame('4.00', $result['municipal_tax']['rate']);
        self::assertSame('0.92', $result['municipal_tax']['amount']);
        self::assertSame('1', $result['municipal_tax']['retention_type']);
        self::assertTrue($result['is_sale']);
        self::assertSame($original, $source['xml']);
        self::assertStringContainsString('<cStat>107</cStat>', $source['xml']);
        self::assertStringContainsString('rsa-sha256', $source['xml']);
    }

    public function testRejectsNoteForAnotherCompany(): void
    {
        $this->expectException(FiscalException::class);
        (new NfseXmlParser())->parse($this->source(), '00000000000000');
    }

    public function testRejectsMetadataKeyDifferentFromXmlKey(): void
    {
        $this->expectException(FiscalException::class);
        (new NfseXmlParser())->parse(array_replace($this->source(), ['access_key' => str_repeat('2', 50)]), '12345678000195');
    }

    public function testRejectsExternalEntityDeclaration(): void
    {
        $this->expectException(FiscalException::class);
        (new NfseXmlParser())->parse(array_replace($this->source(), ['xml' => '<!DOCTYPE NFSe [<!ENTITY secret SYSTEM "file:///dummy">]><NFSe>&secret;</NFSe>']), '12345678000195');
    }

    public function testRejectsMalformedAmountDespiteCompatibilityNormalization(): void
    {
        $source = $this->source();
        $source['xml'] = str_replace('<vServ>32.97</vServ>', '<vServ>invalid</vServ>', $source['xml']);
        $this->expectException(FiscalException::class);
        (new NfseXmlParser())->parse($source, '12345678000195');
    }

    public function testDistinguishesNfseWithoutMunicipalTaxDetails(): void
    {
        $source = $this->source();
        $source['xml'] = preg_replace('/<valores>.*?<\/valores>/s', '', $source['xml'], 1) ?? $source['xml'];
        $source['xml'] = preg_replace('/<tribMun>.*?<\/tribMun>/s', '', $source['xml'], 1) ?? $source['xml'];
        $result = (new NfseXmlParser())->parse($source, '12345678000195');

        self::assertSame('not_informed', $result['municipal_tax']['status']);
        self::assertNull($result['municipal_tax']['tax_base']);
        self::assertNull($result['municipal_tax']['amount']);
    }

    private function source(): array
    {
        return ['xml' => (string) file_get_contents(__DIR__ . '/fixtures/xml/nfse_synthetic_v1.00.xml'), 'access_key' => str_repeat('1', 50), 'document_kind' => 'NFSE'];
    }
}
