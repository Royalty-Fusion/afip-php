<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

/*
 * WSCDC: verificar ante ARCA la factura de un proveedor antes de registrarla en compras.
 */
$result = see()->getWscdc()->verify(
    cuitEmisor: '30500010912',
    puntoVenta: 5,
    tipoComprobante: 1,
    numero: 2424,
    fecha: new DateTimeImmutable('2026-10-20'),
    importeTotal: 1754.50,
    codigoAutorizacion: '69333775104382',
    modo: 'CAE',
    docTipoReceptor: 80,
    docNroReceptor: (string) getenv('AFIP_CUIT'),
);

echo $result->isSuccess() ? 'Comprobante válido' : 'NO válido: ' . $result->getErrorMessage(), PHP_EOL;
