<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Support;

/**
 * Manejo de fechas en la zona horaria de Argentina y en los formatos de ARCA.
 */
final class Date
{
    public const TIMEZONE = 'America/Argentina/Buenos_Aires';

    public static function timezone(): \DateTimeZone
    {
        return new \DateTimeZone(self::TIMEZONE);
    }

    public static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', self::timezone());
    }

    public static function today(): \DateTimeImmutable
    {
        return self::now()->setTime(0, 0);
    }

    /**
     * Normaliza a fecha (sin hora) en la zona horaria de Argentina, conservando el día calendario.
     */
    public static function toDate(\DateTimeInterface $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date->format('Y-m-d'), self::timezone());
    }

    /**
     * Formato yyyymmdd usado por WSFE / WSFEX.
     */
    public static function toAfip(?\DateTimeInterface $date): ?string
    {
        return $date?->format('Ymd');
    }

    /**
     * Interpreta yyyymmdd, yyyy-mm-dd o yyyymmddhhmmss. Devuelve null si está vacío o es inválido.
     */
    public static function fromAfip(?string $value): ?\DateTimeImmutable
    {
        $value = trim((string) $value);
        if ($value === '' || $value === 'NULL') {
            return null;
        }
        $formats = ['!Ymd' => 8, '!Y-m-d' => 10, '!YmdHis' => 14];
        foreach ($formats as $format => $length) {
            if (strlen($value) !== $length) {
                continue;
            }
            $date = \DateTimeImmutable::createFromFormat($format, $value, self::timezone());
            $errors = \DateTimeImmutable::getLastErrors();
            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date;
            }
        }

        try {
            return new \DateTimeImmutable($value, self::timezone());
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Diferencia en días calendario (b - a).
     */
    public static function diffDays(\DateTimeInterface $a, \DateTimeInterface $b): int
    {
        $da = self::toDate($a);
        $db = self::toDate($b);
        $diff = $da->diff($db);

        return (int) $diff->format('%r%a');
    }
}
