<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Model\Response;

use RoyaltyFusion\Afip\Support\Arr;
use RoyaltyFusion\Afip\Support\Date;

/**
 * Comprobante ya autorizado, devuelto por FECompConsultar.
 */
final class VoucherInfo
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private readonly array $data)
    {
    }

    public function getTipoComprobante(): int
    {
        return (int) Arr::int($this->data, 'CbteTipo', 0);
    }

    public function getPuntoVenta(): int
    {
        return (int) Arr::int($this->data, 'PtoVta', 0);
    }

    public function getNumero(): int
    {
        return (int) Arr::int($this->data, 'CbteDesde', 0);
    }

    public function getFecha(): ?\DateTimeImmutable
    {
        return Date::fromAfip(Arr::string($this->data, 'CbteFch'));
    }

    public function getConcepto(): int
    {
        return (int) Arr::int($this->data, 'Concepto', 0);
    }

    public function getDocTipo(): int
    {
        return (int) Arr::int($this->data, 'DocTipo', 0);
    }

    public function getDocNro(): string
    {
        return (string) Arr::string($this->data, 'DocNro', '');
    }

    public function getImpTotal(): float
    {
        return (float) Arr::float($this->data, 'ImpTotal', 0.0);
    }

    public function getImpNeto(): float
    {
        return (float) Arr::float($this->data, 'ImpNeto', 0.0);
    }

    public function getImpIva(): float
    {
        return (float) Arr::float($this->data, 'ImpIVA', 0.0);
    }

    public function getMoneda(): string
    {
        return (string) Arr::string($this->data, 'MonId', '');
    }

    public function getCotizacion(): float
    {
        return (float) Arr::float($this->data, 'MonCotiz', 1.0);
    }

    public function getResultado(): string
    {
        return (string) Arr::string($this->data, 'Resultado', '');
    }

    /**
     * CAE o CAEA.
     */
    public function getCodigoAutorizacion(): ?string
    {
        return Arr::string($this->data, 'CodAutorizacion');
    }

    /**
     * "CAE" o "CAEA".
     */
    public function getEmisionTipo(): ?string
    {
        return Arr::string($this->data, 'EmisionTipo');
    }

    public function getVencimiento(): ?\DateTimeImmutable
    {
        return Date::fromAfip(Arr::string($this->data, 'FchVto'));
    }

    public function getFechaProceso(): ?\DateTimeImmutable
    {
        return Date::fromAfip(Arr::string($this->data, 'FchProceso'));
    }

    public function getCondicionIvaReceptor(): ?int
    {
        return Arr::int($this->data, 'CondicionIVAReceptorId');
    }

    /**
     * @return Message[]
     */
    public function getObservaciones(): array
    {
        return array_map(static fn (mixed $o): Message => Message::fromArray($o), Arr::list(Arr::get($this->data, 'Observaciones.Obs')));
    }

    /**
     * Datos completos tal como los devuelve ARCA.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }
}
