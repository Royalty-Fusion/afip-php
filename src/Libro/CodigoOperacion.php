<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Libro;

/**
 * Códigos de operación del Libro IVA Digital.
 */
final class CodigoOperacion
{
    public const NINGUNO = '0';
    public const NO_ALCANZADO = 'A';
    public const CANJE = 'C';
    public const EXENTO = 'E';
    public const NO_GRAVADO = 'N';
    public const REINTEGRO_1043 = 'T';
    public const EXTERIOR = 'X';
    public const ZONA_FRANCA = 'Z';

    /** @var array<int|string, string> la clave "0" queda como entero */
    private const VENTAS = [
        '0' => 'Sin código de operación',
        'Z' => 'Exportaciones a zona franca',
        'X' => 'Exportaciones al exterior',
        'E' => 'Operaciones exentas',
        'N' => 'No gravado',
        'C' => 'Operaciones de canje',
        'T' => 'Reintegro Decreto 1043/2016',
    ];

    /** @var array<int|string, string> la clave "0" queda como entero */
    private const COMPRAS = [
        '0' => 'Sin código de operación',
        'A' => 'No alcanzado',
        'C' => 'Operaciones de canje',
        'E' => 'Operaciones exentas',
        'N' => 'No gravado',
        'T' => 'Reintegro Decreto 1043/2016',
        'X' => 'Importaciones del exterior',
        'Z' => 'Importaciones de zona franca',
    ];

    public static function exists(string $codigo, string $libro): bool
    {
        return isset(self::all($libro)[$codigo]);
    }

    /**
     * @return array<int|string, string>
     */
    public static function all(string $libro): array
    {
        return $libro === LibroIvaDigital::COMPRAS ? self::COMPRAS : self::VENTAS;
    }
}
