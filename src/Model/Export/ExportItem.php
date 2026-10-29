<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Model\Export;

use RoyaltyFusion\Afip\Catalog\Exportacion;

/**
 * Ítem de la factura de exportación (nodo Item de WSFEX).
 */
class ExportItem
{
    private ?string $codigo = null;
    private string $descripcion = '';
    private float $cantidad = 1.0;
    private int $unidad = 7; // Unidades
    private float $precioUnitario = 0.0;
    private float $bonificacion = 0.0;
    private ?float $total = null;

    public function getCodigo(): ?string
    {
        return $this->codigo;
    }

    public function setCodigo(?string $codigo): static
    {
        $this->codigo = $codigo;

        return $this;
    }

    public function getDescripcion(): string
    {
        return $this->descripcion;
    }

    public function setDescripcion(string $descripcion): static
    {
        $this->descripcion = $descripcion;

        return $this;
    }

    public function getCantidad(): float
    {
        return $this->cantidad;
    }

    public function setCantidad(float $cantidad): static
    {
        $this->cantidad = $cantidad;

        return $this;
    }

    /**
     * Unidad de medida ({@see Exportacion::unidades()}, FEXGetPARAM_UMed).
     */
    public function getUnidad(): int
    {
        return $this->unidad;
    }

    public function setUnidad(int $unidad): static
    {
        $this->unidad = $unidad;

        return $this;
    }

    public function getPrecioUnitario(): float
    {
        return $this->precioUnitario;
    }

    public function setPrecioUnitario(float $precioUnitario): static
    {
        $this->precioUnitario = $precioUnitario;

        return $this;
    }

    /**
     * Bonificación en importe.
     */
    public function getBonificacion(): float
    {
        return $this->bonificacion;
    }

    public function setBonificacion(float $bonificacion): static
    {
        $this->bonificacion = $bonificacion;

        return $this;
    }

    /**
     * Total del ítem. Si no se indica se calcula como cantidad × precio − bonificación.
     */
    public function getTotal(): float
    {
        return $this->total ?? round($this->cantidad * $this->precioUnitario - $this->bonificacion, 2);
    }

    public function setTotal(?float $total): static
    {
        $this->total = $total;

        return $this;
    }

    /**
     * Ítem de bonificación general (unidad 99): se resta del total del comprobante.
     */
    public function isBonificacionGeneral(): bool
    {
        return $this->unidad === Exportacion::UMED_BONIFICACION;
    }
}
