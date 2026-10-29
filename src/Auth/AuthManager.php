<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Auth;

use RoyaltyFusion\Afip\Environment;
use RoyaltyFusion\Afip\Exception\WsaaException;

/**
 * Administra los Tickets de Acceso: los reutiliza desde el almacenamiento mientras estén
 * vigentes y solo solicita uno nuevo al WSAA cuando es necesario.
 */
final class AuthManager
{
    /** @var array<string, AccessTicket> */
    private array $memory = [];

    public function __construct(
        private readonly Wsaa $wsaa,
        private readonly TicketStorageInterface $storage = new MemoryTicketStorage(),
        private readonly Environment $environment = Environment::HOMOLOGACION,
        private readonly int $alreadyAuthenticatedWaitSeconds = 5
    ) {
    }

    public function getTicket(string $service, bool $forceRefresh = false): AccessTicket
    {
        $key = $this->key($service);

        if (!$forceRefresh) {
            $ticket = $this->memory[$key] ?? $this->storage->get($key);
            if ($ticket !== null && $ticket->isValid()) {
                return $this->memory[$key] = $ticket;
            }
        }

        $login = function () use ($key, $service, $forceRefresh): AccessTicket {
            // Otro proceso pudo haber renovado el ticket mientras esperábamos el lock.
            if (!$forceRefresh) {
                $ticket = $this->storage->get($key);
                if ($ticket !== null && $ticket->isValid()) {
                    return $ticket;
                }
            }

            return $this->login($key, $service);
        };

        $ticket = $this->storage instanceof LockableTicketStorageInterface
            ? $this->storage->withLock($key, $login)
            : $login();

        return $this->memory[$key] = $ticket;
    }

    public function invalidate(string $service): void
    {
        $key = $this->key($service);
        unset($this->memory[$key]);
        $this->storage->delete($key);
    }

    private function login(string $key, string $service): AccessTicket
    {
        try {
            $ticket = $this->wsaa->login($service);
        } catch (WsaaException $e) {
            if (!$e->isAlreadyAuthenticated()) {
                throw $e;
            }
            // Otro proceso pidió un TA al mismo tiempo: se espera a que lo guarde.
            $deadline = microtime(true) + $this->alreadyAuthenticatedWaitSeconds;
            while (microtime(true) < $deadline) {
                usleep(250_000);
                $ticket = $this->storage->get($key);
                if ($ticket !== null && $ticket->isValid()) {
                    return $ticket;
                }
            }
            throw new WsaaException(
                'ARCA indica que ya existe un Ticket de Acceso vigente para este certificado y servicio '
                . '(coe.alreadyAuthenticated), pero no está en el almacenamiento local. Configure un '
                . 'almacenamiento persistente (FileTicketStorage / CacheTicketStorage) y espere a que '
                . 'el ticket anterior venza (hasta 12 horas) o utilice otro certificado.',
                $e->getFaultCode(),
                $e
            );
        }

        $this->storage->set($key, $ticket);

        return $ticket;
    }

    private function key(string $service): string
    {
        return sprintf('%s_%s_%s', $this->environment->value, $service, $this->wsaa->getCertificate()->getFingerprint());
    }
}
