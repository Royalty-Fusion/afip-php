<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use RoyaltyFusion\Afip\Calculator\InvoiceCalculator;
use RoyaltyFusion\Afip\Catalog\AlicuotaIva;
use RoyaltyFusion\Afip\Catalog\CondicionIva;
use RoyaltyFusion\Afip\Catalog\TipoComprobante;
use RoyaltyFusion\Afip\Catalog\TipoDocumento;
use RoyaltyFusion\Afip\Catalog\TipoOpcional;
use RoyaltyFusion\Afip\Model\Client;
use RoyaltyFusion\Afip\Model\Sale\Invoice;
use RoyaltyFusion\Afip\Model\Sale\SaleDetail;

$see = see();

// Antes de emitir conviene verificar en wsfecred si el receptor está obligado a recibir FCE.
$invoice = (new Invoice())
    ->setTipoComprobante(TipoComprobante::FCE_FACTURA_A)
    ->setPuntoVenta(1)
    ->setCompany(company($see))
    ->setClient((new Client())->setTipoDoc(TipoDocumento::CUIT)->setNumDoc('30500010912')->setRazonSocial('GRAN EMPRESA S.A.')->setCondicionIva(CondicionIva::RESPONSABLE_INSCRIPTO))
    ->setFechaVencimientoPago(new DateTimeImmutable('+30 days'))
    ->addOpcional(TipoOpcional::FCE_CBU_EMISOR, '2850590940090418135201')
    ->addOpcional(TipoOpcional::FCE_TRANSFERENCIA, TipoOpcional::TRANSFERENCIA_SCA)
    ->addDetail((new SaleDetail())->setDescripcion('Servicio de logística')->setPrecioUnitario(5_000_000)->setAlicuotaIva(AlicuotaIva::IVA_21));

(new InvoiceCalculator())->calculate($invoice);
show($see->send($invoice));
