<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Lock;

use RoyaltyFusion\Afip\Exception\AfipException;

/**
 * Bloqueo con nombre sobre una conexión PDO:
 *  - MySQL / MariaDB: GET_LOCK / RELEASE_LOCK
 *  - PostgreSQL: pg_advisory_lock / pg_advisory_unlock
 *  - SQLite: transacción BEGIN IMMEDIATE
 *  - otros motores: sin bloqueo (se ejecuta directamente)
 */
final class PdoLock implements NumberingLockInterface
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly int $timeoutSeconds = 30,
        private readonly string $prefix = 'afip:'
    ) {
    }

    public function run(string $key, callable $callback): mixed
    {
        $name = self::name($this->prefix . $key);

        switch ($this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)) {
            case 'mysql':
                $stmt = $this->pdo->prepare('SELECT GET_LOCK(:name, :timeout)');
                $stmt->execute(['name' => $name, 'timeout' => $this->timeoutSeconds]);
                if ((int) $stmt->fetchColumn() !== 1) {
                    throw new AfipException(sprintf('No se pudo obtener el bloqueo "%s" en %d segundos.', $name, $this->timeoutSeconds));
                }
                try {
                    return $callback();
                } finally {
                    $this->pdo->prepare('SELECT RELEASE_LOCK(:name)')->execute(['name' => $name]);
                }
                // no break
            case 'pgsql':
                $id = self::bigint($name);
                $this->pdo->prepare('SELECT pg_advisory_lock(:id)')->execute(['id' => $id]);
                try {
                    return $callback();
                } finally {
                    $this->pdo->prepare('SELECT pg_advisory_unlock(:id)')->execute(['id' => $id]);
                }
                // no break
            case 'sqlite':
                if ($this->pdo->inTransaction()) {
                    return $callback();
                }
                $this->pdo->exec('BEGIN IMMEDIATE');
                try {
                    $result = $callback();
                    $this->pdo->exec('COMMIT');

                    return $result;
                } catch (\Throwable $e) {
                    $this->pdo->exec('ROLLBACK');
                    throw $e;
                }
                // no break
            default:
                return $callback();
        }
    }

    /**
     * Nombre de lock válido (MySQL admite hasta 64 caracteres).
     */
    private static function name(string $key): string
    {
        return strlen($key) <= 64 ? $key : substr($key, 0, 23) . sha1($key);
    }

    private static function bigint(string $name): int
    {
        return (int) unpack('J', substr(hash('sha256', $name, true), 0, 8))[1] & PHP_INT_MAX;
    }
}
