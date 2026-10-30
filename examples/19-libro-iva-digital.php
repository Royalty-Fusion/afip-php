<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use RoyaltyFusion\Afip\Exception\ValidationException;
use RoyaltyFusion\Afip\Libro\LibroIvaDigital;
use RoyaltyFusion\Afip\Libro\MisComprobantesParser;

/*
 * Compras del mes desde "Mis Comprobantes Recibidos" (CSV exportado del portal de ARCA),
 * constatación de cada una con WSCDC y archivos del Libro IVA Digital.
 *
 *   php examples/19-libro-iva-digital.php recibidos.csv emitidos.csv
 */
$see = see();
$parser = new MisComprobantesParser();

$compras = $parser->parseFile($argv[1] ?? 'recibidos.csv', MisComprobantesParser::RECIBIDOS);
$ventas = isset($argv[2]) ? $parser->parseFile($argv[2], MisComprobantesParser::EMITIDOS) : [];
foreach ($parser->getWarnings() as $warning) {
    echo 'Aviso: ', $warning, PHP_EOL;
}

foreach ($compras as $compra) {
    if ($compra->getCodigoAutorizacion() === null) {
        continue;
    }
    $result = $see->verifyReceived($compra);
    printf("%s %s: %s\n", $compra->getClave(), $compra->getNombre(), $result->isSuccess() ? 'válido' : 'NO válido: ' . $result->getErrorMessage());
}

$libro = (new LibroIvaDigital($see->getCuit()))
    ->setPeriodo((int) date('Y'), (int) date('n'))
    ->addVentas($ventas)
    ->addCompras($compras);

try {
    foreach ($libro->writeTo(__DIR__ . '/libro-iva') as $path) {
        echo 'Generado: ', $path, PHP_EOL;
    }
} catch (ValidationException $e) {
    foreach ($e->getResult()->getErrors() as $error) {
        echo $error, PHP_EOL;
    }
}
