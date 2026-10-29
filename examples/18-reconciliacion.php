<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use RoyaltyFusion\Afip\Calculator\InvoiceCalculator;
use RoyaltyFusion\Afip\Catalog\AlicuotaIva;
use RoyaltyFusion\Afip\Catalog\TipoComprobante;
use RoyaltyFusion\Afip\Exception\UncertainAuthorizationException;
use RoyaltyFusion\Afip\Exception\VoucherMismatchException;
use RoyaltyFusion\Afip\Model\Client;
use RoyaltyFusion\Afip\Model\Sale\Invoice;
use RoyaltyFusion\Afip\Model\Sale\SaleDetail;

/*
 * Estado incierto: guardar y retomar desde otro proceso (cron).
 */
$see = see();

$invoice = (new Invoice())
    ->setTipoComprobante(TipoComprobante::FACTURA_B)
    ->setPuntoVenta(1)
    ->setCompany(company($see))
    ->setClient(Client::consumidorFinal())
    ->addDetail((new SaleDetail())->setDescripcion('Producto')->setPrecioUnitario(1210)->setAlicuotaIva(AlicuotaIva::IVA_21)->setPrecioIncluyeIva(true));
(new InvoiceCalculator())->calculate($invoice);

try {
    $see->send($invoice);
} catch (UncertainAuthorizationException $e) {
    // Guardar junto al comprobante: servicio, ambiente, CUIT, tipo, PV, números y hash.
    file_put_contents(__DIR__ . '/pendiente.json', json_encode($e));
}

// ... más tarde, en el cron, con el MISMO comprobante (mismo número):
try {
    $result = $see->reconcile($invoice);
    echo $result !== null ? 'Autorizado, CAE ' . $result->getCae() : 'No existe en ARCA: reenviar con el mismo número', PHP_EOL;
} catch (VoucherMismatchException $e) {
    echo 'El número está autorizado con otros datos: revisar', PHP_EOL;
}
