<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip;

/**
 * Ambiente de ARCA.
 *
 * - HOMOLOGACION: ambiente de pruebas (sin validez fiscal). Requiere certificado de
 *   homologación generado en WSASS.
 * - PRODUCCION: comprobantes con validez fiscal. Requiere certificado de producción
 *   generado en "Administración de Certificados Digitales" y la relación con el servicio
 *   en "Administrador de Relaciones de Clave Fiscal".
 */
enum Environment: string
{
    case HOMOLOGACION = 'homologacion';
    case PRODUCCION = 'produccion';

    public function isProduction(): bool
    {
        return $this === self::PRODUCCION;
    }
}
