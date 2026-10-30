<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Libro;

use RoyaltyFusion\Afip\Catalog\Moneda;
use RoyaltyFusion\Afip\Model\Sale\Iva;
use RoyaltyFusion\Afip\Support\Num;

/**
 * Un comprobante del Libro IVA Digital (RG 4597), de ventas o de compras.
 *
 * Los importes van en la moneda del comprobante (con su tipo de cambio), positivos
 * también en las notas de crédito. La contraparte es el comprador en ventas y el
 * vendedor en compras.
 */
final class RegistroIva
{
    private \DateTimeInterface $fecha;
    private int $tipoComprobante;
    private int $puntoVenta = 0;
    private int $numero = 0;
    private ?int $numeroHasta = null;
    private ?string $despachoImportacion = null;

    private int $docTipo = 99;
    private string $docNro = '0';
    private string $nombre = '';

    private float $total = 0.0;
    private float $noGravado = 0.0;
    private float $exento = 0.0;
    private float $percepcionIva = 0.0;
    private float $percepcionNoCategorizados = 0.0;
    private float $percepcionesNacionales = 0.0;
    private float $percepcionesIibb = 0.0;
    private float $percepcionesMunicipales = 0.0;
    private float $impuestosInternos = 0.0;
    private float $otrosTributos = 0.0;

    private string $moneda = Moneda::PESOS;
    private float $cotizacion = 1.0;
    private ?string $codigoOperacion = null;
    private ?\DateTimeInterface $fechaVencimientoPago = null;

    private ?float $creditoFiscalComputable = null;
    private ?string $cuitCorredor = null;
    private ?string $denominacionCorredor = null;
    private float $ivaComision = 0.0;

    private ?string $codigoAutorizacion = null;
    private string $tipoCodigoAutorizacion = 'CAE';

    /** @var Iva[] */
    private array $alicuotas = [];

    public function __construct(\DateTimeInterface $fecha, int $tipoComprobante, int $puntoVenta = 0, int $numero = 0)
    {
        $this->fecha = $fecha;
        $this->tipoComprobante = $tipoComprobante;
        $this->puntoVenta = $puntoVenta;
        $this->numero = $numero;
    }

    public function getFecha(): \DateTimeInterface
    {
        return $this->fecha;
    }

    public function setFecha(\DateTimeInterface $fecha): static
    {
        $this->fecha = $fecha;

        return $this;
    }

    public function getTipoComprobante(): int
    {
        return $this->tipoComprobante;
    }

    public function setTipoComprobante(int $tipo): static
    {
        $this->tipoComprobante = $tipo;

        return $this;
    }

    public function getPuntoVenta(): int
    {
        return $this->puntoVenta;
    }

    public function setPuntoVenta(int $puntoVenta): static
    {
        $this->puntoVenta = $puntoVenta;

        return $this;
    }

    public function getNumero(): int
    {
        return $this->numero;
    }

    public function setNumero(int $numero): static
    {
        $this->numero = $numero;

        return $this;
    }

    /**
     * Último número cuando se registran juntos varios comprobantes (totales diarios de
     * consumidor final). Solo en ventas.
     */
    public function getNumeroHasta(): int
    {
        return $this->numeroHasta ?? $this->numero;
    }

    public function setNumeroHasta(?int $numeroHasta): static
    {
        $this->numeroHasta = $numeroHasta;

        return $this;
    }

    /**
     * Número de despacho de importación (16 caracteres). Solo en compras tipo 66.
     */
    public function getDespachoImportacion(): ?string
    {
        return $this->despachoImportacion;
    }

    public function setDespachoImportacion(?string $despacho): static
    {
        $this->despachoImportacion = $despacho === null ? null : strtoupper(trim($despacho));

        return $this;
    }

    public function getDocTipo(): int
    {
        return $this->docTipo;
    }

    public function getDocNro(): string
    {
        return $this->docNro;
    }

    public function getNombre(): string
    {
        return $this->nombre;
    }

    /**
     * Comprador (ventas) o vendedor (compras).
     */
    public function setContraparte(int $docTipo, string|int $docNro, string $nombre): static
    {
        $this->docTipo = $docTipo;
        $this->docNro = (string) preg_replace('/\D/', '', (string) $docNro);
        if ($this->docNro === '') {
            $this->docNro = '0';
        }
        $this->nombre = trim($nombre);

        return $this;
    }

    public function getTotal(): float
    {
        return $this->total;
    }

    public function setTotal(float $total): static
    {
        $this->total = $total;

        return $this;
    }

    public function getNoGravado(): float
    {
        return $this->noGravado;
    }

    public function setNoGravado(float $importe): static
    {
        $this->noGravado = $importe;

        return $this;
    }

    public function getExento(): float
    {
        return $this->exento;
    }

    public function setExento(float $importe): static
    {
        $this->exento = $importe;

        return $this;
    }

    public function getPercepcionIva(): float
    {
        return $this->percepcionIva;
    }

    public function setPercepcionIva(float $importe): static
    {
        $this->percepcionIva = $importe;

        return $this;
    }

    /**
     * Percepción a no categorizados. Solo en ventas.
     */
    public function getPercepcionNoCategorizados(): float
    {
        return $this->percepcionNoCategorizados;
    }

    public function setPercepcionNoCategorizados(float $importe): static
    {
        $this->percepcionNoCategorizados = $importe;

        return $this;
    }

    public function getPercepcionesNacionales(): float
    {
        return $this->percepcionesNacionales;
    }

    public function setPercepcionesNacionales(float $importe): static
    {
        $this->percepcionesNacionales = $importe;

        return $this;
    }

