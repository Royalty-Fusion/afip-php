<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Catalog;

/**
 * Concepto del comprobante (FEParamGetTiposConcepto).
 */
final class Concepto
{
    public const PRODUCTOS = 1;
    public const SERVICIOS = 2;
    public const PRODUCTOS_Y_SERVICIOS = 3;

    /** @var array<int, string> */
    private const DATA = [
        1 => 'Productos',
        2 => 'Servicios',
        3 => 'Productos y Servicios',
    ];

    public static function exists(int $id): bool
    {
        return isset(self::DATA[$id]);
    }

    public static function description(int $id): string
    {
        return self::DATA[$id] ?? (string) $id;
    }

    public static function includesServices(int $id): bool
    {
        return $id === self::SERVICIOS || $id === self::PRODUCTOS_Y_SERVICIOS;
    }

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return self::DATA;
    }
}
