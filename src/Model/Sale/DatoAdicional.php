<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Model\Sale;

/**
 * Dato adicional de WSMTXCA / WSCT (nodo datoAdicional: t, c1..c6).
 * Los tipos se consultan con consultarTiposDatosAdicionales (ej. 21 = CBU del emisor en FCE).
 */
class DatoAdicional
{
    /** @var array<int, string|null> */
    private array $campos;

    public function __construct(private int $tipo, ?string ...$campos)
    {
        $this->campos = array_values(array_pad(array_slice($campos, 0, 6), 6, null));
    }

    public function getTipo(): int
    {
        return $this->tipo;
    }

    public function setTipo(int $tipo): static
    {
        $this->tipo = $tipo;

        return $this;
    }

    /**
     * Campo c1..c6 (índice 1 a 6).
     */
    public function getCampo(int $n): ?string
    {
        return $this->campos[$n - 1] ?? null;
    }

    public function setCampo(int $n, ?string $valor): static
    {
        if ($n < 1 || $n > 6) {
            throw new \InvalidArgumentException('Los datos adicionales tienen campos c1 a c6.');
        }
        $this->campos[$n - 1] = $valor;

        return $this;
    }

    /**
     * @return array<int, string|null> c1..c6
     */
    public function getCampos(): array
    {
        return $this->campos;
    }
}
