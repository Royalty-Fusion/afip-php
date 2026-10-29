<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Emission;

use RoyaltyFusion\Afip\Model\Response\AuthorizationResult;
use RoyaltyFusion\Afip\Model\Sale\Invoice;
use RoyaltyFusion\Afip\Service\AbstractService;

/**
 * Adaptador de un web service de emisión (WSFEv1, WSMTXCA, WSCT) para el flujo común de
 * {@see \RoyaltyFusion\Afip\See}: numeración, autorización, reintentos y recuperación.
 *
 * @internal
 */
interface VoucherChannel
{
    public function service(): AbstractService;

    /** Modo de validación de {@see \RoyaltyFusion\Afip\Validator\InvoiceValidator}. */
    public function validationMode(): string;

    public function lastNumber(int $puntoVenta, int $tipo): int;

    public function find(int $tipo, int $puntoVenta, int $numero): ?FoundVoucher;

    /**
     * @param Invoice[] $invoices
     */
    public function authorize(array $invoices): AuthorizationResult;

    /**
     * Códigos con los que el servicio informa que el número ya fue usado.
     *
     * @return int[]
     */
    public function numberingConflictCodes(): array;

    /**
     * Puntos de venta habilitados, o null si el servicio no permite consultarlos.
     *
     * @return array<int, array{numero: int, bloqueado: bool, baja: ?string}>|null
     */
    public function salesPoints(): ?array;

    /** Máxima cantidad de comprobantes por solicitud. */
    public function maxBatch(): int;
}
