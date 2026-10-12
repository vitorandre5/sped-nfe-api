<?php

declare(strict_types=1);

namespace NFePHP\NFe\Tests;

use App\Services\Fiscal\FiscalDocumentXmlParser;
use PHPUnit\Framework\TestCase;

final class FiscalDocumentXmlParserTest extends TestCase
{
    public function testParsesAndClassifiesAnAlphanumericCnpjSummary(): void
    {
        $xml = '<resNFe xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.01">'
            . '<chNFe>35130611290482000188550010000065961003608500</chNFe>'
            . '<CNPJ>AB123456789012</CNPJ><xNome>Empresa Teste</xNome><IE>148870628115</IE>'
            . '<dhEmi>2026-10-12T10:20:00-03:00</dhEmi><tpNF>1</tpNF><vNF>994.89</vNF>'
            . '<dhRecbto>2026-10-12T10:20:00-03:00</dhRecbto>'
            . '<nProt>135260000000000</nProt><cSitNFe>1</cSitNFe></resNFe>';

        $parsed = (new FiscalDocumentXmlParser())->parse(
            $xml,
            'resNFe_v1.01.xsd',
            'AB123456789012'
        );

        self::assertTrue($parsed['is_invoice']);
        self::assertSame('35130611290482000188550010000065961003608500', $parsed['access_key']);
        self::assertSame('AB123456789012', $parsed['issuer_cnpj']);
        self::assertSame('Empresa Teste', $parsed['issuer_name']);
        self::assertSame('994.89', $parsed['total_amount']);
        self::assertTrue($parsed['is_sale']);
    }

    public function testRejectsXmlWithDoctype(): void
    {
        $this->expectException(\App\Exceptions\FiscalException::class);

        (new FiscalDocumentXmlParser())->parse(
            '<!DOCTYPE root [<!ENTITY x "unsafe">]><root>&x;</root>',
            'resNFe_v1.01.xsd',
            '12345678000195'
        );
    }

    public function testClassifiesFiscalOperationFromFinalityNatureAndCfop(): void
    {
        $xml = (string) file_get_contents(__DIR__ . '/fixtures/xml/nfe_4.0.xml');
        $xml = str_replace('VENDA PARA CONSUMIDOR FINAL', 'Remessa para industrialização', $xml);
        $xml = str_replace('<finNFe>1</finNFe>', '<finNFe>4</finNFe>', $xml);
        $xml = str_replace('<CFOP>6108</CFOP>', '<CFOP>5901</CFOP>', $xml);
        $xml = str_replace('<CPF>014345733812</CPF>', '<CPF>01434573381</CPF>', $xml);
        $xml = preg_replace('/<infCpl>.*?<\/infCpl>/s', '<infCpl>Dados adicionais</infCpl>', $xml) ?? $xml;

        $parsed = (new FiscalDocumentXmlParser())->parse($xml, 'procNFe_v4.00.xsd', '12345678000195');

        self::assertSame(['5901'], $parsed['cfops']);
        self::assertSame(['return', 'remittance_return', 'industrialization'], $parsed['operation_categories']);
        self::assertSame('Remessa para industrialização', $parsed['operation_nature']);
    }
}
