<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Soap;

use RoyaltyFusion\Afip\Exception\AfipException;

/**
 * Conversión entre arrays PHP y XML.
 *
 * Convenciones para construir XML desde arrays:
 *  - Las claves se emiten en el orden del array (el orden importa en los WSDL de ARCA).
 *  - Los valores null se omiten.
 *  - Una lista (array con índices 0..n) de valores repite el elemento:
 *      ['AlicIva' => [['Id' => 5], ['Id' => 4]]] => <AlicIva>..</AlicIva><AlicIva>..</AlicIva>
 */
final class XmlUtil
{
    /**
     * @param array<string, mixed> $data
     */
    public static function appendArray(\DOMDocument $doc, \DOMElement $parent, array $data, ?string $namespace): void
    {
        foreach ($data as $name => $value) {
            if ($value === null) {
                continue;
            }
            if (is_array($value) && $value !== [] && array_is_list($value)) {
                foreach ($value as $item) {
                    if ($item !== null) {
                        self::appendValue($doc, $parent, (string) $name, $item, $namespace);
                    }
                }
                continue;
            }
            self::appendValue($doc, $parent, (string) $name, $value, $namespace);
        }
    }

    private static function appendValue(\DOMDocument $doc, \DOMElement $parent, string $name, mixed $value, ?string $namespace): void
    {
        $element = $namespace === null ? $doc->createElement($name) : $doc->createElementNS($namespace, $name);
        if (is_array($value)) {
            self::appendArray($doc, $element, $value, $namespace);
        } else {
            $element->appendChild($doc->createTextNode(self::scalar($value)));
        }
        $parent->appendChild($element);
    }

    private static function scalar(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_float($value) => self::float($value),
            $value instanceof \Stringable, is_scalar($value) => (string) $value,
            default => throw new AfipException('Valor no serializable a XML: ' . get_debug_type($value)),
        };
    }

    private static function float(float $value): string
    {
        $s = number_format($value, 6, '.', '');

        return rtrim(rtrim($s, '0'), '.') ?: '0';
    }

    /**
     * Convierte un elemento DOM en array. Los elementos sin hijos se devuelven como string;
     * los elementos repetidos se agrupan en listas.
     *
     * @return array<string, mixed>|string
     */
    public static function toArray(\DOMElement $element): array|string
    {
        $children = [];
        foreach ($element->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $children[] = $child;
            }
        }
        if ($children === []) {
            return $element->textContent;
        }

        $result = [];
        $repeated = [];
        foreach ($children as $child) {
            $name = $child->localName;
            $value = self::toArray($child);
            if (!array_key_exists($name, $result)) {
                $result[$name] = $value;
                continue;
            }
            if (!isset($repeated[$name])) {
                $result[$name] = [$result[$name]];
                $repeated[$name] = true;
            }
            $result[$name][] = $value;
        }

        return $result;
    }

    public static function load(string $xml): \DOMDocument
    {
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            $message = $errors !== [] ? trim($errors[0]->message) : 'XML inválido';
            throw new AfipException('No se pudo interpretar la respuesta XML: ' . $message);
        }

        return $doc;
    }
}
