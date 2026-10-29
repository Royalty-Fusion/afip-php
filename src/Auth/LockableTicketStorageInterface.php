<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Auth;

/**
 * Almacenamiento de tickets que puede bloquear una clave entre procesos, para que dos
 * procesos no pidan un TA al WSAA al mismo tiempo (ARCA respondería coe.alreadyAuthenticated).
 */
interface LockableTicketStorageInterface extends TicketStorageInterface
{
    /**
     * Ejecuta $callback con un bloqueo exclusivo sobre la clave.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function withLock(string $key, callable $callback): mixed;
}
