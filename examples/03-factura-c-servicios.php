<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use RoyaltyFusion\Afip\Calculator\InvoiceCalculator;
use RoyaltyFusion\Afip\Catalog\Concepto;
use RoyaltyFusion\Afip\Catalog\CondicionIva;
use RoyaltyFusion\Afip\Catalog\TipoComprobante;
use RoyaltyFusion\Afip\Catalog\TipoDocumento;
use RoyaltyFusion\Afip\Model\Client;
use RoyaltyFusion\Afip\Model\Sale\Invoice;
use RoyaltyFusion\Afip\Model\Sale\SaleDetail;

$see = see();
$company = company($see)->setCondicionIva(CondicionIva::MONOTRIBUTO);

$hoy = new DateTimeImmutable('today', new DateTimeZone('America/Argentina/Buenos_Aires'));
$invoice = (new Invoice())
    ->setTipoComprobante(TipoComprobante::FACTURA_C)
    ->setPuntoVenta(2)
    ->setConcepto(Concepto::SERVICIOS)
    // Servicios: período facturado y vencimiento del pago son obligatorios.
    ->setPeriodoServicio($hoy->modify('first day of this month'), $hoy->modify('last day of this month'), $hoy->modify('+10 days'))
    ->setCompany($company)
    ->setClient((new Client())->setTipoDoc(TipoDocumento::DNI)->setNumDoc('26756539')->setRazonSocial('Juan Pérez')->setCondicionIva(CondicionIva::CONSUMIDOR_FINAL))
    ->addDetail((new SaleDetail())->setDescripcion('Abono mensual de soporte')->setPrecioUnitario(150000));

(new InvoiceCalculator())->calculate($invoice);
show($see->send($invoice));
