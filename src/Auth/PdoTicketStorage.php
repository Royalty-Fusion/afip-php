<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Auth;

use RoyaltyFusion\Afip\Lock\PdoLock;

/**
 * Guarda los Tickets de Acceso en una tabla de la base de datos (sobrevive a despliegues
 * y se comparte entre servidores). Solo requiere ext-pdo.
 *
 * Tabla (ver {@see self::createTable()} o docs/SYMFONY.md):
 *   ticket_key VARCHAR(191) PK, token TEXT, sign TEXT, service VARCHAR(100),
 *   generation_time VARCHAR(40), expiration_time VARCHAR(40), updated_at VARCHAR(40)
 */
final class PdoTicketStorage implements LockableTicketStorageInterface
{
    private PdoLock $lock;

    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $table = 'afip_tickets',
        int $lockTimeoutSeconds = 30
    ) {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $table)) {
            throw new \InvalidArgumentException(sprintf('Nombre de tabla inválido: "%s".', $table));
        }
        $this->lock = new PdoLock($pdo, $lockTimeoutSeconds, 'afip-ta:');
    }

    public function get(string $key): ?AccessTicket
    {
        $stmt = $this->pdo->prepare(sprintf(
            'SELECT token, sign, service, generation_time, expiration_time FROM %s WHERE ticket_key = :k',
            $this->table
        ));
        $stmt->execute(['k' => $key]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        try {
            return AccessTicket::fromArray([
                'token' => $row['token'],
                'sign' => $row['sign'],
                'service' => $row['service'],
                'generationTime' => $row['generation_time'],
                'expirationTime' => $row['expiration_time'],
            ]);
        } catch (\Throwable) {
            return null;
        }
    }

    public function set(string $key, AccessTicket $ticket): void
    {
        $data = $ticket->toArray();
        $params = [
            'k' => $key,
            't' => $data['token'],
            's' => $data['sign'],
            'srv' => $data['service'],
            'g' => $data['generationTime'],
            'e' => $data['expirationTime'],
            'u' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];
        $columns = '(ticket_key, token, sign, service, generation_time, expiration_time, updated_at) VALUES (:k, :t, :s, :srv, :g, :e, :u)';
        $sql = match ($this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)) {
            'mysql' => sprintf('INSERT INTO %s %s ON DUPLICATE KEY UPDATE token = VALUES(token), sign = VALUES(sign), service = VALUES(service), '
                . 'generation_time = VALUES(generation_time), expiration_time = VALUES(expiration_time), updated_at = VALUES(updated_at)', $this->table, $columns),
            'pgsql', 'sqlite' => sprintf('INSERT INTO %s %s ON CONFLICT (ticket_key) DO UPDATE SET token = excluded.token, sign = excluded.sign, '
                . 'service = excluded.service, generation_time = excluded.generation_time, expiration_time = excluded.expiration_time, updated_at = excluded.updated_at', $this->table, $columns),
            default => null,
        };

        if ($sql !== null) {
            $this->pdo->prepare($sql)->execute($params);

            return;
        }
        $this->delete($key);
        $this->pdo->prepare(sprintf('INSERT INTO %s %s', $this->table, $columns))->execute($params);
    }

    public function delete(string $key): void
    {
        $this->pdo->prepare(sprintf('DELETE FROM %s WHERE ticket_key = :k', $this->table))->execute(['k' => $key]);
    }

    public function withLock(string $key, callable $callback): mixed
    {
        return $this->lock->run($key, $callback);
    }

    /**
     * Crea la tabla si no existe (también puede crearse con una migración del ERP).
     */
    public function createTable(): void
    {
        $this->pdo->exec(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (ticket_key VARCHAR(191) NOT NULL PRIMARY KEY, token TEXT NOT NULL, sign TEXT NOT NULL, '
            . 'service VARCHAR(100) NOT NULL, generation_time VARCHAR(40) NOT NULL, expiration_time VARCHAR(40) NOT NULL, updated_at VARCHAR(40) NOT NULL)',
            $this->table
        ));
    }
}
