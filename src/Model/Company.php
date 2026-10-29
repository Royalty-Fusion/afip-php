<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Model;

use RoyaltyFusion\Afip\Catalog\CondicionIva;

/**
 * Emisor del comprobante.
 */
class Company
{
    private string $cuit = '';
    private string $razonSocial = '';
    private ?string $nombreFantasia = null;
    private int $condicionIva = CondicionIva::RESPONSABLE_INSCRIPTO;
    private ?string $ingresosBrutos = null;
    private ?\DateTimeInterface $inicioActividades = null;
    private ?Address $address = null;
    private ?string $email = null;
    private ?string $telefono = null;
    private ?string $web = null;

    public function getCuit(): string
    {
        return $this->cuit;
    }

    public function setCuit(string $cuit): static
    {
        $this->cuit = preg_replace('/\D/', '', $cuit) ?? '';

        return $this;
    }

    public function getRazonSocial(): string
    {
        return $this->razonSocial;
    }

    public function setRazonSocial(string $razonSocial): static
    {
        $this->razonSocial = $razonSocial;

        return $this;
    }

    public function getNombreFantasia(): ?string
    {
        return $this->nombreFantasia;
    }

    public function setNombreFantasia(?string $nombreFantasia): static
    {
        $this->nombreFantasia = $nombreFantasia;

        return $this;
    }

    /**
     * Condición frente al IVA del emisor ({@see CondicionIva}).
     */
    public function getCondicionIva(): int
    {
        return $this->condicionIva;
    }

    public function setCondicionIva(int $condicionIva): static
    {
        $this->condicionIva = $condicionIva;

        return $this;
    }

    public function getIngresosBrutos(): ?string
    {
        return $this->ingresosBrutos;
    }

    public function setIngresosBrutos(?string $ingresosBrutos): static
    {
        $this->ingresosBrutos = $ingresosBrutos;

        return $this;
    }

    public function getInicioActividades(): ?\DateTimeInterface
    {
        return $this->inicioActividades;
    }

    public function setInicioActividades(?\DateTimeInterface $inicioActividades): static
    {
        $this->inicioActividades = $inicioActividades;

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

    public function getTelefono(): ?string
    {
        return $this->telefono;
    }

    public function setTelefono(?string $telefono): static
    {
        $this->telefono = $telefono;

        return $this;
    }

    public function getWeb(): ?string
    {
        return $this->web;
    }

    public function setWeb(?string $web): static
    {
        $this->web = $web;

        return $this;
    }
}
