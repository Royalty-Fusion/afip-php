<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Model\Export;

/**
 * Comprobante asociado de exportación (nodo Cmp_asoc de WSFEX).
 */
class ExportCbteAsociado
{
    public function __construct(
        private int $tipo,
        private int $puntoVenta,
        private int $numero,
        private string $cuit
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

    public function getCuit(): string
    {
        return $this->cuit;
    }

    public function setCuit(string $cuit): static
    {
        $this->cuit = $cuit;

        return $this;
    }
}
