<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Catalog;

/**
 * Condición frente al IVA (RG 5616 — FEParamGetCondicionIvaReceptor).
 *
 * Se usa tanto para el receptor (campo CondicionIVAReceptorId) como para describir
 * la condición del emisor.
 */
final class CondicionIva
{
    public const RESPONSABLE_INSCRIPTO = 1;
    public const EXENTO = 4;
    public const CONSUMIDOR_FINAL = 5;
    public const MONOTRIBUTO = 6;
    public const NO_CATEGORIZADO = 7;
    public const PROVEEDOR_EXTERIOR = 8;
    public const CLIENTE_EXTERIOR = 9;
    public const IVA_LIBERADO_LEY_19640 = 10;
    public const MONOTRIBUTO_SOCIAL = 13;
    public const NO_ALCANZADO = 15;
    public const MONOTRIBUTO_TRABAJADOR_INDEPENDIENTE_PROMOVIDO = 16;

    /**
     * id => [descripción, clases de comprobante admitidas]
     *
     * @var array<int, array{0: string, 1: string[]}>
     */
    private const DATA = [
        1 => ['IVA Responsable Inscripto', ['A', 'M', 'C']],
        4 => ['IVA Sujeto Exento', ['B', 'C']],
        5 => ['Consumidor Final', ['B', 'C']],
        6 => ['Responsable Monotributo', ['A', 'M', 'C']],
        7 => ['Sujeto No Categorizado', ['B', 'C']],
        8 => ['Proveedor del Exterior', ['B', 'C']],
        9 => ['Cliente del Exterior', ['B', 'C']],
        10 => ['IVA Liberado – Ley N° 19.640', ['B', 'C']],
        13 => ['Monotributista Social', ['A', 'M', 'C']],
        15 => ['IVA No Alcanzado', ['B', 'C']],
        16 => ['Monotributo Trabajador Independiente Promovido', ['A', 'M', 'C']],
    ];

    public static function exists(int $id): bool
    {
        return isset(self::DATA[$id]);
    }

    public static function description(int $id): string
    {
        return self::DATA[$id][0] ?? (string) $id;
    }

    /**
     * Clases de comprobante (A, B, C, M) en las que puede informarse la condición.
     *
     * @return string[]
     */
    public static function allowedClasses(int $id): array
    {
        return self::DATA[$id][1] ?? [];
    }

    public static function isAllowedForClass(int $id, string $clase): bool
    {
        return in_array($clase, self::allowedClasses($id), true);
    }

    /**
     * Condiciones que requieren que el receptor se identifique con CUIT
     * (responsables inscriptos y monotributistas).
     */
    public static function requiresCuit(int $id): bool
    {
        return in_array($id, [
            self::RESPONSABLE_INSCRIPTO,
            self::MONOTRIBUTO,
            self::MONOTRIBUTO_SOCIAL,
            self::MONOTRIBUTO_TRABAJADOR_INDEPENDIENTE_PROMOVIDO,
        ], true);
    }

    public static function isMonotributo(int $id): bool
    {
        return in_array($id, [self::MONOTRIBUTO, self::MONOTRIBUTO_SOCIAL, self::MONOTRIBUTO_TRABAJADOR_INDEPENDIENTE_PROMOVIDO], true);
    }

    /**
     * Clases de comprobante que puede EMITIR un contribuyente con esta condición.
     *
     * @return string[]
     */
    public static function issuableClasses(int $emisorCondicion): array
    {
        return match (true) {
            $emisorCondicion === self::RESPONSABLE_INSCRIPTO => ['A', 'B', 'M', 'E', 'T'],
            self::isMonotributo($emisorCondicion), $emisorCondicion === self::EXENTO => ['C', 'E'],
            default => ['C', 'E'],
        };
    }

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return array_map(static fn (array $row): string => $row[0], self::DATA);
    }
}
