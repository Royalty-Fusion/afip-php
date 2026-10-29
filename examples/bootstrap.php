<?php

/**
 * Configuración común de los ejemplos.
 *
 * Variables de entorno:
 *   AFIP_CERT   ruta al certificado (.crt) emitido por ARCA
 *   AFIP_KEY    ruta a la clave privada (.key)
 *   AFIP_CUIT   CUIT del emisor (opcional, se toma del certificado)
 *   AFIP_ENV    "homologacion" (por defecto) o "produccion"
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use RoyaltyFusion\Afip\Auth\Certificate;
use RoyaltyFusion\Afip\Catalog\CondicionIva;
use RoyaltyFusion\Afip\Environment;
use RoyaltyFusion\Afip\Model\Address;
use RoyaltyFusion\Afip\Model\Company;
use RoyaltyFusion\Afip\See;

function see(): See
{
    $see = new See(Environment::from(getenv('AFIP_ENV') ?: 'homologacion'));
    $see->setCertificate(Certificate::fromFiles((string) getenv('AFIP_CERT'), (string) getenv('AFIP_KEY')));
    if (getenv('AFIP_CUIT')) {
        $see->setCuit((string) getenv('AFIP_CUIT'));
    }
    // Persiste los tickets de acceso (12 h) y bloquea la numeración entre procesos.
    $see->setCacheDirectory(__DIR__ . '/../var/afip');

    return $see;
}

function company(See $see): Company
{
    return (new Company())
        ->setCuit($see->getCuit())
        ->setRazonSocial('MI EMPRESA S.A.')
        ->setCondicionIva(CondicionIva::RESPONSABLE_INSCRIPTO)
        ->setIngresosBrutos('901-123456-7')
        ->setInicioActividades(new DateTimeImmutable('2020-01-01'))
        ->setAddress((new Address())->setDireccion('Av. Corrientes 1234')->setLocalidad('CABA')->setProvincia('Ciudad Autónoma de Buenos Aires'));
}

function show(RoyaltyFusion\Afip\Model\Response\AuthorizationResult|RoyaltyFusion\Afip\Model\Response\ExportAuthorizationResult $result): void
{
    if ($result->isSuccess()) {
        echo 'APROBADO - CAE: ', $result->getCae(), ' vence ', $result->getCaeVencimiento()?->format('d/m/Y'), PHP_EOL;
    } else {
        echo 'RECHAZADO: ', $result->getErrorMessage(), PHP_EOL;
    }
}
