<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Model\Sale;

use RoyaltyFusion\Afip\Catalog\Concepto;
use RoyaltyFusion\Afip\Catalog\Moneda;
use RoyaltyFusion\Afip\Catalog\TipoComprobante;
use RoyaltyFusion\Afip\Model\Client;
use RoyaltyFusion\Afip\Model\Company;
use RoyaltyFusion\Afip\Support\Date;

/**
 * Comprobante de mercado interno autorizado por WSFEv1 (facturas, notas de crédito y
 * débito, recibos, FCE MiPyME, etc.).
 */
class Invoice
{
    private int $tipoComprobante = TipoComprobante::FACTURA_B;
    private int $puntoVenta = 1;
    /** Número de comprobante. Si es null se asigna automáticamente (último autorizado + 1). */
    private ?int $numero = null;
    /** Último número del rango (solo lotes de comprobantes B/C a consumidor final). */
    private ?int $numeroHasta = null;
    private \DateTimeInterface $fechaEmision;
    private int $concepto = Concepto::PRODUCTOS;

    private ?Company $company = null;
    private ?Client $client = null;

    private ?\DateTimeInterface $fechaServicioDesde = null;
    private ?\DateTimeInterface $fechaServicioHasta = null;
    private ?\DateTimeInterface $fechaVencimientoPago = null;

    private string $moneda = Moneda::PESOS;
    private float $cotizacion = 1.0;
    /** CanMisMonExt: true si el comprobante se cancela en la misma moneda extranjera. */
    private ?bool $pagoMismaMoneda = null;

    // Importes (ImpTotal = ImpTotConc + ImpNeto + ImpOpEx + ImpTrib + ImpIVA)
    private float $impTotal = 0.0;
    private float $impNoGravado = 0.0;
    private float $impNeto = 0.0;
    private float $impExento = 0.0;
    private float $impTributos = 0.0;
    private float $impIva = 0.0;

    /** @var Iva[] */
    private array $ivas = [];
    /** @var Tributo[] */
    private array $tributos = [];
    /** @var Opcional[] */
    private array $opcionales = [];
    /** @var CbteAsociado[] */
    private array $cbtesAsociados = [];
    private ?Periodo $periodoAsociado = null;
    /** @var Comprador[] */
    private array $compradores = [];
    /** @var int[] */
    private array $actividades = [];

    /** @var SaleDetail[] */
    private array $details = [];
    /** @var string[] */
    private array $leyendas = [];
    private ?string $observaciones = null;

    // CAEA (régimen de contingencia / emisión anticipada)
    private ?string $caea = null;
    private ?\DateTimeInterface $fechaHoraGeneracion = null;

    // Datos de autorización (completados por el SDK al autorizar)
    private ?string $cae = null;
    private ?\DateTimeInterface $caeVencimiento = null;

    public function __construct()
    {
        $this->fechaEmision = Date::today();
    }

    public function getTipoComprobante(): int
    {
        return $this->tipoComprobante;
    }

    /**
     * Tipo de comprobante ({@see TipoComprobante}).
     */
    public function setTipoComprobante(int $tipoComprobante): static
    {
        $this->tipoComprobante = $tipoComprobante;

        return $this;
    }

