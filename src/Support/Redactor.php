<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Support;

/**
 * Oculta credenciales en los XML antes de registrarlos: token y sign del Ticket de Acceso,
 * el CMS firmado del login (in0), la respuesta del WSAA y cualquier clave privada PEM.
 */
final class Redactor
{
    public const MASK = '***';

    public static function xml(?string $xml): ?string
    {
        if ($xml === null || $xml === '') {
            return $xml;
        }
        $xml = (string) preg_replace(
            '#<((?:[\w-]+:)?(?:token|Token|sign|Sign|in0|loginCmsReturn))((?:\s[^>]*)?)>.*?</\1>#s',
            '<$1$2>' . self::MASK . '</$1>',
            $xml
        );
        // Credenciales dentro de XML escapado (loginTicketResponse embebido).
        $xml = (string) preg_replace('#&lt;(token|sign)&gt;.*?&lt;/\1&gt;#s', '&lt;$1&gt;' . self::MASK . '&lt;/$1&gt;', $xml);

        return self::pem($xml);
    }

    /**
     * Oculta bloques PEM de claves privadas.
     */
    public static function pem(string $text): string
    {
        return (string) preg_replace(
            '#-----BEGIN ([A-Z ]*)PRIVATE KEY-----.*?-----END \1PRIVATE KEY-----#s',
            '-----BEGIN $1PRIVATE KEY-----' . self::MASK . '-----END $1PRIVATE KEY-----',
            $text
        );
    }
}
