<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use RoyaltyFusion\Afip\Calculator\InvoiceCalculator;
use RoyaltyFusion\Afip\Catalog\AlicuotaIva;
use RoyaltyFusion\Afip\Catalog\CondicionIva;
use RoyaltyFusion\Afip\Catalog\TipoComprobante;
use RoyaltyFusion\Afip\Catalog\TipoDocumento;
use RoyaltyFusion\Afip\Model\Client;
use RoyaltyFusion\Afip\Model\Sale\Invoice;
use RoyaltyFusion\Afip\Model\Sale\SaleDetail;

/*
 * WSMTXCA: factura con detalle de ítems informado a ARCA (clases A, B y M).
 * Requiere el servicio "wsmtxca" asociado al certificado.
 */
$see = see();

$invoice = (new Invoice())
    ->setTipoComprobante(TipoComprobante::FACTURA_A)
    ->setPuntoVenta(1)
    ->setCompany(company($see))
    ->setClient((new Client())->setTipoDoc(TipoDocumento::CUIT)->setNumDoc('30500010912')->setRazonSocial('CLIENTE S.A.')->setCondicionIva(CondicionIva::RESPONSABLE_INSCRIPTO))
    ->addDetail((new SaleDetail())
        ->setCodigo('NB-15')->setCodigoMtx('7790001000012')->setUnidadesMtx(1) // GTIN / código de barras
        ->setDescripcion('Notebook 15"')->setCantidad(2)->setCodigoUnidadMedida(7)
        ->setPrecioUnitario(850000)->setAlicuotaIva(AlicuotaIva::IVA_21))
    ->addDetail((new SaleDetail())->setDescripcion('Libro')->setCantidad(1)->setPrecioUnitario(25000)->setAlicuotaIva(AlicuotaIva::IVA_10_5));

// Detalle por ítem: calcula el IVA y el importe de cada ítem y arma los subtotales.
(new InvoiceCalculator())->calculate($invoice, true);

show($see->sendMtxca($invoice));
