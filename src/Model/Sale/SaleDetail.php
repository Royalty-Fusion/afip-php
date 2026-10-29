<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Model\Sale;

/**
 * Ítem del comprobante.
 *
 * WSFEv1 no recibe el detalle de ítems: se usa para calcular automáticamente los
 * importes y alícuotas ({@see \RoyaltyFusion\Afip\Calculator\InvoiceCalculator})
 * y para la representación impresa.
 *
 * El precio unitario se interpreta SIN IVA, salvo que se indique setPrecioIncluyeIva(true).
 * En comprobantes clase C (y bienes usados) el precio es el final.
 */
class SaleDetail
{
    public const GRAVADO = 'gravado';
    public const EXENTO = 'exento';
    public const NO_GRAVADO = 'no_gravado';

    private ?string $codigo = null;
    private string $descripcion = '';
    private float $cantidad = 1.0;
    private string $unidad = 'unidades';
    private float $precioUnitario = 0.0;
    private float $bonificacion = 0.0;
    private float $bonificacionPorcentaje = 0.0;
    private ?int $alicuotaIva = null;
    private string $tratamientoIva = self::GRAVADO;
    private bool $precioIncluyeIva = false;

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

    public function getUnidad(): string
    {
        return $this->unidad;
    }

    public function setUnidad(string $unidad): static
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
     * Bonificación en importe (sobre el total del ítem).
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
     * Bonificación en porcentaje (se aplica antes que la bonificación en importe).
     */
    public function getBonificacionPorcentaje(): float
    {
        return $this->bonificacionPorcentaje;
    }

    public function setBonificacionPorcentaje(float $bonificacionPorcentaje): static
    {
        $this->bonificacionPorcentaje = $bonificacionPorcentaje;

        return $this;
    }

    /**
     * Id de alícuota de IVA ({@see \RoyaltyFusion\Afip\Catalog\AlicuotaIva}).
     */
    public function getAlicuotaIva(): ?int
    {
        return $this->alicuotaIva;
    }

    public function setAlicuotaIva(?int $alicuotaIva): static
    {
        $this->alicuotaIva = $alicuotaIva;
        if ($alicuotaIva !== null) {
            $this->tratamientoIva = self::GRAVADO;
        }

        return $this;
    }

    /**
     * gravado | exento | no_gravado.
     */
    public function getTratamientoIva(): string
    {
        return $this->tratamientoIva;
    }

    public function setTratamientoIva(string $tratamientoIva): static
    {
        if (!in_array($tratamientoIva, [self::GRAVADO, self::EXENTO, self::NO_GRAVADO], true)) {
            throw new \InvalidArgumentException(sprintf('Tratamiento de IVA inválido: %s', $tratamientoIva));
        }
        $this->tratamientoIva = $tratamientoIva;
        if ($tratamientoIva !== self::GRAVADO) {
            $this->alicuotaIva = null;
        }

        return $this;
    }

    public function setExento(): static
    {
        return $this->setTratamientoIva(self::EXENTO);
    }

    public function setNoGravado(): static
    {
        return $this->setTratamientoIva(self::NO_GRAVADO);
    }

    public function isPrecioIncluyeIva(): bool
    {
        return $this->precioIncluyeIva;
    }

    public function setPrecioIncluyeIva(bool $precioIncluyeIva): static
    {
        $this->precioIncluyeIva = $precioIncluyeIva;

        return $this;
    }

    /**
     * Importe del ítem tal como fue cargado (cantidad × precio − bonificaciones), sin redondear.
     */
    public function getSubtotal(): float
    {
        $gross = $this->cantidad * $this->precioUnitario;
        $gross -= $gross * $this->bonificacionPorcentaje / 100;

        return $gross - $this->bonificacion;
    }
}
