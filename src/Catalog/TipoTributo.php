<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Catalog;

/**
 * Tipos de tributo (FEParamGetTiposTributos).
 */
final class TipoTributo
{
    public const IMPUESTOS_NACIONALES = 1;
    public const IMPUESTOS_PROVINCIALES = 2;
    public const IMPUESTOS_MUNICIPALES = 3;
    public const IMPUESTOS_INTERNOS = 4;
    public const IIBB = 5;
    public const PERCEPCION_IVA = 6;
    public const PERCEPCION_IIBB = 7;
    public const PERCEPCION_MUNICIPAL = 8;
    public const OTRAS_PERCEPCIONES = 9;
    public const PERCEPCION_IVA_NO_CATEGORIZADO = 13;
    public const OTRO = 99;

    /** @var array<int, string> */
    private const DATA = [
        1 => 'Impuestos nacionales',
        2 => 'Impuestos provinciales',
        3 => 'Impuestos municipales',
        4 => 'Impuestos Internos',
        5 => 'IIBB',
        6 => 'Percepción de IVA',
        7 => 'Percepción de IIBB',
        8 => 'Percepciones por Impuestos Municipales',
        9 => 'Otras Percepciones',
        13 => 'Percepción de IVA a no Categorizado',
        99 => 'Otro',
    ];

    public static function exists(int $id): bool
    {
        return isset(self::DATA[$id]);
    }

    public static function description(int $id): string
    {
        return self::DATA[$id] ?? (string) $id;
    }

    /**
     * Impuestos nacionales indirectos distintos del IVA (para el Régimen de
     * Transparencia Fiscal al Consumidor, Ley 27.743).
     */
    public static function isNationalIndirect(int $id): bool
    {
        return in_array($id, [self::IMPUESTOS_NACIONALES, self::IMPUESTOS_INTERNOS], true);
    }

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return self::DATA;
    }
}
