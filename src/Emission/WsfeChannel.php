<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Emission;

use RoyaltyFusion\Afip\Model\Response\AuthorizationResult;
use RoyaltyFusion\Afip\Service\AbstractService;
use RoyaltyFusion\Afip\Service\WsfeService;
use RoyaltyFusion\Afip\Validator\InvoiceValidator;

/**
 * @internal
 */
final class WsfeChannel implements VoucherChannel
{
    public function __construct(private readonly WsfeService $wsfe, private readonly int $maxBatch = 250)
    {
    }

    public function service(): AbstractService
    {
        return $this->wsfe;
    }

    public function validationMode(): string
    {
        return InvoiceValidator::MODE_CAE;
    }

    public function lastNumber(int $puntoVenta, int $tipo): int
    {
        return $this->wsfe->getLastVoucher($puntoVenta, $tipo);
    }

    public function find(int $tipo, int $puntoVenta, int $numero): ?FoundVoucher
    {
        $voucher = $this->wsfe->getVoucher($tipo, $puntoVenta, $numero);
        if ($voucher === null) {
            return null;
        }

        return new FoundVoucher(
            $voucher->getNumero(),
            $voucher->getCodigoAutorizacion(),
            $voucher->getVencimiento(),
            $voucher->getImpTotal(),
            $voucher->getDocTipo(),
            $voucher->getDocNro(),
            $voucher->getFecha(),
            $voucher->toArray()
        );
    }

    public function authorize(array $invoices): AuthorizationResult
    {
        return $this->wsfe->authorize($invoices);
    }

    public function numberingConflictCodes(): array
    {
        return [10016];
    }

    public function salesPoints(): ?array
    {
        return array_map(static fn (array $p): array => [
            'numero' => (int) ($p['Nro'] ?? 0),
            'bloqueado' => strtoupper((string) ($p['Bloqueado'] ?? 'N')) === 'S',
            'baja' => isset($p['FchBaja']) && is_string($p['FchBaja']) && strtoupper(trim($p['FchBaja'])) !== 'NULL' && trim($p['FchBaja']) !== ''
                ? trim($p['FchBaja'])
                : null,
        ], $this->wsfe->getSalesPoints());
    }

    public function maxBatch(): int
    {
        return $this->maxBatch;
    }
}
