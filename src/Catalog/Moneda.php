<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Catalog;

/**
 * Monedas (FEParamGetTiposMonedas / FEXGetPARAM_MON).
 */
final class Moneda
{
    public const PESOS = 'PES';
    public const DOLAR = 'DOL';
    public const DOLAR_LIBRE = '002';
    public const EURO = '060';
    public const REAL = '012';
    public const SOL_PERUANO = '035';

    /** @var array<string, string> */
    private const DATA = [
        'PES' => 'Pesos Argentinos',
        'DOL' => 'Dólar Estadounidense',
        '002' => 'Dólar Libre EEUU',
        '007' => 'Florines Holandeses',
        '009' => 'Franco Suizo',
        '010' => 'Pesos Mejicanos',
        '011' => 'Pesos Uruguayos',
        '012' => 'Real',
        '014' => 'Coronas Danesas',
        '015' => 'Coronas Noruegas',
        '016' => 'Coronas Suecas',
        '018' => 'Dólar Canadiense',
        '019' => 'Yens',
        '021' => 'Libra Esterlina',
        '023' => 'Bolívar Venezolano',
        '024' => 'Corona Checa',
        '025' => 'Dinar Yugoslavo',
        '026' => 'Dólar Australiano',
        '027' => 'Dracma Griego',
        '028' => 'Florín (Antillas Holandesas)',
        '029' => 'Guaraní',
        '030' => 'Shekel (Israel)',
        '031' => 'Peso Boliviano',
        '032' => 'Peso Colombiano',
        '033' => 'Peso Chileno',
        '034' => 'Rand Sudafricano',
        '035' => 'Nuevo Sol Peruano',
        '036' => 'Sucre Ecuatoriano',
        '040' => 'Lei Rumano',
        '041' => 'Derechos Especiales de Giro',
        '042' => 'Peso Dominicano',
        '043' => 'Balboas Panameñas',
        '044' => 'Córdoba Nicaragüense',
        '045' => 'Dirham Marroquí',
        '046' => 'Libra Egipcia',
        '047' => 'Riyal Saudita',
        '049' => 'Gramos de Oro Fino',
        '051' => 'Dólar de Hong Kong',
        '052' => 'Dólar de Singapur',
        '053' => 'Dólar de Jamaica',
        '054' => 'Dólar de Taiwan',
        '055' => 'Quetzal Guatemalteco',
        '056' => 'Forint (Hungría)',
        '057' => 'Baht (Tailandia)',
        '059' => 'Dinar Kuwaiti',
        '060' => 'Euro',
        '061' => 'Zloty Polaco',
        '062' => 'Rupia Hindú',
        '063' => 'Lempira Hondureña',
        '064' => 'Yuan (Rep. Pop. China)',
    ];

    /** Símbolos para la representación impresa. */
    private const SYMBOLS = [
        'PES' => '$',
        'DOL' => 'US$',
        '002' => 'US$',
        '060' => '€',
        '012' => 'R$',
        '021' => '£',
        '035' => 'S/',
    ];

    public static function exists(string $id): bool
    {
        return isset(self::DATA[$id]);
    }

    public static function description(string $id): string
    {
        return self::DATA[$id] ?? $id;
    }

    public static function symbol(string $id): string
    {
        return self::SYMBOLS[$id] ?? $id;
    }

    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        return self::DATA;
    }
}
