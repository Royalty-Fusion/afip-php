<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Model\Sale;

/**
 * Comprobante asociado (nodo CbteAsoc). Obligatorio en notas de crédito y débito
 * (salvo que se informe un período asociado).
 */
class CbteAsociado
{
    public function __construct(
        private int $tipo,
        private int $puntoVenta,
        private int $numero,
        private ?string $cuit = null,
        private ?\DateTimeInterface $fecha = null
    ) {
    }

    public function getTipo(): int
    {
        return $this->tipo;
    }

    public function setTipo(int $tipo): static
    {
        $this->tipo = $tipo;

        return $this;
    }

    public function getPuntoVenta(): int
    {
        return $this->puntoVenta;
    }

    public function setPuntoVenta(int $puntoVenta): static
    {
        $this->puntoVenta = $puntoVenta;

        return $this;
    }

    public function getNumero(): int
    {
        return $this->numero;
    }

    public function setNumero(int $numero): static
    {
        $this->numero = $numero;

        return $this;
    }

    /**
     * CUIT del emisor del comprobante asociado (obligatorio en FCE).
     */
    public function getCuit(): ?string
    {
        return $this->cuit;
    }

    public function setCuit(?string $cuit): static
    {
        $this->cuit = $cuit === null ? null : (preg_replace('/\D/', '', $cuit) ?? '');

        return $this;
    }

    /**
     * Fecha del comprobante asociado (obligatoria en FCE).
     */
    public function getFecha(): ?\DateTimeInterface
    {
        return $this->fecha;
    }

    public function setFecha(?\DateTimeInterface $fecha): static
    {
        $this->fecha = $fecha;

        return $this;
    }
}
