<?php

namespace NFePHP\NFe\Traits;

use NFePHP\Common\DOMImproved as Dom;
use stdClass;
use DOMElement;

/**
 * @property Dom $dom
 * @property DOMElement $entrega
 * @method equilizeParameters($std, $possible)
 */
trait TraitTagEntrega
{
    /**
     * Identificação do Local de entrega G01 pai A01
     * tag NFe/infNFe/entrega (opcional)
     * NOTA: ajustado para NT 2018.005
     */
    public function tagentrega(stdClass $std): DOMElement
    {
        $possible = [
            'xLgr',
            'nro',
            'xCpl',
            'xBairro',
            'cMun',
            'xMun',
            'UF',
            'CNPJ',
            'CPF',
            'xNome',
            'CEP',
            'cPais',
            'xPais',
            'fone',
            'email',
            'IE'
        ];
        $std = $this->equilizeParameters($std, $possible);
        $identificador = 'G01 entrega -';
        $this->entrega = $this->dom->createElement("entrega");
        if (!empty($std->CPF)) {
            $this->dom->addChild(
                $this->entrega,
                "CPF",
                $std->CPF,
                false,
                $identificador . "CPF do Cliente da Entrega"
            );
        } else {
            $this->dom->addChild(
                $this->entrega,
                "CNPJ",
                $std->CNPJ ?? '',
                true,
                $identificador . "CNPJ do Cliente da Entrega"
            );
        }
        $this->dom->addChild(
            $this->entrega,
            "xNome",
            $std->xNome,
            false,
            $identificador . "Nome do Cliente da Entrega"
        );
        $this->dom->addChild(
            $this->entrega,
            "xLgr",
            $std->xLgr,
            true,
            $identificador . "Logradouro do Endereço do Cliente da Entrega"
        );
        $this->dom->addChild(
            $this->entrega,
            "nro",
            $std->nro,
            true,
            $identificador . "Número do Endereço do Cliente da Entrega"
        );
        $this->dom->addChild(
            $this->entrega,
            "xCpl",
            $std->xCpl,
            false,
            $identificador . "Complemento do Endereço do Cliente da Entrega"
        );
        $this->dom->addChild(
            $this->entrega,
            "xBairro",
            $std->xBairro,
            true,
            $identificador . "Bairro do Endereço do Cliente da Entrega"
        );
        $this->dom->addChild(
            $this->entrega,
            "cMun",
            $std->cMun,
            true,
            $identificador . "Código do município do Endereço do Cliente da Entrega"
        );
        $this->dom->addChild(
            $this->entrega,
            "xMun",
            $std->xMun,
            true,
            $identificador . "Nome do município do Endereço do Cliente da Entrega"
        );
        $this->dom->addChild(
            $this->entrega,
            "UF",
            $std->UF,
            true,
            $identificador . "Sigla da UF do Endereço do Cliente da Entrega"
        );
        $this->dom->addChild(
            $this->entrega,
            "CEP",
            $std->CEP,
            false,
            $identificador . "CEP do Endereço do Cliente da Entrega"
        );
        $this->dom->addChild(
            $this->entrega,
            "cPais",
            $std->cPais,
            false,
            $identificador . "Código do País do Endereço do Cliente da Entrega"
        );
        $this->dom->addChild(
            $this->entrega,
            "xPais",
            $std->xPais,
            false,
            $identificador . "País do Endereço do Cliente da Entrega"
        );
        $this->dom->addChild(
            $this->entrega,
            "fone",
            $std->fone,
            false,
            $identificador . "Fone do Endereço do Cliente da Entrega"
        );
        $this->dom->addChild(
            $this->entrega,
            "email",
            $std->email,
            false,
            $identificador . "Email do Endereço do Cliente da Entrega"
        );
        $this->dom->addChild(
            $this->entrega,
            "IE",
            $std->IE,
            false,
            $identificador . "IE do Cliente da Entrega"
        );
        return $this->entrega;
    }
}
