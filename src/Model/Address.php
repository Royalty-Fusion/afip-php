<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Model;

/**
 * Domicilio (para la representación impresa y datos de padrón).
 */
class Address
{
    private ?string $direccion = null;
    private ?string $localidad = null;
    private ?string $provincia = null;
    private ?int $idProvincia = null;
    private ?string $codigoPostal = null;
    private ?string $pais = null;

    public function getDireccion(): ?string
    {
        return $this->direccion;
    }

    public function setDireccion(?string $direccion): static
    {
        $this->direccion = $direccion;

        return $this;
    }

    public function getLocalidad(): ?string
    {
        return $this->localidad;
    }

    public function setLocalidad(?string $localidad): static
    {
        $this->localidad = $localidad;

        return $this;
    }

    public function getProvincia(): ?string
    {
        return $this->provincia;
    }

    public function setProvincia(?string $provincia): static
    {
        $this->provincia = $provincia;

        return $this;
    }

    public function getIdProvincia(): ?int
    {
        return $this->idProvincia;
    }

    public function setIdProvincia(?int $idProvincia): static
    {
        $this->idProvincia = $idProvincia;

        return $this;
    }

    public function getCodigoPostal(): ?string
    {
        return $this->codigoPostal;
    }

    public function setCodigoPostal(?string $codigoPostal): static
    {
        $this->codigoPostal = $codigoPostal;

        return $this;
    }

    public function getPais(): ?string
    {
        return $this->pais;
    }

    public function setPais(?string $pais): static
    {
        $this->pais = $pais;

        return $this;
    }

    public function __toString(): string
    {
        $parts = array_filter([
            $this->direccion,
            $this->localidad,
            $this->codigoPostal !== null ? 'CP ' . $this->codigoPostal : null,
            $this->provincia,
            $this->pais,
        ], static fn (?string $v): bool => $v !== null && $v !== '');

        return implode(' - ', $parts);
    }
}
