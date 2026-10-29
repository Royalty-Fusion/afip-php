<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Exception;

/**
 * Error al obtener el Ticket de Acceso (TA) del WSAA.
 */
class WsaaException extends AfipException
{
    public const ALREADY_AUTHENTICATED = 'coe.alreadyAuthenticated';

    public function __construct(string $message, private readonly ?string $faultCode = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function getFaultCode(): ?string
    {
        return $this->faultCode;
    }

    public function isAlreadyAuthenticated(): bool
    {
        return $this->faultCode === self::ALREADY_AUTHENTICATED;
    }
}
