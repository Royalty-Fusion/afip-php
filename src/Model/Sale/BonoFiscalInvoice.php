<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Model\Sale;

/**
 * Comprobante de Bono Fiscal Electrónico (WSBFEv1 — bienes de capital, Decreto 379/01).
 *
 * Es un {@see Invoice} con detalle de ítems (cada ítem con código NCM) y la zona de
 * producción. Calcular con InvoiceCalculator::calculate($invoice, true).
 */
class BonoFiscalInvoice extends Invoice
{
    /** Id de la solicitud. Si es null se asigna automáticamente (BFEGetLast_ID + 1). */
    private ?int $id = null;
    private int $zona = 1;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): static
    {
        $this->id = $id;

        return $this;
    }

    /**
     * Zona (BFEGetPARAM_Zonas).
     */
    public function getZona(): int
    {
        return $this->zona;
    }

    public function setZona(int $zona): static
    {
        $this->zona = $zona;

        return $this;
    }
}
