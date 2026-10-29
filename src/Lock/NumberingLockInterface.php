<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Lock;

/**
 * Bloqueo exclusivo entre procesos para serializar la numeración de comprobantes
 * (último autorizado + 1 → autorización) por ambiente, CUIT, punto de venta y tipo.
 */
interface NumberingLockInterface
{
    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function run(string $key, callable $callback): mixed;
}
