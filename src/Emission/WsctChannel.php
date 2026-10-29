<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Emission;

use RoyaltyFusion\Afip\Model\Response\AuthorizationResult;
use RoyaltyFusion\Afip\Model\Sale\TurismoInvoice;
use RoyaltyFusion\Afip\Service\AbstractService;
use RoyaltyFusion\Afip\Service\WsctService;
use RoyaltyFusion\Afip\Support\Arr;
use RoyaltyFusion\Afip\Support\Date;
use RoyaltyFusion\Afip\Validator\InvoiceValidator;

/**
 * @internal
 */
final class WsctChannel implements VoucherChannel
{
    public function __construct(private readonly WsctService $service)
    {
    }

    public function service(): AbstractService
    {
        return $this->service;
    }

    public function validationMode(): string
    {
        return InvoiceValidator::MODE_CT;
    }

    public function lastNumber(int $puntoVenta, int $tipo): int
    {
        return $this->service->getLastVoucher($puntoVenta, $tipo);
    }

    public function find(int $tipo, int $puntoVenta, int $numero): ?FoundVoucher
    {
        $c = $this->service->getVoucher($tipo, $puntoVenta, $numero);
        if ($c === null) {
            return null;
        }

        return new FoundVoucher(
            (int) Arr::int($c, 'numeroComprobante', $numero),
            Arr::string($c, 'codigoAutorizacion'),
            Date::fromAfip(Arr::string($c, 'fechaVencimiento')),
            (float) Arr::float($c, 'importeTotal', 0.0),
            (int) Arr::int($c, 'codigoTipoDocumento', 0),
            (string) Arr::string($c, 'numeroDocumento', ''),
            Date::fromAfip(Arr::string($c, 'fechaEmision')),
            $c
        );
    }

    public function authorize(array $invoices): AuthorizationResult
    {
        $invoice = $invoices[0];
        if (!$invoice instanceof TurismoInvoice) {
            throw new \InvalidArgumentException('WSCT requiere un TurismoInvoice.');
        }

        return $this->service->authorize($invoice);
    }

    public function numberingConflictCodes(): array
    {
        return [];
    }

    public function salesPoints(): ?array
    {
        return $this->service->getSalesPoints();
    }

    public function maxBatch(): int
    {
        return 1;
    }
}
