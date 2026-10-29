<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use RoyaltyFusion\Afip\Calculator\InvoiceCalculator;
use RoyaltyFusion\Afip\Catalog\AlicuotaIva;
use RoyaltyFusion\Afip\Catalog\CondicionIva;
use RoyaltyFusion\Afip\Catalog\TipoDocumento;
use RoyaltyFusion\Afip\Model\Client;
use RoyaltyFusion\Afip\Model\Sale\FormaPago;
use RoyaltyFusion\Afip\Model\Sale\SaleDetail;
use RoyaltyFusion\Afip\Model\Sale\TurismoInvoice;

/*
 * WSCT: Factura T (195) por alojamiento a turista no residente, con reintegro de IVA.
 */
$see = see();
$wsct = $see->getWsct();
// Tablas útiles: $wsct->getIssuerReceiverRelations(), getTourismItemCodes(), getPaymentMethods(), getCountries()

$invoice = (new TurismoInvoice())
    ->setPuntoVenta(10)
    ->setCompany(company($see))
    ->setClient((new Client())->setTipoDoc(TipoDocumento::PASAPORTE)->setNumDoc('YB1234567')->setRazonSocial('John Smith')
        ->setCondicionIva(CondicionIva::CLIENTE_EXTERIOR))
    ->setCodigoPais(212) // consultarPaises
    ->setDomicilioReceptor('742 Evergreen Terrace, Springfield')
    ->setCodigoRelacionEmisorReceptor(3) // alojamiento directo a turista no residente
    ->addDetail((new SaleDetail())->setTipoItem(0)->setCodigoTurismo(1)->setCodigo('HAB-DBL')
        ->setDescripcion('Habitación doble - 3 noches')->setPrecioUnitario(300000)->setAlicuotaIva(AlicuotaIva::IVA_21))
    ->addFormaPago(new FormaPago(68, 99, '450799')); // tarjeta de crédito, 6 primeros dígitos

$invoice->setImporteReintegro(-63000.0); // reintegro del IVA (importe negativo)
(new InvoiceCalculator())->calculate($invoice, true);

show($see->sendTurismo($invoice));
