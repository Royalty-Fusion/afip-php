<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Model\Sale;

/**
 * Dato opcional (nodo Opcional).
 */
class Opcional
{
    private string $id;

    public function __construct(string|int $id, private string $valor)
    {
        $this->id = (string) $id;
    }

    /**
     * Id del opcional ({@see \RoyaltyFusion\Afip\Catalog\TipoOpcional}).
     */
    public function getId(): string
    {
        return $this->id;
    }

    public function setId(string|int $id): static
    {
        $this->id = (string) $id;

        return $this;
    }

    public function getValor(): string
    {
        return $this->valor;
    }

    public function setValor(string $valor): static
    {
        $this->valor = $valor;

        return $this;
    }
}
