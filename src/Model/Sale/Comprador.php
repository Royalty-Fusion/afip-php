<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Model\Sale;

/**
 * Comprador adicional (nodo Comprador, RG 4109 — bienes registrables con varios titulares).
 */
class Comprador
{
    public function __construct(
        private int $tipoDoc,
        private string $numDoc,
        private float $porcentaje
    ) {
    }

    public function getTipoDoc(): int
    {
        return $this->tipoDoc;
    }

    public function setTipoDoc(int $tipoDoc): static
    {
        $this->tipoDoc = $tipoDoc;

        return $this;
    }

    public function getNumDoc(): string
    {
        return $this->numDoc;
    }

    public function setNumDoc(string $numDoc): static
    {
        $this->numDoc = $numDoc;

        return $this;
    }

    /**
     * Porcentaje de titularidad (la suma de todos los compradores debe ser 100).
     */
    public function getPorcentaje(): float
    {
        return $this->porcentaje;
    }

    public function setPorcentaje(float $porcentaje): static
    {
        $this->porcentaje = $porcentaje;

        return $this;
    }
}
