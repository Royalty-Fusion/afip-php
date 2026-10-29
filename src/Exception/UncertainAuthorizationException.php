<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Exception;

/**
 * Hubo un error de comunicación al autorizar y NO se pudo confirmar si ARCA registró
 * el comprobante.
 *
 * El comprobante conserva el número asignado. Para reintentar hay que reenviarlo CON EL
 * MISMO NÚMERO: si ARCA ya lo había autorizado, responde con el mismo CAE (Reproceso = S);
 * si no, lo autoriza. Nunca se debe generar un comprobante nuevo con otro número para la
 * misma operación.
 */
class UncertainAuthorizationException extends TransportException
{
    /**
     * @param int[] $numeros    números asignados a los comprobantes enviados
     * @param bool  $notFound   true si FECompConsultar respondió que el número no existe
     *                          (lo más probable es que no se haya autorizado)
     */
    public function __construct(
        string $message,
        private readonly int $puntoVenta,
        private readonly int $tipoComprobante,
        private readonly array $numeros,
        private readonly bool $notFound,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, null, null, $previous);
    }

    public function getPuntoVenta(): int
    {
        return $this->puntoVenta;
    }

    public function getTipoComprobante(): int
    {
        return $this->tipoComprobante;
    }

    /**
     * @return int[]
     */
    public function getNumeros(): array
    {
        return $this->numeros;
    }

    public function isNotFound(): bool
    {
        return $this->notFound;
    }
}
