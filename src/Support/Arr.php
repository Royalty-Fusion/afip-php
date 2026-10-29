<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Support;

/**
 * Ayudas para recorrer las respuestas SOAP convertidas a arrays.
 */
final class Arr
{
    /**
     * Normaliza un nodo que puede venir como un único elemento o como una lista.
     *
     * @return array<int, mixed>
     */
    public static function list(mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }
        if (is_array($value) && array_is_list($value)) {
            return $value;
        }

        return [$value];
    }

    /**
     * Obtiene un valor anidado usando notación con puntos ("FeCabResp.Resultado").
     */
    public static function get(mixed $data, string $path, mixed $default = null): mixed
    {
        if ($path === '') {
            return $data;
        }
        foreach (explode('.', $path) as $segment) {
            if (!is_array($data) || !array_key_exists($segment, $data)) {
                return $default;
            }
            $data = $data[$segment];
        }

        return $data;
    }

    public static function string(mixed $data, string $path, ?string $default = null): ?string
    {
        $value = self::get($data, $path);
        if ($value === null || is_array($value)) {
            return $default;
        }

        return (string) $value;
    }

    public static function int(mixed $data, string $path, ?int $default = null): ?int
    {
        $value = self::string($data, $path);

        return $value === null || $value === '' ? $default : (int) $value;
    }

    public static function float(mixed $data, string $path, ?float $default = null): ?float
    {
        $value = self::string($data, $path);

        return $value === null || $value === '' ? $default : (float) $value;
    }
}
