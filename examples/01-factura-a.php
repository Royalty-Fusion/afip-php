<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use RoyaltyFusion\Afip\Calculator\InvoiceCalculator;
use RoyaltyFusion\Afip\Catalog\AlicuotaIva;
use RoyaltyFusion\Afip\Catalog\Concepto;
use RoyaltyFusion\Afip\Catalog\CondicionIva;
use RoyaltyFusion\Afip\Catalog\TipoComprobante;
use RoyaltyFusion\Afip\Catalog\TipoDocumento;
use RoyaltyFusion\Afip\Exception\ValidationException;
use RoyaltyFusion\Afip\Model\Client;
use RoyaltyFusion\Afip\Model\Sale\Invoice;
use RoyaltyFusion\Afip\Model\Sale\SaleDetail;

$see = see();

$client = (new Client())
    ->setTipoDoc(TipoDocumento::CUIT)
    ->setNumDoc('30500010912')
    ->setRazonSocial('BANCO DE LA NACION ARGENTINA')
    ->setCondicionIva(CondicionIva::RESPONSABLE_INSCRIPTO);

$invoice = (new Invoice())
    ->setTipoComprobante(TipoComprobante::FACTURA_A)
    ->setPuntoVenta(1)
    ->setConcepto(Concepto::PRODUCTOS)
    ->setCompany(company($see))
    ->setClient($client)
    ->addDetail((new SaleDetail())->setCodigo('NB-01')->setDescripcion('Notebook 15"')->setCantidad(2)->setPrecioUnitario(850000)->setAlicuotaIva(AlicuotaIva::IVA_21))
    ->addDetail((new SaleDetail())->setCodigo('LB-02')->setDescripcion('Libro técnico')->setCantidad(3)->setPrecioUnitario(25000)->setAlicuotaIva(AlicuotaIva::IVA_10_5));

// Calcula ImpNeto, ImpIVA, alícuotas y total a partir de los ítems.
(new InvoiceCalculator())->calculate($invoice);

try {
    $result = $see->send($invoice); // valida, numera (último + 1) y solicita el CAE
    show($result);
    echo 'Comprobante: ', $invoice->getNumeroCompleto(), PHP_EOL;
} catch (ValidationException $e) {
    foreach ($e->getResult()->getErrors() as $error) {
        echo '- ', $error, PHP_EOL;
    }
}
