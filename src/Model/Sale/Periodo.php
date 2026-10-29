<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Model\Sale;

/**
 * Período de comprobantes asociados (nodo PeriodoAsoc, RG 4540). Alternativa a
 * CbtesAsoc en notas de crédito/débito que ajustan un período completo.
 */
class Periodo
{
    public function __construct(private \DateTimeInterface $desde, private \DateTimeInterface $hasta)
    {
    }

    public function getDesde(): \DateTimeInterface
    {
        return $this->desde;
    }

    public function setDesde(\DateTimeInterface $desde): static
    {
        $this->desde = $desde;

        return $this;
    }

    public function getHasta(): \DateTimeInterface
    {
        return $this->hasta;
    }

    public function setHasta(\DateTimeInterface $hasta): static
    {
        $this->hasta = $hasta;

        return $this;
    }
}
