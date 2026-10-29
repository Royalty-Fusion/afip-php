<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Model\Response;

use RoyaltyFusion\Afip\Support\Arr;
use RoyaltyFusion\Afip\Support\Date;

/**
 * Respuesta de FECAESolicitar / FECAEARegInformativo.
 */
final class AuthorizationResult
{
    private ?string $requestXml = null;
    private ?string $responseXml = null;
    private bool $recovered = false;

    /**
     * @param CaeDetail[] $details
     * @param Message[]   $errors
     * @param Message[]   $events
     * @param array<string, mixed> $raw
     */
    public function __construct(
        private readonly string $resultado,
        private readonly ?string $reproceso,
        private readonly int $puntoVenta,
        private readonly int $tipoComprobante,
        private readonly ?\DateTimeImmutable $fechaProceso,
        private readonly array $details,
        private readonly array $errors,
        private readonly array $events,
        private readonly array $raw = []
    ) {
    }

    /**
     * @param array<string, mixed> $result contenido de FECAESolicitarResult / FECAEARegInformativoResult
     */
    public static function fromArray(array $result): self
    {
        $details = [];
        foreach (['FECAEDetResponse', 'FECAEADetResponse'] as $node) {
            foreach (Arr::list(Arr::get($result, 'FeDetResp.' . $node)) as $detail) {
                if (is_array($detail)) {
                    $details[] = CaeDetail::fromArray($detail);
                }
            }
        }

        return new self(
            (string) Arr::string($result, 'FeCabResp.Resultado', ''),
            Arr::string($result, 'FeCabResp.Reproceso'),
            (int) Arr::int($result, 'FeCabResp.PtoVta', 0),
            (int) Arr::int($result, 'FeCabResp.CbteTipo', 0),
            Date::fromAfip(Arr::string($result, 'FeCabResp.FchProceso')),
            $details,
            array_map(static fn (mixed $e): Message => Message::fromArray($e), Arr::list(Arr::get($result, 'Errors.Err'))),
            array_map(static fn (mixed $e): Message => Message::fromArray($e), Arr::list(Arr::get($result, 'Events.Evt'))),
            $result
        );
    }

    /**
     * Reconstruye un resultado aprobado a partir de un comprobante consultado con
     * FECompConsultar (recuperación tras un error de comunicación).
     */
    public static function fromVoucher(VoucherInfo $voucher): self
    {
        $data = $voucher->toArray();
        $detail = new CaeDetail(
            $voucher->getResultado() !== '' ? $voucher->getResultado() : 'A',
            $voucher->getCodigoAutorizacion(),
            $voucher->getVencimiento(),
            $voucher->getNumero(),
            (int) Arr::int($data, 'CbteHasta', $voucher->getNumero()),
            $voucher->getFecha(),
            $voucher->getDocTipo(),
            $voucher->getDocNro(),
            $voucher->getObservaciones(),
            $data
        );

        return new self(
            $detail->getResultado(),
            'S',
            $voucher->getPuntoVenta(),
            $voucher->getTipoComprobante(),
            $voucher->getFechaProceso(),
            [$detail],
            [],
            [],
            ['ResultGet' => $data]
        );
    }

    /**
     * true si ARCA aprobó todos los comprobantes de la solicitud.
     */
    public function isSuccess(): bool
    {
        if ($this->resultado !== 'A' || $this->errors !== [] || $this->details === []) {
            return false;
        }
        foreach ($this->details as $detail) {
            if (!$detail->isApproved()) {
                return false;
            }
        }

        return true;
    }

    /**
     * A = aprobado, R = rechazado, P = parcial (lotes).
     */
    public function getResultado(): string
    {
        return $this->resultado;
    }

    /**
     * "S" si ARCA devolvió un comprobante ya autorizado previamente (reproceso).
     */
    public function getReproceso(): ?string
    {
        return $this->reproceso;
    }

    public function isReproceso(): bool
    {
        return $this->reproceso === 'S';
    }

    public function getPuntoVenta(): int
    {
        return $this->puntoVenta;
    }

    public function getTipoComprobante(): int
    {
        return $this->tipoComprobante;
    }

    public function getFechaProceso(): ?\DateTimeImmutable
    {
        return $this->fechaProceso;
    }

    /**
     * @return CaeDetail[]
     */
    public function getDetails(): array
    {
        return $this->details;
    }

    public function getDetail(int $index = 0): ?CaeDetail
    {
        return $this->details[$index] ?? null;
    }

    public function getCae(): ?string
    {
        return $this->getDetail()?->getCae();
    }

    public function getCaeVencimiento(): ?\DateTimeImmutable
    {
        return $this->getDetail()?->getCaeVencimiento();
    }

    public function getNumero(): ?int
    {
        return $this->getDetail()?->getNumeroDesde();
    }

    /**
     * Errores generales de la solicitud (nodo Errors).
     *
     * @return Message[]
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Observaciones de todos los comprobantes.
     *
     * @return Message[]
     */
    public function getObservaciones(): array
    {
        $all = [];
        foreach ($this->details as $detail) {
            array_push($all, ...$detail->getObservaciones());
        }

        return $all;
    }

    /**
     * Errores + observaciones (útil para mostrar el motivo de un rechazo).
     *
     * @return Message[]
     */
    public function getMessages(): array
    {
        return [...$this->errors, ...$this->getObservaciones()];
    }

    /**
     * Mensaje de error legible (vacío si fue aprobado sin observaciones).
     */
    public function getErrorMessage(): string
    {
        return implode(' | ', array_map(static fn (Message $m): string => (string) $m, $this->getMessages()));
    }

    /**
     * @return Message[]
     */
    public function getEvents(): array
    {
        return $this->events;
    }

    public function hasCode(int $code): bool
    {
        foreach ($this->getMessages() as $message) {
            if ($message->getCode() === $code) {
                return true;
            }
        }

        return false;
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

    /**
     * true si el resultado se recuperó con FECompConsultar tras un error de comunicación.
     */
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
