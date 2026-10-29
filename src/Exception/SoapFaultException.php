<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Exception;

/**
 * ARCA respondió con un SOAP Fault.
 */
class SoapFaultException extends AfipException
{
    public function __construct(
        private readonly string $faultCode,
        private readonly string $faultString,
        private readonly ?string $detail = null
    ) {
        parent::__construct(trim(sprintf('[%s] %s', $faultCode, $faultString)));
    }

    public function getFaultCode(): string
    {
        return $this->faultCode;
    }

    /**
     * Código de falla sin el prefijo de namespace (ej. "coe.alreadyAuthenticated").
     */
    public function getShortFaultCode(): string
    {
        $pos = strrpos($this->faultCode, ':');

        return $pos === false ? $this->faultCode : substr($this->faultCode, $pos + 1);
    }

    public function getFaultString(): string
    {
        return $this->faultString;
    }

    public function getDetail(): ?string
    {
        return $this->detail;
    }
}
