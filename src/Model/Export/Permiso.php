<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Model\Export;

/**
 * Permiso de embarque (nodo Permiso de WSFEX).
 */
class Permiso
{
    public function __construct(private string $id, private int $paisDestino)
    {
    }

    /**
     * Número de permiso de embarque / destinación (16 caracteres).
     */
    public function getId(): string
    {
        return $this->id;
    }

    public function setId(string $id): static
    {
        $this->id = $id;

        return $this;
    }

    /**
     * País de destino de la mercadería (FEXGetPARAM_DST_pais).
     */
    public function getPaisDestino(): int
    {
        return $this->paisDestino;
    }

    public function setPaisDestino(int $paisDestino): static
    {
        $this->paisDestino = $paisDestino;

        return $this;
    }
}
