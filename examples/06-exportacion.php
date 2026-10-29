<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use RoyaltyFusion\Afip\Catalog\Exportacion;
use RoyaltyFusion\Afip\Catalog\TipoComprobante;
use RoyaltyFusion\Afip\Model\Export\ExportInvoice;
use RoyaltyFusion\Afip\Model\Export\ExportItem;

$see = see();
$wsfex = $see->getWsfex();

// Cotización oficial y CUIT genérico del país del cliente.
$cotizacion = $wsfex->getQuotation('DOL')['cotizacion'];

$invoice = (new ExportInvoice())
    ->setTipoComprobante(TipoComprobante::FACTURA_EXPORTACION)
    ->setPuntoVenta(3)
    ->setTipoExportacion(Exportacion::EXPORTACION_BIENES)
    ->setPermisoExistente('N')
    ->setPaisDestino(222) // Perú (FEXGetPARAM_DST_pais)
    ->setCompany(company($see))
    ->setCliente('IMPORTADORA DEL PERU SAC')
    ->setCuitPaisCliente('50000000016') // FEXGetPARAM_DST_CUIT
    ->setIdImpositivo('20123456789')
    ->setDomicilioCliente('Av. Arequipa 123, Lima, Perú')
    ->setMoneda('DOL', $cotizacion)
    ->setPagoMismaMoneda(true)
    ->setIncoterms('FOB')
    ->setFormaPago('Transferencia bancaria')
    ->setIdioma(Exportacion::IDIOMA_ESPANOL)
    ->addItem((new ExportItem())->setCodigo('MAL-750')->setDescripcion('Vino Malbec 750ml')->setCantidad(1200)->setUnidad(7)->setPrecioUnitario(6.5));

$result = $see->sendExport($invoice); // asigna Id y número automáticamente
show($result);
