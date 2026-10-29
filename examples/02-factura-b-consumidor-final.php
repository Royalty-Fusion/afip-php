<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use RoyaltyFusion\Afip\Calculator\InvoiceCalculator;
use RoyaltyFusion\Afip\Catalog\AlicuotaIva;
use RoyaltyFusion\Afip\Catalog\TipoComprobante;
use RoyaltyFusion\Afip\Model\Client;
use RoyaltyFusion\Afip\Model\Sale\Invoice;
use RoyaltyFusion\Afip\Model\Sale\SaleDetail;

$see = see();

// Consumidor final sin identificar (DocTipo 99, DocNro 0, condición IVA 5).
// Desde $10.000.000 (RG 5700/2025) hay que identificarlo con DNI/CUIT/CUIL.
$invoice = (new Invoice())
    ->setTipoComprobante(TipoComprobante::FACTURA_B)
    ->setPuntoVenta(1)
    ->setCompany(company($see))
    ->setClient(Client::consumidorFinal())
    ->addDetail((new SaleDetail())->setDescripcion('Gaseosa 2.25L')->setCantidad(6)->setPrecioUnitario(2500)->setAlicuotaIva(AlicuotaIva::IVA_21)->setPrecioIncluyeIva(true));

(new InvoiceCalculator())->calculate($invoice);
show($see->send($invoice));
