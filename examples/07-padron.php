<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$see = see();

// Constancia de inscripción (requiere asociar el servicio ws_sr_constancia_inscripcion al certificado).
$persona = $see->getPadronA5()->getPersona('30500010912');
if ($persona === null) {
    exit("No existe la CUIT\n");
}

echo $persona->getNombreCompleto(), PHP_EOL;
echo 'Estado: ', $persona->getEstadoClave(), PHP_EOL;
echo 'Domicilio: ', $persona->getDomicilioFiscal(), PHP_EOL;
echo 'Condición IVA (RG 5616): ', $persona->getCondicionIva(), PHP_EOL;

// Receptor listo para usar en un comprobante:
$client = $persona->toClient();
