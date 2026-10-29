<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use RoyaltyFusion\Afip\Calculator\InvoiceCalculator;
use RoyaltyFusion\Afip\Catalog\AlicuotaIva;
use RoyaltyFusion\Afip\Catalog\TipoComprobante;
use RoyaltyFusion\Afip\Model\Client;
use RoyaltyFusion\Afip\Model\Sale\Invoice;
use RoyaltyFusion\Afip\Model\Sale\SaleDetail;
use RoyaltyFusion\Afip\Report\HtmlReport;
use RoyaltyFusion\Afip\Report\PdfReport;

$see = see();

$invoice = (new Invoice())
    ->setTipoComprobante(TipoComprobante::FACTURA_B)
    ->setPuntoVenta(1)
    ->setCompany(company($see))
    ->setClient(Client::consumidorFinal())
    ->addDetail((new SaleDetail())->setDescripcion('Producto')->setCantidad(2)->setPrecioUnitario(12100)->setAlicuotaIva(AlicuotaIva::IVA_21)->setPrecioIncluyeIva(true));
(new InvoiceCalculator())->calculate($invoice);

$result = $see->send($invoice);
if (!$result->isSuccess()) {
    exit('Rechazado: ' . $result->getErrorMessage() . PHP_EOL);
}

$params = [
    // 'logo' => __DIR__ . '/logo.png',
    'condicionVenta' => 'Contado',
    'footer' => 'Gracias por su compra',
];

file_put_contents(__DIR__ . '/factura.html', (new HtmlReport())->render($invoice, $params));

// Requiere: composer require dompdf/dompdf chillerlan/php-qrcode
if (class_exists(Dompdf\Dompdf::class)) {
    file_put_contents(__DIR__ . '/factura.pdf', (new PdfReport())->render($invoice, $params));
}
