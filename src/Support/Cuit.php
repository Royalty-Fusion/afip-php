<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Support;

/**
 * Utilidades para CUIT / CUIL / CDI (11 dígitos con dígito verificador módulo 11).
 */
final class Cuit
{
    private const WEIGHTS = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
    private const VALID_PREFIXES = ['20', '23', '24', '25', '26', '27', '30', '33', '34', '50', '51', '55'];

    public static function normalize(string|int $cuit): string
    {
        return preg_replace('/\D/', '', (string) $cuit) ?? '';
    }

    public static function isValid(string|int $cuit): bool
    {
        $cuit = self::normalize($cuit);
        if (!preg_match('/^\d{11}$/', $cuit)) {
            return false;
        }
        if (!in_array(substr($cuit, 0, 2), self::VALID_PREFIXES, true)) {
            return false;
        }

        return self::checkDigit(substr($cuit, 0, 10)) === (int) $cuit[10];
    }

    /**
     * Calcula el dígito verificador para los primeros 10 dígitos.
     * Devuelve -1 cuando el resultado es 10 (combinación inválida).
     */
    public static function checkDigit(string $firstTen): int
    {
        $sum = 0;
        foreach (self::WEIGHTS as $i => $weight) {
            $sum += (int) $firstTen[$i] * $weight;
        }
        $mod = 11 - ($sum % 11);

        return match ($mod) {
            11 => 0,
            10 => -1,
            default => $mod,
        };
    }

    /**
     * Formatea como XX-XXXXXXXX-X.
     */
    public static function format(string|int $cuit): string
    {
        $cuit = self::normalize($cuit);
        if (strlen($cuit) !== 11) {
            return $cuit;
        }

        return substr($cuit, 0, 2) . '-' . substr($cuit, 2, 8) . '-' . substr($cuit, 10, 1);
    }

    public static function isLegalEntity(string|int $cuit): bool
    {
        return in_array(substr(self::normalize($cuit), 0, 2), ['30', '33', '34'], true);
    }
}
