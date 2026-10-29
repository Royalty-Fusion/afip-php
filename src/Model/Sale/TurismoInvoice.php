<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Model\Sale;

use RoyaltyFusion\Afip\Catalog\TipoComprobante;

/**
 * Comprobante de turismo clase T (WSCT): servicios turísticos prestados a no residentes
 * con reintegro de IVA (RG 3971 y modificatorias).
 *
 * El número de documento del receptor admite letras (pasaporte). La condición frente al
 * IVA del receptor (Client::setCondicionIva) se informa como idImpositivo.
 *
 * ImpTotal = gravado + no gravado + exento + IVA + otros tributos + reintegro (negativo).
 */
class TurismoInvoice extends Invoice
{
    private ?int $codigoPais = null;
    private ?string $domicilioReceptor = null;
    private int $codigoRelacionEmisorReceptor = 0;
    private float $importeReintegro = 0.0;
    /** @var FormaPago[] */
    private array $formasPago = [];

    public function __construct()
    {
        parent::__construct();
        $this->setTipoComprobante(TipoComprobante::FACTURA_T);
    }

    /**
     * País del receptor (consultarPaises).
     */
    public function getCodigoPais(): ?int
    {
        return $this->codigoPais;
    }

    public function setCodigoPais(?int $codigoPais): static
    {
        $this->codigoPais = $codigoPais;

        return $this;
    }

    public function getDomicilioReceptor(): ?string
    {
        return $this->domicilioReceptor;
    }

    public function setDomicilioReceptor(?string $domicilioReceptor): static
    {
        $this->domicilioReceptor = $domicilioReceptor;

        return $this;
    }

    /**
     * Relación emisor-receptor (consultarRelacionEmisorReceptor, ej. 3 = alojamiento directo a turista no residente).
     */
    public function getCodigoRelacionEmisorReceptor(): int
    {
        return $this->codigoRelacionEmisorReceptor;
    }

    public function setCodigoRelacionEmisorReceptor(int $codigo): static
    {
        $this->codigoRelacionEmisorReceptor = $codigo;

        return $this;
    }

    /**
     * Reintegro de IVA (importe negativo o cero).
     */
    public function getImporteReintegro(): float
    {
        return $this->importeReintegro;
    }

    public function setImporteReintegro(float $importeReintegro): static
    {
        $this->importeReintegro = $importeReintegro;

        return $this;
    }

    /**
     * @return FormaPago[]
     */
    public function getFormasPago(): array
    {
        return $this->formasPago;
    }

    /**
     * @param FormaPago[] $formasPago
     */
    public function setFormasPago(array $formasPago): static
    {
        $this->formasPago = array_values($formasPago);

        return $this;
    }

    public function addFormaPago(FormaPago $formaPago): static
    {
        $this->formasPago[] = $formaPago;

        return $this;
    }
}
