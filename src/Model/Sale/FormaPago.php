<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Model\Sale;

/**
 * Forma de pago de un comprobante de turismo (WSCT: consultarFormasPago).
 */
class FormaPago
{
    public function __construct(
        private int $codigo,
        private ?int $tipoTarjeta = null,
        private ?string $numeroTarjeta = null,
        private ?string $swiftCode = null,
        private ?int $tipoCuenta = null,
        private ?string $numeroCuenta = null
    ) {
    }

    public function getCodigo(): int
    {
        return $this->codigo;
    }

    public function setCodigo(int $codigo): static
    {
        $this->codigo = $codigo;

        return $this;
    }

    /**
     * Tipo de tarjeta (consultarTiposTarjeta).
     */
    public function getTipoTarjeta(): ?int
    {
        return $this->tipoTarjeta;
    }

    public function setTipoTarjeta(?int $tipoTarjeta): static
    {
        $this->tipoTarjeta = $tipoTarjeta;

        return $this;
    }

    /**
     * Primeros 6 dígitos de la tarjeta.
     */
    public function getNumeroTarjeta(): ?string
    {
        return $this->numeroTarjeta;
    }

    public function setNumeroTarjeta(?string $numeroTarjeta): static
    {
        $this->numeroTarjeta = $numeroTarjeta;

        return $this;
    }

    public function getSwiftCode(): ?string
    {
        return $this->swiftCode;
    }

    public function setSwiftCode(?string $swiftCode): static
    {
        $this->swiftCode = $swiftCode;

        return $this;
    }

    /**
     * Tipo de cuenta (consultarTiposCuenta).
     */
    public function getTipoCuenta(): ?int
    {
        return $this->tipoCuenta;
    }

    public function setTipoCuenta(?int $tipoCuenta): static
    {
        $this->tipoCuenta = $tipoCuenta;

        return $this;
    }

    public function getNumeroCuenta(): ?string
    {
        return $this->numeroCuenta;
    }

    public function setNumeroCuenta(?string $numeroCuenta): static
    {
        $this->numeroCuenta = $numeroCuenta;

        return $this;
    }
}
