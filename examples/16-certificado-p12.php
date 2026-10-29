<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use RoyaltyFusion\Afip\Auth\Certificate;
use RoyaltyFusion\Afip\See;

/*
 * Certificado en formato PKCS#12 (.p12 / .pfx) con su contraseña.
 */
$see = See::fromConfig([
    'pkcs12_file' => '/etc/erp/afip/empresa.p12',
    'pkcs12_password' => (string) getenv('AFIP_P12_PASSWORD'),
    'cuit' => '20123456789',
    'environment' => 'homologacion',
    'cache_dir' => '/var/lib/erp/afip',
]);

// Equivalente sin fromConfig:
$certificate = Certificate::fromPkcs12File('/etc/erp/afip/empresa.p12', (string) getenv('AFIP_P12_PASSWORD'));
echo 'CUIT del certificado: ', $certificate->getCuit(), PHP_EOL;
echo 'Vence: ', $certificate->getValidTo()->format('d/m/Y'), PHP_EOL;

print_r($see->getWsfe()->dummy());
