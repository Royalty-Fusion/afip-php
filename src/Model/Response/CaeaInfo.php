<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Model\Response;

use RoyaltyFusion\Afip\Support\Arr;
use RoyaltyFusion\Afip\Support\Date;

/**
 * CAEA otorgado (FECAEASolicitar / FECAEAConsultar).
 */
final class CaeaInfo
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private readonly array $data)
    {
    }

    public function getCaea(): string
    {
        return (string) Arr::string($this->data, 'CAEA', '');
    }

    /**
     * Período yyyymm.
     */
    public function getPeriodo(): int
    {
        return (int) Arr::int($this->data, 'Periodo', 0);
    }

    /**
     * 1 = primera quincena, 2 = segunda quincena.
     */
    public function getOrden(): int
    {
        return (int) Arr::int($this->data, 'Orden', 0);
    }

    public function getVigenciaDesde(): ?\DateTimeImmutable
    {
        return Date::fromAfip(Arr::string($this->data, 'FchVigDesde'));
    }

    public function getVigenciaHasta(): ?\DateTimeImmutable
    {
        return Date::fromAfip(Arr::string($this->data, 'FchVigHasta'));
    }

    /**
     * Fecha tope para informar los comprobantes emitidos con este CAEA.
     */
    public function getFechaTopeInformar(): ?\DateTimeImmutable
    {
        return Date::fromAfip(Arr::string($this->data, 'FchTopeInf'));
    }

    public function getFechaProceso(): ?\DateTimeImmutable
    {
        return Date::fromAfip(Arr::string($this->data, 'FchProceso'));
    }

    /**
     * @return Message[]
     */
    public function getObservaciones(): array
    {
        return array_map(static fn (mixed $o): Message => Message::fromArray($o), Arr::list(Arr::get($this->data, 'Observaciones.Obs')));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }
}
