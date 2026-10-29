<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Soap;

use RoyaltyFusion\Afip\Exception\TransportException;

/**
 * Transporte HTTP de los mensajes SOAP. Permite reemplazar cURL (por ejemplo por un
 * cliente PSR-18 o por un mock en los tests).
 */
interface SoapTransportInterface
{
    /**
     * Envía el sobre SOAP y devuelve el cuerpo de la respuesta HTTP.
     *
     * Debe devolver el cuerpo también cuando el HTTP status es 500 (SOAP Fault).
     *
     * @param array<string, string> $headers
     *
     * @throws TransportException
     */
    public function send(string $url, string $body, array $headers): string;
}
