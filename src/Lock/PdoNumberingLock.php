<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Lock;

/**
 * Bloqueo de numeración en base de datos (MySQL/MariaDB GET_LOCK, PostgreSQL advisory lock).
 * Funciona entre servidores que comparten la base, a diferencia del bloqueo por archivos.
 */
final class PdoNumberingLock implements NumberingLockInterface
{
    private PdoLock $lock;

    public function __construct(\PDO $pdo, int $timeoutSeconds = 30)
    {
        $this->lock = new PdoLock($pdo, $timeoutSeconds, 'afip-num:');
    }

    public function run(string $key, callable $callback): mixed
    {
        return $this->lock->run($key, $callback);
    }
}
