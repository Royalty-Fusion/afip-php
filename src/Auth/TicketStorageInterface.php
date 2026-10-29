<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Auth;

/**
 * Almacenamiento de Tickets de Acceso para reutilizarlos entre requests/procesos.
 */
interface TicketStorageInterface
{
    public function get(string $key): ?AccessTicket;

    public function set(string $key, AccessTicket $ticket): void;

    public function delete(string $key): void;
}
