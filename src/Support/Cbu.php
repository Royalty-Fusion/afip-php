<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Support;

/**
 * Validación de CBU / CVU (22 dígitos, dos bloques con dígito verificador).
 */
final class Cbu
{
    public static function isValid(string $cbu): bool
    {
        if (!preg_match('/^\d{22}$/', $cbu)) {
            return false;
        }

        return self::checkBlock(substr($cbu, 0, 8), [7, 1, 3, 9, 7, 1, 3])
            && self::checkBlock(substr($cbu, 8, 14), [3, 9, 7, 1, 3, 9, 7, 1, 3, 9, 7, 1, 3]);
    }

    /**
     * @param int[] $weights
     */
    private static function checkBlock(string $block, array $weights): bool
    {
        $len = strlen($block);
        $sum = 0;
        for ($i = 0; $i < $len - 1; $i++) {
            $sum += (int) $block[$i] * $weights[$i];
        }
        $digit = (10 - ($sum % 10)) % 10;

        return $digit === (int) $block[$len - 1];
    }
}
