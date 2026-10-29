<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Support;

/**
 * Operaciones numéricas seguras para importes (redondeo comercial a 2 decimales).
 */
final class Num
{
    public static function round(float $value, int $decimals = 2): float
    {
        // Se suma un epsilon proporcional para evitar errores de representación binaria
        // (ej. 1.005 => 1.01) y luego se redondea "half up".
        $factor = 10 ** $decimals;
        $sign = $value < 0 ? -1 : 1;
        $abs = abs($value);

        return $sign * (round(round($abs * $factor, 6), 0, PHP_ROUND_HALF_UP) / $factor);
    }

    public static function equals(float $a, float $b, float $tolerance = 0.0): bool
    {
        return abs(self::round($a - $b, 6)) <= $tolerance + 1e-9;
    }

    /**
     * Formatea un importe para enviar a ARCA (punto decimal, sin separador de miles).
     */
    public static function format(float $value, int $decimals = 2): string
    {
        return number_format(self::round($value, $decimals), $decimals, '.', '');
    }

    /**
     * Formatea con la mínima cantidad de decimales necesaria (máx. $maxDecimals).
     */
    public static function formatCompact(float $value, int $maxDecimals = 6): string
    {
        $formatted = number_format($value, $maxDecimals, '.', '');
        if (str_contains($formatted, '.')) {
            $formatted = rtrim(rtrim($formatted, '0'), '.');
        }

        return $formatted === '-0' ? '0' : $formatted;
    }

    /**
     * Cantidad de decimales significativos de un valor.
     */
    public static function decimals(float $value): int
    {
        $s = self::formatCompact($value, 10);
        $pos = strpos($s, '.');

        return $pos === false ? 0 : strlen($s) - $pos - 1;
    }

    /**
     * Formatea para mostrar en la representación impresa (1.234,56).
     */
    public static function display(float $value, int $decimals = 2): string
    {
        return number_format($value, $decimals, ',', '.');
    }
}
