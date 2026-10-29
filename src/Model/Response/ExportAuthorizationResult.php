<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Model\Response;

use RoyaltyFusion\Afip\Support\Arr;
use RoyaltyFusion\Afip\Support\Date;

/**
 * Respuesta de FEXAuthorize.
 */
final class ExportAuthorizationResult
{
    private ?string $requestXml = null;
    private ?string $responseXml = null;
    private bool $recovered = false;

    /**
     * @param Message[] $errors
     * @param Message[] $events
     * @param array<string, mixed> $raw
     */
    public function __construct(
        private readonly string $resultado,
        private readonly ?string $cae,
        private readonly ?\DateTimeImmutable $caeVencimiento,
        private readonly int $numero,
        private readonly ?\DateTimeImmutable $fecha,
        private readonly ?string $reproceso,
        private readonly ?string $motivosObservacion,
        private readonly array $errors,
        private readonly array $events,
        private readonly array $raw = []
    ) {
    }

    /**
     * @param array<string, mixed> $result contenido de FEXAuthorizeResult
     */
    public static function fromArray(array $result): self
    {
        $errors = [];
        $err = Arr::get($result, 'FEXErr');
        if (is_array($err) && (int) Arr::int($err, 'ErrCode', 0) !== 0) {
            $errors[] = Message::fromArray($err);
        }
        $events = [];
        $evt = Arr::get($result, 'FEXEvents');
        if (is_array($evt) && (int) Arr::int($evt, 'EventCode', 0) !== 0) {
            $events[] = Message::fromArray($evt);
        }
        $cae = Arr::string($result, 'FEXResultAuth.Cae');

        return new self(
            (string) Arr::string($result, 'FEXResultAuth.Resultado', ''),
            $cae !== null && $cae !== '' ? $cae : null,
            Date::fromAfip(Arr::string($result, 'FEXResultAuth.Fch_venc_Cae')),
            (int) Arr::int($result, 'FEXResultAuth.Cbte_nro', 0),
            Date::fromAfip(Arr::string($result, 'FEXResultAuth.Fch_cbte')),
            Arr::string($result, 'FEXResultAuth.Reproceso'),
            Arr::string($result, 'FEXResultAuth.Motivos_Obs'),
            $errors,
            $events,
            $result
        );
    }

    public function isSuccess(): bool
    {
        return $this->resultado === 'A' && $this->errors === [] && $this->cae !== null;
    }

    public function getResultado(): string
    {
        return $this->resultado;
    }

    public function getCae(): ?string
    {
        return $this->cae;
    }

    public function getCaeVencimiento(): ?\DateTimeImmutable
    {
        return $this->caeVencimiento;
    }

    public function getNumero(): int
    {
        return $this->numero;
    }

    public function getFecha(): ?\DateTimeImmutable
    {
        return $this->fecha;
    }

    public function getReproceso(): ?string
    {
        return $this->reproceso;
    }

    /**
     * Observaciones devueltas por ARCA (Motivos_Obs).
     */
    public function getMotivosObservacion(): ?string
    {
        return $this->motivosObservacion === '' ? null : $this->motivosObservacion;
    }

    /**
     * @return Message[]
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getErrorMessage(): string
    {
        $parts = array_map(static fn (Message $m): string => (string) $m, $this->errors);
        if ($this->getMotivosObservacion() !== null) {
            $parts[] = $this->getMotivosObservacion();
        }

        return implode(' | ', $parts);
    }

    /**
     * @return Message[]
     */
    public function getEvents(): array
    {
        return $this->events;
    }

    /**
     * @return array<string, mixed>
     */
    public function getRaw(): array
    {
        return $this->raw;
    }

    public function getRequestXml(): ?string
    {
        return $this->requestXml;
    }

    public function getResponseXml(): ?string
    {
        return $this->responseXml;
    }

    /**
     * @internal
     */
    public function setXml(?string $request, ?string $response): self
    {
        $this->requestXml = $request;
        $this->responseXml = $response;

        return $this;
    }

    public function isRecovered(): bool
    {
        return $this->recovered;
    }

    /**
     * @internal
     */
    public function markRecovered(): self
    {
        $this->recovered = true;

        return $this;
    }
}
