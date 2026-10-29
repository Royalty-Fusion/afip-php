<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Model\Sale;

/**
 * Subtotal por alícuota de IVA (nodo AlicIva).
 */
class Iva
{
    public function __construct(
        private int $id,
        private float $baseImp,
        private float $importe
    ) {
    }

    /**
     * Id de alícuota ({@see \RoyaltyFusion\Afip\Catalog\AlicuotaIva}).
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

    public function getBaseImp(): float
    {
        return $this->baseImp;
    }

    public function setBaseImp(float $baseImp): static
    {
        $this->baseImp = $baseImp;

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
