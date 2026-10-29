<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use RoyaltyFusion\Afip\Service\WsfecredService;

/*
 * WSFECRED: Factura de Crédito Electrónica MiPyME.
 */
$see = see();
$fecred = $see->getWsfecred();

// Como emisor: ¿el cliente está obligado a recibir FCE y desde qué monto?
$obligacion = $fecred->getObligation('30500010912');
echo $obligacion['obligado'] ? 'Obligado desde $ ' . $obligacion['montoDesde'] : 'No obligado', PHP_EOL;

// O automáticamente antes de cada envío (emisor MiPyME):
$see->setFceCheck(true);

// Como receptor (compras): FCE recibidas pendientes
$pendientes = $fecred->getVouchers(WsfecredService::ROL_RECEPTOR, ['estado' => 'Recepcionado']);
foreach ($pendientes['comprobantes'] as $cmp) {
    $id = WsfecredService::cuentaCorriente((int) $cmp['codCtaCte']);
    // Aceptar el saldo total:
    // $fecred->accept($id, (float) $cmp['importeTotal'], $cmp['codMoneda'], (float) $cmp['cotizacionMoneda']);
    // Rechazar con motivo (getRejectionReasons()):
    // $fecred->reject($id, [['codMotivo' => 1, 'descMotivo' => 'Falta de entrega', 'justificacion' => 'Mercadería no recibida']]);
}