    public function getClase(): ?string
    {
        return TipoComprobante::clase($this->tipoComprobante);
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

    public function getNumero(): ?int
    {
        return $this->numero;
    }

    public function setNumero(?int $numero): static
    {
        $this->numero = $numero;

        return $this;
    }

    public function getNumeroHasta(): ?int
    {
        return $this->numeroHasta ?? $this->numero;
    }

    public function setNumeroHasta(?int $numeroHasta): static
    {
        $this->numeroHasta = $numeroHasta;

        return $this;
    }

    /**
     * Número formateado PPPPP-NNNNNNNN.
     */
    public function getNumeroCompleto(): string
    {
        return sprintf('%05d-%08d', $this->puntoVenta, (int) $this->numero);
    }

    public function getFechaEmision(): \DateTimeInterface
    {
        return $this->fechaEmision;
    }

    public function setFechaEmision(\DateTimeInterface $fechaEmision): static
    {
        $this->fechaEmision = $fechaEmision;

        return $this;
    }

    public function getConcepto(): int
    {
        return $this->concepto;
    }

    /**
     * Concepto ({@see Concepto}): 1 productos, 2 servicios, 3 productos y servicios.
     */
    public function setConcepto(int $concepto): static
    {
        $this->concepto = $concepto;

        return $this;
    }

    public function getCompany(): ?Company
    {
        return $this->company;
    }

    public function setCompany(?Company $company): static
    {
        $this->company = $company;

        return $this;
    }

    public function getClient(): ?Client
    {
        return $this->client;
    }

    public function setClient(?Client $client): static
    {
        $this->client = $client;

        return $this;
    }

    public function getFechaServicioDesde(): ?\DateTimeInterface
    {
        return $this->fechaServicioDesde;
    }

    public function setFechaServicioDesde(?\DateTimeInterface $fecha): static
    {
        $this->fechaServicioDesde = $fecha;

        return $this;
    }

    public function getFechaServicioHasta(): ?\DateTimeInterface
    {
        return $this->fechaServicioHasta;
    }

    public function setFechaServicioHasta(?\DateTimeInterface $fecha): static
    {
        $this->fechaServicioHasta = $fecha;

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
     * Atajo para comprobantes de servicios: período facturado y vencimiento del pago.
     */
    public function setPeriodoServicio(\DateTimeInterface $desde, \DateTimeInterface $hasta, ?\DateTimeInterface $vencimientoPago = null): static
    {
        $this->fechaServicioDesde = $desde;
        $this->fechaServicioHasta = $hasta;
        if ($vencimientoPago !== null) {
            $this->fechaVencimientoPago = $vencimientoPago;
        }

        return $this;
    }

    public function getMoneda(): string
    {
        return $this->moneda;
    }

    /**
     * Código de moneda ({@see Moneda}).
     */
    public function setMoneda(string $moneda, ?float $cotizacion = null): static
    {
        $this->moneda = $moneda;
        if ($cotizacion !== null) {
            $this->cotizacion = $cotizacion;
        }

        return $this;
    }

    public function getCotizacion(): float
    {
        return $this->cotizacion;
    }

    public function setCotizacion(float $cotizacion): static
    {
        $this->cotizacion = $cotizacion;

        return $this;
    }

    public function getPagoMismaMoneda(): ?bool
    {
        return $this->pagoMismaMoneda;
    }

    /**
     * CanMisMonExt (RG 5616): indica si el comprobante en moneda extranjera se cancela en
     * esa misma moneda. Solo se informa cuando la moneda no es PES.
     */
    public function setPagoMismaMoneda(?bool $pagoMismaMoneda): static
    {
        $this->pagoMismaMoneda = $pagoMismaMoneda;

        return $this;
    }

    public function getImpTotal(): float
    {
        return $this->impTotal;
    }

    public function setImpTotal(float $impTotal): static
    {
        $this->impTotal = $impTotal;

        return $this;
    }

    /**
     * Importe neto no gravado (ImpTotConc).
     */
    public function getImpNoGravado(): float
    {
        return $this->impNoGravado;
    }

    public function setImpNoGravado(float $impNoGravado): static
    {
        $this->impNoGravado = $impNoGravado;

        return $this;
    }

    /**
     * Importe neto gravado (ImpNeto). En clase C es el subtotal del comprobante.
     */
    public function getImpNeto(): float
    {
        return $this->impNeto;
    }

    public function setImpNeto(float $impNeto): static
    {
        $this->impNeto = $impNeto;

        return $this;
    }

    /**
     * Importe exento (ImpOpEx).
     */
    public function getImpExento(): float
    {
        return $this->impExento;
    }

    public function setImpExento(float $impExento): static
    {
        $this->impExento = $impExento;

        return $this;
    }

    /**
     * Suma de otros tributos (ImpTrib).
     */
    public function getImpTributos(): float
    {
        return $this->impTributos;
    }

    public function setImpTributos(float $impTributos): static
    {
        $this->impTributos = $impTributos;

        return $this;
    }

    /**
     * Suma de IVA (ImpIVA).
     */
    public function getImpIva(): float
    {
        return $this->impIva;
    }

    public function setImpIva(float $impIva): static
    {
        $this->impIva = $impIva;

        return $this;
    }

    /**
     * @return Iva[]
     */
    public function getIvas(): array
    {
        return $this->ivas;
    }

    /**
     * @param Iva[] $ivas
     */
    public function setIvas(array $ivas): static
    {
        $this->ivas = array_values($ivas);

        return $this;
    }

    public function addIva(Iva|int $iva, float $baseImp = 0.0, float $importe = 0.0): static
    {
        $this->ivas[] = $iva instanceof Iva ? $iva : new Iva($iva, $baseImp, $importe);

        return $this;
    }

    /**
     * @return Tributo[]
     */
    public function getTributos(): array
    {
        return $this->tributos;
    }

    /**
     * @param Tributo[] $tributos
     */
    public function setTributos(array $tributos): static
    {
        $this->tributos = array_values($tributos);

        return $this;
    }

    public function addTributo(Tributo $tributo): static
    {
        $this->tributos[] = $tributo;

        return $this;
    }

    /**
     * @return Opcional[]
     */
    public function getOpcionales(): array
    {
        return $this->opcionales;
    }

    /**
     * @param Opcional[] $opcionales
     */
    public function setOpcionales(array $opcionales): static
    {
        $this->opcionales = array_values($opcionales);

        return $this;
    }

    public function addOpcional(Opcional|string|int $opcional, ?string $valor = null): static
    {
        $this->opcionales[] = $opcional instanceof Opcional ? $opcional : new Opcional($opcional, (string) $valor);

        return $this;
    }

    public function getOpcional(string|int $id): ?Opcional
    {
        foreach ($this->opcionales as $opcional) {
            if ($opcional->getId() === (string) $id) {
                return $opcional;
            }
        }

        return null;
    }

    /**
     * @return CbteAsociado[]
     */
    public function getCbtesAsociados(): array
    {
        return $this->cbtesAsociados;
    }

    /**
     * @param CbteAsociado[] $cbtesAsociados
     */
    public function setCbtesAsociados(array $cbtesAsociados): static
    {
        $this->cbtesAsociados = array_values($cbtesAsociados);

        return $this;
    }

    public function addCbteAsociado(CbteAsociado $cbteAsociado): static
    {
        $this->cbtesAsociados[] = $cbteAsociado;

        return $this;
    }

    public function getPeriodoAsociado(): ?Periodo
    {
        return $this->periodoAsociado;
    }

    public function setPeriodoAsociado(?Periodo $periodoAsociado): static
    {
        $this->periodoAsociado = $periodoAsociado;

        return $this;
    }

    /**
     * @return Comprador[]
     */
    public function getCompradores(): array
    {
        return $this->compradores;
    }

    /**
     * @param Comprador[] $compradores
     */
    public function setCompradores(array $compradores): static
    {
        $this->compradores = array_values($compradores);

        return $this;
    }

    public function addComprador(Comprador $comprador): static
    {
        $this->compradores[] = $comprador;

        return $this;
    }

    /**
     * @return int[]
     */
    public function getActividades(): array
    {
        return $this->actividades;
    }

    /**
     * Códigos de actividad (FEParamGetActividades).
     *
     * @param int[] $actividades
     */
    public function setActividades(array $actividades): static
    {
        $this->actividades = array_values(array_map('intval', $actividades));

        return $this;
    }

    public function addActividad(int $actividad): static
    {
        $this->actividades[] = $actividad;

        return $this;
    }

    /**
     * @return SaleDetail[]
     */
    public function getDetails(): array
    {
        return $this->details;
    }

    /**
     * @param SaleDetail[] $details
     */
    public function setDetails(array $details): static
    {
        $this->details = array_values($details);

        return $this;
    }

    public function addDetail(SaleDetail $detail): static
    {
        $this->details[] = $detail;

        return $this;
    }

    /**
     * @return string[]
     */
    public function getLeyendas(): array
    {
        return $this->leyendas;
    }

    /**
     * Leyendas a imprimir en el comprobante.
     *
     * @param string[] $leyendas
     */
    public function setLeyendas(array $leyendas): static
    {
        $this->leyendas = array_values($leyendas);

        return $this;
    }

    public function addLeyenda(string $leyenda): static
    {
        $this->leyendas[] = $leyenda;

        return $this;
    }

    public function getObservaciones(): ?string
    {
        return $this->observaciones;
    }

    public function setObservaciones(?string $observaciones): static
    {
        $this->observaciones = $observaciones;

        return $this;
    }

    public function getCaea(): ?string
    {
        return $this->caea;
    }

    /**
     * CAEA con el que se emitió el comprobante (solo para FECAEARegInformativo).
     */
    public function setCaea(?string $caea): static
    {
        $this->caea = $caea;

        return $this;
    }

    public function getFechaHoraGeneracion(): ?\DateTimeInterface
    {
        return $this->fechaHoraGeneracion;
    }

    /**
     * Fecha y hora de generación del comprobante emitido con CAEA (CbteFchHsGen).
     */
    public function setFechaHoraGeneracion(?\DateTimeInterface $fechaHoraGeneracion): static
    {
        $this->fechaHoraGeneracion = $fechaHoraGeneracion;

        return $this;
    }

    public function getCae(): ?string
    {
        return $this->cae;
    }

    public function setCae(?string $cae): static
    {
        $this->cae = $cae;

        return $this;
    }

    public function getCaeVencimiento(): ?\DateTimeInterface
    {
        return $this->caeVencimiento;
    }

    public function setCaeVencimiento(?\DateTimeInterface $caeVencimiento): static
    {
        $this->caeVencimiento = $caeVencimiento;

        return $this;
    }

    public function isAuthorized(): bool
    {
        return $this->cae !== null && $this->cae !== '';
    }
}
