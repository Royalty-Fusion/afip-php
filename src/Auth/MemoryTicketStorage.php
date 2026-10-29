<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Auth;

/**
 * Almacenamiento en memoria (solo dura lo que dura el proceso). Útil para tests o
 * procesos de larga duración. En entornos web usar {@see FileTicketStorage} o
 * {@see CacheTicketStorage}.
 */
final class MemoryTicketStorage implements TicketStorageInterface
{
    /** @var array<string, AccessTicket> */
    private array $tickets = [];

    public function get(string $key): ?AccessTicket
    {
        return $this->tickets[$key] ?? null;
    }

    public function set(string $key, AccessTicket $ticket): void
    {
        $this->tickets[$key] = $ticket;
    }

    public function delete(string $key): void
    {
        unset($this->tickets[$key]);
    }
}
