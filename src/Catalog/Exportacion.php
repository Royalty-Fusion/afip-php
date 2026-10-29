<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Catalog;

/**
 * Tablas específicas de la Factura Electrónica de Exportación (WSFEXv1).
 */
final class Exportacion
{
    // Tipo de exportación (FEXGetPARAM_Tipo_Expo)
    public const EXPORTACION_BIENES = 1;
    public const SERVICIOS = 2;
    public const OTROS = 4;

    // Idiomas (FEXGetPARAM_Idiomas)
    public const IDIOMA_ESPANOL = 1;
    public const IDIOMA_INGLES = 2;
    public const IDIOMA_PORTUGUES = 3;

    // Unidades de medida especiales
    public const UMED_SENIAS_ANTICIPOS = 97;
    public const UMED_OTRAS_UNIDADES = 98;
    public const UMED_BONIFICACION = 99;

    /** @var array<int, string> */
    private const TIPOS_EXPORTACION = [
        1 => 'Exportación definitiva de bienes',
        2 => 'Servicios',
        4 => 'Otros',
    ];

    /** @var array<int, string> */
    private const IDIOMAS = [
        1 => 'Español',
        2 => 'Inglés',
        3 => 'Portugués',
    ];

    /** @var array<string, string> */
    private const INCOTERMS = [
        'EXW' => 'En fábrica',
        'FCA' => 'Franco transportista',
        'FAS' => 'Franco al costado del buque',
        'FOB' => 'Franco a bordo',
        'CFR' => 'Costo y flete',
        'CIF' => 'Costo, seguro y flete',
        'CPT' => 'Transporte pagado hasta',
        'CIP' => 'Transporte y seguro pagados hasta',
        'DAF' => 'Entregada en frontera',
        'DES' => 'Entregada sobre buque',
        'DEQ' => 'Entregada en muelle',
        'DDU' => 'Entregada derechos no pagados',
        'DAT' => 'Entregada en terminal',
        'DAP' => 'Entregada en lugar',
        'DPU' => 'Entregada en lugar descargada',
        'DDP' => 'Entregada derechos pagados',
    ];

    /** @var array<int, string> */
    private const UNIDADES = [
        0 => 'Sin descripción',
        1 => 'Kilogramos',
        2 => 'Metros',
        3 => 'Metros cuadrados',
        4 => 'Metros cúbicos',
        5 => 'Litros',
        6 => '1000 kWh',
        7 => 'Unidades',
        8 => 'Pares',
        9 => 'Docenas',
        10 => 'Quilates',
        11 => 'Millares',
        14 => 'Gramos',
        15 => 'Milímetros',
        16 => 'mm cúbicos',
        17 => 'Kilómetros',
        18 => 'Hectolitros',
        20 => 'Centímetros',
        25 => 'Jgo. pqt. mazo naipes',
        27 => 'cm cúbicos',
        29 => 'Toneladas',
        30 => 'dam cúbicos',
        31 => 'hm cúbicos',
        32 => 'km cúbicos',
        33 => 'Microgramos',
        34 => 'Nanogramos',
        35 => 'Picogramos',
        41 => 'Miligramos',
        47 => 'Mililitros',
        48 => 'Curie',
        49 => 'Milicurie',
        50 => 'Microcurie',
        51 => 'Uiacthor',
        52 => 'Muiacthor',
        53 => 'kg base',
        54 => 'Gruesa',
        61 => 'kg bruto',
        62 => 'Uiactant',
        63 => 'Muiactant',
        64 => 'Uiactig',
        65 => 'Muiactig',
        66 => 'kg activo',
        67 => 'Gramo activo',
        68 => 'Gramo base',
        96 => 'Packs',
        97 => 'Señas/Anticipos',
        98 => 'Otras unidades',
        99 => 'Bonificación',
    ];

    public static function tipoExportacionExists(int $id): bool
    {
        return isset(self::TIPOS_EXPORTACION[$id]);
    }

    public static function tipoExportacionDescription(int $id): string
    {
        return self::TIPOS_EXPORTACION[$id] ?? (string) $id;
    }

    public static function idiomaExists(int $id): bool
    {
        return isset(self::IDIOMAS[$id]);
    }

    public static function incotermExists(string $code): bool
    {
        return isset(self::INCOTERMS[$code]);
    }

    public static function unidadExists(int $id): bool
    {
        return isset(self::UNIDADES[$id]);
    }

    public static function unidadDescription(int $id): string
    {
        return self::UNIDADES[$id] ?? (string) $id;
    }

    /**
     * @return array<int, string>
     */
    public static function tiposExportacion(): array
    {
        return self::TIPOS_EXPORTACION;
    }

    /**
     * @return array<int, string>
     */
    public static function idiomas(): array
    {
        return self::IDIOMAS;
    }

    /**
     * @return array<string, string>
     */
    public static function incoterms(): array
    {
        return self::INCOTERMS;
    }

    /**
     * @return array<int, string>
     */
    public static function unidades(): array
    {
        return self::UNIDADES;
    }
}
