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
    /** Unidad de medida de bonificación general (WSMTXCA). */
    public const UNIDAD_BONIFICACION = 99;
    /** Tipo de ítem de descuento (WSCT). */
    public const TIPO_ITEM_DESCUENTO = 99;

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
    // Campos de WSMTXCA / WSBFE / WSCT
    private int $codigoUnidadMedida = 7;
    private ?string $codigoMtx = null;
    private ?int $unidadesMtx = null;
    private ?string $codigoNcm = null;
    private ?string $codigoSecretaria = null;
    private ?float $importeIva = null;
    private ?float $importeItem = null;
    private int $tipoItem = 0;
    private bool $descuento = false;
    private ?int $codigoTurismo = null;

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
     * Código de unidad de medida de ARCA (7 = unidades). Se usa en WSMTXCA y WSBFE.
     */
    public function getCodigoUnidadMedida(): int
    {
        return $this->codigoUnidadMedida;
    }

    public function setCodigoUnidadMedida(int $codigoUnidadMedida): static
    {
        $this->codigoUnidadMedida = $codigoUnidadMedida;

        return $this;
    }

    /**
     * Código de barras / GTIN del producto (WSMTXCA, codigoMtx).
     */
    public function getCodigoMtx(): ?string
    {
        return $this->codigoMtx;
    }

    public function setCodigoMtx(?string $codigoMtx): static
    {
        $this->codigoMtx = $codigoMtx;

        return $this;
    }

    /**
     * Unidades de la presentación referenciada por codigoMtx (WSMTXCA, unidadesMtx).
     */
    public function getUnidadesMtx(): ?int
    {
        return $this->unidadesMtx;
    }

    public function setUnidadesMtx(?int $unidadesMtx): static
    {
        $this->unidadesMtx = $unidadesMtx;

        return $this;
    }

    /**
     * Código NCM del bien (WSBFE, Pro_codigo_ncm).
     */
    public function getCodigoNcm(): ?string
    {
        return $this->codigoNcm;
    }

    public function setCodigoNcm(?string $codigoNcm): static
    {
        $this->codigoNcm = $codigoNcm;

        return $this;
    }

    /**
     * Código de la Secretaría de Industria (WSBFE, Pro_codigo_sec).
     */
    public function getCodigoSecretaria(): ?string
    {
        return $this->codigoSecretaria;
    }

    public function setCodigoSecretaria(?string $codigoSecretaria): static
    {
        $this->codigoSecretaria = $codigoSecretaria;

        return $this;
    }

    /**
     * IVA del ítem (calculado por InvoiceCalculator con detalle por ítem).
     */
    public function getImporteIva(): ?float
    {
        return $this->importeIva;
    }

    public function setImporteIva(?float $importeIva): static
    {
        $this->importeIva = $importeIva;

        return $this;
    }

    /**
     * Importe del ítem con IVA incluido (calculado por InvoiceCalculator con detalle por ítem).
     */
    public function getImporteItem(): ?float
    {
        return $this->importeItem;
    }

    public function setImporteItem(?float $importeItem): static
    {
        $this->importeItem = $importeItem;

        return $this;
    }

    /**
     * Línea de bonificación o descuento general: resta su importe del comprobante.
     * Indicar el importe positivo; con alícuota si el descuento es sobre ítems gravados.
     */
    public static function descuento(string $descripcion, float $importe, ?int $alicuotaIva = null): self
    {
        $detail = (new self())->setDescripcion($descripcion)->setCantidad(1)->setPrecioUnitario(abs($importe));
        $detail->descuento = true;
        if ($alicuotaIva !== null) {
            $detail->setAlicuotaIva($alicuotaIva);
        }

        return $detail;
    }

    public function isDescuento(): bool
    {
        return $this->descuento;
    }

    public function setDescuento(bool $descuento): static
    {
        $this->descuento = $descuento;

        return $this;
    }

    /**
     * Tipo de ítem de turismo (WSCT consultarTiposItem; 0 = general).
     */
    public function getTipoItem(): int
    {
        return $this->tipoItem;
    }

    public function setTipoItem(int $tipoItem): static
    {
        $this->tipoItem = $tipoItem;

        return $this;
    }

    /**
     * Código de ítem de turismo (WSCT consultarCodigosItemTurismo, ej. 1 = alojamiento sin desayuno).
     */
    public function getCodigoTurismo(): ?int
    {
        return $this->codigoTurismo;
    }

    public function setCodigoTurismo(?int $codigoTurismo): static
    {
        $this->codigoTurismo = $codigoTurismo;

        return $this;
    }

    /**
     * Importe del ítem tal como fue cargado (cantidad × precio − bonificaciones), sin redondear.
     */
    public function getSubtotal(): float
    {
        $gross = $this->cantidad * $this->precioUnitario;
        $gross -= $gross * $this->bonificacionPorcentaje / 100;
        $subtotal = $gross - $this->bonificacion;

        return $this->descuento ? -abs($subtotal) : $subtotal;
    }
}
