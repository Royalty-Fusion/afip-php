<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Model;

use RoyaltyFusion\Afip\Catalog\CondicionIva;
use RoyaltyFusion\Afip\Catalog\TipoDocumento;

/**
 * Receptor del comprobante.
 */
class Client
{
    private int $tipoDoc = TipoDocumento::OTRO;
    private string $numDoc = '0';
    private ?string $razonSocial = null;
    private ?int $condicionIva = null;
    private ?Address $address = null;
    private ?string $email = null;

    /**
     * Consumidor final sin identificar (DocTipo 99, DocNro 0).
     */
    public static function consumidorFinal(): self
    {
        return (new self())
            ->setTipoDoc(TipoDocumento::OTRO)
            ->setNumDoc('0')
            ->setCondicionIva(CondicionIva::CONSUMIDOR_FINAL)
            ->setRazonSocial('Consumidor Final');
    }

    public function getTipoDoc(): int
    {
        return $this->tipoDoc;
    }

    /**
     * Tipo de documento ({@see TipoDocumento}).
     */
    public function setTipoDoc(int $tipoDoc): static
    {
        $this->tipoDoc = $tipoDoc;

        return $this;
    }

    public function getNumDoc(): string
    {
        return $this->numDoc;
    }

    public function setNumDoc(string|int $numDoc): static
    {
        $this->numDoc = preg_replace('/[\s.\-]/', '', (string) $numDoc) ?? '';

        return $this;
    }

    public function getRazonSocial(): ?string
    {
        return $this->razonSocial;
    }

    public function setRazonSocial(?string $razonSocial): static
    {
        $this->razonSocial = $razonSocial;

        return $this;
    }

    /**
     * Condición frente al IVA del receptor (RG 5616, {@see CondicionIva}).
     */
    public function getCondicionIva(): ?int
    {
        return $this->condicionIva;
    }

    public function setCondicionIva(?int $condicionIva): static
    {
        $this->condicionIva = $condicionIva;

        return $this;
    }

    public function getAddress(): ?Address
    {
        return $this->address;
    }

    public function setAddress(?Address $address): static
    {
        $this->address = $address;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }
}