    public function getPercepcionesIibb(): float
    {
        return $this->percepcionesIibb;
    }

    public function setPercepcionesIibb(float $importe): static
    {
        $this->percepcionesIibb = $importe;

        return $this;
    }

    public function getPercepcionesMunicipales(): float
    {
        return $this->percepcionesMunicipales;
    }

    public function setPercepcionesMunicipales(float $importe): static
    {
        $this->percepcionesMunicipales = $importe;

        return $this;
    }

    public function getImpuestosInternos(): float
    {
        return $this->impuestosInternos;
    }

    public function setImpuestosInternos(float $importe): static
    {
        $this->impuestosInternos = $importe;

        return $this;
    }

    public function getOtrosTributos(): float
    {
        return $this->otrosTributos;
    }

    public function setOtrosTributos(float $importe): static
    {
        $this->otrosTributos = $importe;

        return $this;
    }

    public function getMoneda(): string
    {
        return $this->moneda;
    }

    public function getCotizacion(): float
    {
        return $this->cotizacion;
    }

    public function setMoneda(string $moneda, float $cotizacion = 1.0): static
    {
        $this->moneda = strtoupper(trim($moneda));
        $this->cotizacion = $cotizacion;

        return $this;
    }

    /**
     * Código de operación (ver {@see CodigoOperacion}). Null: se deduce de los importes.
     */
    public function getCodigoOperacion(): ?string
    {
        return $this->codigoOperacion;
    }

    public function setCodigoOperacion(?string $codigo): static
    {
        $this->codigoOperacion = $codigo === null ? null : strtoupper(trim($codigo));

        return $this;
    }

    public function getFechaVencimientoPago(): ?\DateTimeInterface
    {
        return $this->fechaVencimientoPago;
    }

    public function setFechaVencimientoPago(?\DateTimeInterface $fecha): static
    {
        $this->fechaVencimientoPago = $fecha;

        return $this;
    }

    /**
     * Crédito fiscal computable (compras). Null: el IVA de las alícuotas.
     */
    public function getCreditoFiscalComputable(): ?float
    {
        return $this->creditoFiscalComputable;
    }

    public function setCreditoFiscalComputable(?float $importe): static
    {
        $this->creditoFiscalComputable = $importe;

        return $this;
    }

    public function getCuitCorredor(): ?string
    {
        return $this->cuitCorredor;
    }

    public function getDenominacionCorredor(): ?string
    {
        return $this->denominacionCorredor;
    }

    public function getIvaComision(): float
    {
        return $this->ivaComision;
    }

    /**
     * Emisor o corredor en liquidaciones (tipos 33, 58, 59, 60 y 63). Solo en compras.
     */
    public function setCorredor(?string $cuit, ?string $denominacion, float $ivaComision = 0.0): static
    {
        $this->cuitCorredor = $cuit === null ? null : (string) preg_replace('/\D/', '', $cuit);
        $this->denominacionCorredor = $denominacion;
        $this->ivaComision = $ivaComision;

        return $this;
    }

    /**
     * CAE, CAEA o CAI del comprobante (para constatarlo con WSCDC).
     */
    public function getCodigoAutorizacion(): ?string
    {
        return $this->codigoAutorizacion;
    }

    public function getTipoCodigoAutorizacion(): string
    {
        return $this->tipoCodigoAutorizacion;
    }

    public function setCodigoAutorizacion(?string $codigo, string $tipo = 'CAE'): static
    {
        $codigo = $codigo === null ? null : (string) preg_replace('/\D/', '', $codigo);
        $this->codigoAutorizacion = $codigo === '' ? null : $codigo;
        $this->tipoCodigoAutorizacion = strtoupper($tipo);

        return $this;
    }

    /**
     * @return Iva[]
     */
    public function getAlicuotas(): array
    {
        return $this->alicuotas;
    }

    /**
     * @param Iva[] $alicuotas
     */
    public function setAlicuotas(array $alicuotas): static
    {
        $this->alicuotas = array_values($alicuotas);

        return $this;
    }

    public function addAlicuota(int $id, float $neto, float $iva): static
    {
        $this->alicuotas[] = new Iva($id, $neto, $iva);

        return $this;
    }

    public function getNetoGravado(): float
    {
        return Num::round(array_sum(array_map(static fn (Iva $i): float => $i->getBaseImp(), $this->alicuotas)));
    }

    public function getIva(): float
    {
        return Num::round(array_sum(array_map(static fn (Iva $i): float => $i->getImporte(), $this->alicuotas)));
    }

    /**
     * Suma de todos los conceptos: debe coincidir con el total.
     */
    public function getSumaConceptos(): float
    {
        return Num::round(
            $this->getNetoGravado() + $this->getIva() + $this->noGravado + $this->exento
            + $this->percepcionIva + $this->percepcionNoCategorizados + $this->percepcionesNacionales
            + $this->percepcionesIibb + $this->percepcionesMunicipales + $this->impuestosInternos
            + $this->otrosTributos
        );
    }

    /**
     * Clave que identifica al comprobante (tipo, punto de venta, número y, en compras,
     * documento del vendedor).
     */
    public function getClave(bool $conContraparte = false): string
    {
        $clave = sprintf('%03d-%05d-%08d', $this->tipoComprobante, $this->puntoVenta, $this->numero);
        if ($this->despachoImportacion !== null && $this->despachoImportacion !== '') {
            $clave .= '-' . $this->despachoImportacion;
        }

        return $conContraparte ? $clave . '-' . $this->docTipo . '-' . $this->docNro : $clave;
    }
}
