<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Exception;

/**
 * El número del comprobante ya está autorizado en ARCA, pero con otros datos (importe,
 * documento del receptor o fecha). No es el mismo comprobante: no debe tomarse ese CAE.
 */
class VoucherMismatchException extends AfipException
{
    /**
     * @param array<string, mixed> $authorized datos del comprobante autorizado en ARCA
     */
    public function __construct(string $message, private readonly array $authorized = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @return array<string, mixed>
     */
    public function getAuthorized(): array
    {
        return $this->authorized;
    }
}
