<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Model\Response;

use RoyaltyFusion\Afip\Support\Arr;
use RoyaltyFusion\Afip\Support\Date;

/**
 * Resultado de un comprobante dentro de una solicitud de CAE/CAEA (FECAEDetResponse).
 */
final class CaeDetail
{
    /**
     * @param Message[] $observaciones
     * @param array<string, mixed> $raw
     */
    public function __construct(
        private readonly string $resultado,
        private readonly ?string $cae,
        private readonly ?\DateTimeImmutable $caeVencimiento,
        private readonly int $numeroDesde,
        private readonly int $numeroHasta,
        private readonly ?\DateTimeImmutable $fecha,
        private readonly int $docTipo,
        private readonly string $docNro,
        private readonly array $observaciones,
        private readonly array $raw = []
    ) {
    }

    /**
     * @param array<string, mixed> $node
     */
    public static function fromArray(array $node): self
    {
        $obs = array_map(
            static fn (mixed $o): Message => Message::fromArray($o),
            Arr::list(Arr::get($node, 'Observaciones.Obs'))
        );
        $cae = Arr::string($node, 'CAE') ?? Arr::string($node, 'CAEA');

        return new self(
            (string) Arr::string($node, 'Resultado', ''),
            $cae !== null && $cae !== '' ? $cae : null,
            Date::fromAfip(Arr::string($node, 'CAEFchVto')),
            (int) Arr::int($node, 'CbteDesde', 0),
            (int) Arr::int($node, 'CbteHasta', 0),
            Date::fromAfip(Arr::string($node, 'CbteFch')),
            (int) Arr::int($node, 'DocTipo', 0),
            (string) Arr::string($node, 'DocNro', ''),
            $obs,
            $node
        );
    }

    /**
     * A = aprobado, R = rechazado.
     */
    public function getResultado(): string
    {
        return $this->resultado;
    }

    public function isApproved(): bool
    {
        return $this->resultado === 'A';
    }

    public function getCae(): ?string
    {
        return $this->cae;
    }

    public function getCaeVencimiento(): ?\DateTimeImmutable
    {
        return $this->caeVencimiento;
    }

    public function getNumeroDesde(): int
    {
        return $this->numeroDesde;
    }

    public function getNumeroHasta(): int
    {
        return $this->numeroHasta;
    }

    public function getFecha(): ?\DateTimeImmutable
    {
        return $this->fecha;
    }

    public function getDocTipo(): int
    {
        return $this->docTipo;
    }

    public function getDocNro(): string
    {
        return $this->docNro;
    }

    /**
     * @return Message[]
     */
    public function getObservaciones(): array
    {
        return $this->observaciones;
    }

    /**
     * @return array<string, mixed>
     */
    public function getRaw(): array
    {
        return $this->raw;
    }
}
