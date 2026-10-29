<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Catalog;

/**
 * Alícuotas de IVA (FEParamGetTiposIva).
 */
final class AlicuotaIva
{
    public const IVA_0 = 3;
    public const IVA_10_5 = 4;
    public const IVA_21 = 5;
    public const IVA_27 = 6;
    public const IVA_5 = 8;
    public const IVA_2_5 = 9;

    /** @var array<int, float> */
    private const RATES = [
        3 => 0.0,
        4 => 10.5,
        5 => 21.0,
        6 => 27.0,
        8 => 5.0,
        9 => 2.5,
    ];

    public static function exists(int $id): bool
    {
        return isset(self::RATES[$id]);
    }

    /**
     * Porcentaje de la alícuota (ej. 21.0).
     */
    public static function rate(int $id): float
    {
        if (!isset(self::RATES[$id])) {
            throw new \InvalidArgumentException(sprintf('Alícuota de IVA %d inexistente.', $id));
        }

        return self::RATES[$id];
    }

    /**
     * Id de alícuota a partir del porcentaje (21 => 5).
     */
    public static function fromRate(float $rate): ?int
    {
        foreach (self::RATES as $id => $value) {
            if (abs($value - $rate) < 0.0001) {
                return $id;
            }
        }

        return null;
    }

    public static function description(int $id): string
    {
        return isset(self::RATES[$id]) ? rtrim(rtrim(number_format(self::RATES[$id], 1, '.', ''), '0'), '.') . '%' : (string) $id;
    }

    /**
     * @return array<int, float>
     */
    public static function all(): array
    {
        return self::RATES;
    }
}
