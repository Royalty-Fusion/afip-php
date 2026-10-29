<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Auth;

/**
 * Adaptador para cualquier caché PSR-16 (Redis, Memcached, Laravel, Symfony...).
 *
 * Se tipa como object para no obligar a instalar psr/simple-cache: basta con que el
 * objeto tenga los métodos get($key), set($key, $value, $ttl) y delete($key).
 */
final class CacheTicketStorage implements TicketStorageInterface
{
    public function __construct(private readonly object $cache, private readonly string $prefix = 'afip_ta_')
    {
        foreach (['get', 'set', 'delete'] as $method) {
            if (!method_exists($cache, $method)) {
                throw new \InvalidArgumentException(sprintf('La caché debe implementar %s().', $method));
            }
        }
    }

    public function get(string $key): ?AccessTicket
    {
        $data = $this->cache->get($this->key($key));
        if (is_string($data)) {
            $data = json_decode($data, true);
        }

        return is_array($data) ? AccessTicket::fromArray($data) : null;
    }

    public function set(string $key, AccessTicket $ticket): void
    {
        $ttl = max(60, $ticket->getExpirationTime()->getTimestamp() - time());
        $this->cache->set($this->key($key), json_encode($ticket->toArray()), $ttl);
    }

    public function delete(string $key): void
    {
        $this->cache->delete($this->key($key));
    }

    private function key(string $key): string
    {
        // PSR-16 no admite {}()/\@: en las claves.
        return $this->prefix . preg_replace('/[^A-Za-z0-9_.]/', '_', $key);
    }
}
