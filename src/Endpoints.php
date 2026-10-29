<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip;

/**
 * URLs de los web services de ARCA.
 *
 * Pueden sobreescribirse (por ejemplo para apuntar a un simulador o a nuevas URLs
 * publicadas por ARCA) con {@see Endpoints::override()} o pasando la URL al servicio.
 */
final class Endpoints
{
    public const WSAA = 'wsaa';
    public const WSFE = 'wsfe';
    public const WSFEX = 'wsfex';
    public const PADRON_A5 = 'ws_sr_constancia_inscripcion';
    public const PADRON_A13 = 'ws_sr_padron_a13';

    /** @var array<string, array<string, string>> */
    private const DEFAULTS = [
        self::WSAA => [
            'homologacion' => 'https://wsaahomo.afip.gov.ar/ws/services/LoginCms',
            'produccion' => 'https://wsaa.afip.gov.ar/ws/services/LoginCms',
        ],
        self::WSFE => [
            'homologacion' => 'https://wswhomo.afip.gov.ar/wsfev1/service.asmx',
            'produccion' => 'https://servicios1.afip.gov.ar/wsfev1/service.asmx',
        ],
        self::WSFEX => [
            'homologacion' => 'https://wswhomo.afip.gov.ar/wsfexv1/service.asmx',
            'produccion' => 'https://servicios1.afip.gov.ar/wsfexv1/service.asmx',
        ],
        self::PADRON_A5 => [
            'homologacion' => 'https://awshomo.afip.gov.ar/sr-padron/webservices/personaServiceA5',
            'produccion' => 'https://aws.afip.gov.ar/sr-padron/webservices/personaServiceA5',
        ],
        self::PADRON_A13 => [
            'homologacion' => 'https://awshomo.afip.gov.ar/sr-padron/webservices/personaServiceA13',
            'produccion' => 'https://aws.afip.gov.ar/sr-padron/webservices/personaServiceA13',
        ],
    ];

    /** @var array<string, array<string, string>> */
    private static array $overrides = [];

    public static function get(string $service, Environment $environment): string
    {
        $url = self::$overrides[$service][$environment->value]
            ?? self::DEFAULTS[$service][$environment->value]
            ?? null;

        if ($url === null) {
            throw new \InvalidArgumentException(sprintf('No hay URL configurada para el servicio "%s".', $service));
        }

        return $url;
    }

    /**
     * Reemplaza globalmente la URL de un servicio para un ambiente.
     */
    public static function override(string $service, Environment $environment, string $url): void
    {
        self::$overrides[$service][$environment->value] = $url;
    }

    public static function reset(): void
    {
        self::$overrides = [];
    }
}
