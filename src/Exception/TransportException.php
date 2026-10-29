<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Exception;

/**
 * Error de comunicación con ARCA (timeout, DNS, TLS, HTTP distinto de 200/500).
 *
 * Cuando ocurre durante una autorización NO se sabe si ARCA procesó el comprobante:
 * el SDK intenta recuperarlo con FECompConsultar antes de propagar esta excepción.
 */
class TransportException extends AfipException
{
    public function __construct(
        string $message,
        private readonly ?int $httpStatus = null,
        private readonly ?string $responseBody = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function getResponseBody(): ?string
    {
        return $this->responseBody;
    }
}
