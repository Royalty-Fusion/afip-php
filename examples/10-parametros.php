<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$wsfe = see()->getWsfe();

print_r($wsfe->dummy());                       // estado de los servidores
print_r($wsfe->getSalesPoints());              // puntos de venta habilitados para WS
print_r($wsfe->getReceptorVatConditions('A')); // condiciones IVA receptor (RG 5616)
print_r($wsfe->getQuotation('DOL'));           // cotización oficial
echo 'Último Factura A PV 1: ', $wsfe->getLastVoucher(1, 1), PHP_EOL;
echo 'Máximo de comprobantes por lote: ', $wsfe->getMaxRecordsPerRequest(), PHP_EOL;
