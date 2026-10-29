<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

/*
 * CAEA: código de autorización anticipado (contingencia o emisión masiva).
 * 1) Se solicita por quincena, 2) se emiten los comprobantes con el CAEA,
 * 3) se informan con FECAEARegInformativo antes de la fecha tope.
 */

$see = see();
$wsfe = $see->getWsfe();

$hoy = new DateTimeImmutable('now', new DateTimeZone('America/Argentina/Buenos_Aires'));
$periodo = (int) $hoy->format('Ym');
$orden = (int) $hoy->format('d') <= 15 ? 1 : 2;

try {
    $caea = $wsfe->getCaea($periodo, $orden);
} catch (RoyaltyFusion\Afip\Exception\ServiceException) {
    $caea = $wsfe->requestCaea($periodo, $orden);
}
echo 'CAEA ', $caea->getCaea(), ' vigente hasta ', $caea->getVigenciaHasta()?->format('d/m/Y'),
    ' - informar antes del ', $caea->getFechaTopeInformar()?->format('d/m/Y'), PHP_EOL;

// Para cada comprobante emitido offline:
// $invoice->setNumero($n)->setCaea($caea->getCaea())->setFechaHoraGeneracion(new DateTimeImmutable());
// $result = $see->informCaea($invoice);
// Si un punto de venta no tuvo movimientos: $wsfe->informCaeaWithoutMovement($caea->getCaea(), $ptoVta);
