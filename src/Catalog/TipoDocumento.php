<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Catalog;

/**
 * Tipos de documento del receptor (FEParamGetTiposDoc).
 */
final class TipoDocumento
{
    public const CUIT = 80;
    public const CUIL = 86;
    public const CDI = 87;
    public const LE = 89;
    public const LC = 90;
    public const CI_EXTRANJERA = 91;
    public const EN_TRAMITE = 92;
    public const ACTA_NACIMIENTO = 93;
    public const PASAPORTE = 94;
    public const CI_BS_AS_RNP = 95;
    public const DNI = 96;
    public const OTRO = 99;
    /** Alias habitual: consumidor final sin identificar. */
    public const CONSUMIDOR_FINAL = 99;

    /** @var array<int, string> */
    private const DATA = [
        80 => 'CUIT',
        86 => 'CUIL',
        87 => 'CDI',
        89 => 'LE',
        90 => 'LC',
        91 => 'CI Extranjera',
        92 => 'en trámite',
        93 => 'Acta Nacimiento',
        94 => 'Pasaporte',
        95 => 'CI Bs. As. RNP',
        96 => 'DNI',
        99 => 'Doc. (Otro)',
        0 => 'CI Policía Federal',
        1 => 'CI Buenos Aires',
        2 => 'CI Catamarca',
        3 => 'CI Córdoba',
        4 => 'CI Corrientes',
        5 => 'CI Entre Ríos',
        6 => 'CI Jujuy',
        7 => 'CI Mendoza',
        8 => 'CI La Rioja',
        9 => 'CI Salta',
        10 => 'CI San Juan',
        11 => 'CI San Luis',
        12 => 'CI Santa Fe',
        13 => 'CI Santiago del Estero',
        14 => 'CI Tucumán',
        16 => 'CI Chaco',
        17 => 'CI Chubut',
        18 => 'CI Formosa',
        19 => 'CI Misiones',
        20 => 'CI Neuquén',
        21 => 'CI La Pampa',
        22 => 'CI Río Negro',
        23 => 'CI Santa Cruz',
        24 => 'CI Tierra del Fuego',
    ];

    public static function exists(int $id): bool
    {
        return isset(self::DATA[$id]);
    }

    public static function description(int $id): string
    {
        return self::DATA[$id] ?? sprintf('Documento %d', $id);
    }

    /**
     * Documentos de 11 dígitos con dígito verificador (CUIT, CUIL, CDI).
     */
    public static function isCuitLike(int $id): bool
    {
        return in_array($id, [self::CUIT, self::CUIL, self::CDI], true);
    }

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return self::DATA;
    }
}
