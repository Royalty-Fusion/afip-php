<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Auth;

use RoyaltyFusion\Afip\Exception\AfipException;

/**
 * Guarda los TA como JSON en un directorio (permisos 0600). Las escrituras son atómicas.
 */
final class FileTicketStorage implements LockableTicketStorageInterface
{
    public function __construct(private readonly string $directory)
    {
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new AfipException(sprintf('No se pudo crear el directorio de tickets "%s".', $directory));
        }
    }

    public function get(string $key): ?AccessTicket
    {
        $file = $this->path($key);
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($file), true);
        if (!is_array($data) || !isset($data['token'], $data['sign'], $data['expirationTime'], $data['generationTime'])) {
            return null;
        }

        try {
            return AccessTicket::fromArray($data);
        } catch (\Throwable) {
            return null;
        }
    }

    public function set(string $key, AccessTicket $ticket): void
    {
        $file = $this->path($key);
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, json_encode($ticket->toArray(), JSON_PRETTY_PRINT)) === false) {
            throw new AfipException(sprintf('No se pudo escribir el ticket en "%s".', $tmp));
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new AfipException(sprintf('No se pudo guardar el ticket en "%s".', $file));
        }
    }

    public function delete(string $key): void
    {
        $file = $this->path($key);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /**
     * Ejecuta $callback con un lock exclusivo por clave (evita logins concurrentes).
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function withLock(string $key, callable $callback): mixed
    {
        $handle = fopen($this->path($key) . '.lock', 'c');
        if ($handle === false) {
            return $callback();
        }
        try {
            flock($handle, LOCK_EX);

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function path(string $key): string
    {
        return rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . 'TA-' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $key) . '.json';
    }
}
