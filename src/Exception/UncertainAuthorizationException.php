<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Exception;

/**
 * Hubo un error de comunicación al autorizar y NO se pudo confirmar si ARCA registró
 * el comprobante.
 *
 * El comprobante conserva el número asignado. Para retomarlo (incluso desde otro proceso,
 * por ejemplo un cron) guardar {@see toArray()} y luego llamar a See::reconcile() con el
 * mismo comprobante (mismo número): si ARCA lo autorizó devuelve el CAE; si no existe,
 * reenviarlo con el mismo número. Nunca generar otro número para la misma operación.
 */
class UncertainAuthorizationException extends TransportException implements \JsonSerializable
{
    /**
     * @param int[] $numeros     números asignados a los comprobantes enviados
     * @param bool  $notFound    true si ARCA respondió que el número no existe (lo más probable
     *                           es que no se haya autorizado)
     * @param string $requestHash huella de los datos enviados ({@see \RoyaltyFusion\Afip\Support\Fingerprint})
     */
    public function __construct(
        string $message,
        private readonly int $puntoVenta,
        private readonly int $tipoComprobante,
        private readonly array $numeros,
        private readonly bool $notFound,
        ?\Throwable $previous = null,
        private readonly string $requestHash = '',
        private readonly string $servicio = '',
        private readonly string $cuit = '',
        private readonly string $ambiente = ''
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

    /**
     * SHA-256 de los datos fiscales enviados (emisor, tipo, punto de venta, número, fecha,
     * total, moneda y receptor).
     */
    public function getRequestHash(): string
    {
        return $this->requestHash;
    }

    /**
     * Web service usado (wsfe, wsmtxca, wsct, wsfex, wsbfe).
     */
    public function getServicio(): string
    {
        return $this->servicio;
    }

    public function getCuit(): string
    {
        return $this->cuit;
    }

    public function getAmbiente(): string
    {
        return $this->ambiente;
    }

    /**
     * Datos para persistir el estado incierto y retomarlo después.
     *
     * @return array{servicio: string, ambiente: string, cuit: string, tipoComprobante: int, puntoVenta: int, numeros: int[], notFound: bool, requestHash: string, mensaje: string}
     */
    public function toArray(): array
    {
        return [
            'servicio' => $this->servicio,
            'ambiente' => $this->ambiente,
            'cuit' => $this->cuit,
            'tipoComprobante' => $this->tipoComprobante,
            'puntoVenta' => $this->puntoVenta,
            'numeros' => $this->numeros,
            'notFound' => $this->notFound,
            'requestHash' => $this->requestHash,
            'mensaje' => $this->getMessage(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
