<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Model\Sale;

use RoyaltyFusion\Afip\Catalog\TipoComprobante;

/**
 * Nota de crédito o débito. Es un {@see Invoice} con atajos para el comprobante asociado.
 */
class Note extends Invoice
{
    public function __construct()
    {
        parent::__construct();
        $this->setTipoComprobante(TipoComprobante::NOTA_CREDITO_B);
    }

    /**
     * Asocia la nota al comprobante original.
     */
    public function setAfectado(Invoice $original, ?string $cuitEmisor = null): static
    {
        $cuit = $cuitEmisor ?? $original->getCompany()?->getCuit();

        return $this->setCbtesAsociados([
            new CbteAsociado(
                $original->getTipoComprobante(),
                $original->getPuntoVenta(),
                (int) $original->getNumero(),
                $cuit !== '' ? $cuit : null,
                $original->getFechaEmision()
            ),
        ]);
    }
}
