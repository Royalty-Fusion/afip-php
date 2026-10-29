<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Model\Sale;

/**
 * Otros tributos (percepciones, impuestos internos, etc.) — nodo Tributo.
 */
class Tributo
{
    public function __construct(
        private int $id,
        private string $descripcion,
        private float $baseImp,
        private float $alicuota,
        private float $importe
    ) {
    }

    /**
     * Tipo de tributo ({@see \RoyaltyFusion\Afip\Catalog\TipoTributo}).
     */
    public function getId(): int
    {
        return $this->id;
    }

    public function setId(int $id): static
    {
        $this->id = $id;

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

    public function getBaseImp(): float
    {
        return $this->baseImp;
    }

    public function setBaseImp(float $baseImp): static
    {
        $this->baseImp = $baseImp;

        return $this;
    }

    /**
     * Alícuota en porcentaje (ej. 3.5).
     */
    public function getAlicuota(): float
    {
        return $this->alicuota;
    }

    public function setAlicuota(float $alicuota): static
    {
        $this->alicuota = $alicuota;

        return $this;
    }

    public function getImporte(): float
    {
        return $this->importe;
    }

    public function setImporte(float $importe): static
    {
        $this->importe = $importe;

        return $this;
    }
}
