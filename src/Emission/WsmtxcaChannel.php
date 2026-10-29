<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Emission;

use RoyaltyFusion\Afip\Model\Response\AuthorizationResult;
use RoyaltyFusion\Afip\Service\AbstractService;
use RoyaltyFusion\Afip\Service\WsmtxcaService;
use RoyaltyFusion\Afip\Support\Arr;
use RoyaltyFusion\Afip\Support\Date;
use RoyaltyFusion\Afip\Validator\InvoiceValidator;

/**
 * @internal
 */
final class WsmtxcaChannel implements VoucherChannel
{
    public function __construct(private readonly WsmtxcaService $service)
    {
    }

    public function service(): AbstractService
    {
        return $this->service;
    }

    public function validationMode(): string
    {
        return InvoiceValidator::MODE_MTXCA;
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
        return $this->service->authorize($invoices[0]);
    }

    public function numberingConflictCodes(): array
    {
        return [WsmtxcaService::ERROR_NUMERO];
    }

    public function salesPoints(): array
    {
        return $this->service->getSalesPoints('CAE');
    }

    public function maxBatch(): int
    {
        return 1;
    }

    public function supportsReprocess(): bool
    {
        return false;
    }
}
