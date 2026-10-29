<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use RoyaltyFusion\Afip\Calculator\InvoiceCalculator;
use RoyaltyFusion\Afip\Catalog\AlicuotaIva;
use RoyaltyFusion\Afip\Catalog\CondicionIva;
use RoyaltyFusion\Afip\Catalog\TipoComprobante;
use RoyaltyFusion\Afip\Catalog\TipoDocumento;
use RoyaltyFusion\Afip\Model\Client;
use RoyaltyFusion\Afip\Model\Sale\BonoFiscalInvoice;
use RoyaltyFusion\Afip\Model\Sale\SaleDetail;

/*
 * WSBFE: Bono Fiscal Electrónico (bienes de capital). Cada ítem lleva su código NCM.
 */
$see = see();

$invoice = (new BonoFiscalInvoice())
    ->setZona(1) // BFEGetPARAM_Zonas
    ->setTipoComprobante(TipoComprobante::FACTURA_A)
    ->setPuntoVenta(5)
    ->setCompany(company($see))
    ->setClient((new Client())->setTipoDoc(TipoDocumento::CUIT)->setNumDoc('30500010912')->setCondicionIva(CondicionIva::RESPONSABLE_INSCRIPTO))
    ->addDetail((new SaleDetail())->setCodigoNcm('8459.61.00')->setDescripcion('Fresadora CNC')->setCantidad(1)
        ->setCodigoUnidadMedida(7)->setPrecioUnitario(25_000_000)->setAlicuotaIva(AlicuotaIva::IVA_10_5));

(new InvoiceCalculator())->calculate($invoice, true);
show($see->sendBfe($invoice)); // asigna Id y número automáticamente
