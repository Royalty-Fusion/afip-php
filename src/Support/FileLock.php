<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Support;

/**
 * Lock exclusivo basado en archivos (flock). Se usa para serializar la numeración
 * automática de comprobantes entre procesos/usuarios del ERP.
 */
final class FileLock
{
    public function __construct(private readonly string $directory)
    {
        if (!is_dir($directory)) {
            @mkdir($directory, 0700, true);
        }
    }

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function run(string $key, callable $callback): mixed
    {
        $file = rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . preg_replace('/[^A-Za-z0-9_.-]/', '_', $key) . '.lock';
        $handle = @fopen($file, 'c');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('No se pudo crear el archivo de bloqueo "%s".', $file));
        }
        try {
            flock($handle, LOCK_EX);

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
