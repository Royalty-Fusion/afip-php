<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Model\Export;

use RoyaltyFusion\Afip\Catalog\Exportacion;
use RoyaltyFusion\Afip\Catalog\TipoComprobante;
use RoyaltyFusion\Afip\Model\Company;
use RoyaltyFusion\Afip\Model\Sale\Opcional;
use RoyaltyFusion\Afip\Support\Date;

/**
 * Comprobante de exportación clase E (WSFEXv1): factura (19), nota de débito (20)
 * y nota de crédito (21).
 */
class ExportInvoice
{
    /** Id de la solicitud. Si es null se asigna automáticamente (FEXGetLast_ID + 1). */
    private ?int $id = null;
    private int $tipoComprobante = TipoComprobante::FACTURA_EXPORTACION;
    private int $puntoVenta = 1;
    private ?int $numero = null;
    private \DateTimeInterface $fechaEmision;
    private int $tipoExportacion = Exportacion::EXPORTACION_BIENES;
    /** "S" / "N" para bienes; null para servicios, otros y notas. */
    private ?string $permisoExistente = null;
    /** @var Permiso[] */
    private array $permisos = [];
    private int $paisDestino = 0;

    private ?Company $company = null;
    private string $cliente = '';
    private ?string $cuitPaisCliente = null;
    private ?string $domicilioCliente = null;
    private ?string $idImpositivo = null;

    private string $moneda = 'DOL';
    private float $cotizacion = 0.0;
    private ?bool $pagoMismaMoneda = null;
    private ?string $observacionesComerciales = null;
    private ?float $impTotal = null;
    private ?string $observaciones = null;
    /** @var ExportCbteAsociado[] */
    private array $cbtesAsociados = [];
    private ?string $formaPago = null;
    private ?string $incoterms = null;
    private ?string $incotermsDescripcion = null;
    private int $idioma = Exportacion::IDIOMA_ESPANOL;
    /** @var ExportItem[] */
    private array $items = [];
    /** @var Opcional[] */
    private array $opcionales = [];
    private ?\DateTimeInterface $fechaPago = null;
    /** @var int[] */
    private array $actividades = [];

    private ?string $cae = null;
    private ?\DateTimeInterface $caeVencimiento = null;

    public function __construct()
    {
        $this->fechaEmision = Date::today();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function getTipoComprobante(): int
    {
        return $this->tipoComprobante;
    }

    public function setTipoComprobante(int $tipoComprobante): static
    {
        $this->tipoComprobante = $tipoComprobante;

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

    public function getNumero(): ?int
    {
        return $this->numero;
    }

    public function setNumero(?int $numero): static
    {
        $this->numero = $numero;

        return $this;
    }

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

    public function getTipoExportacion(): int
    {
        return $this->tipoExportacion;
    }

    /**
     * 1 = exportación definitiva de bienes, 2 = servicios, 4 = otros.
     */
    public function setTipoExportacion(int $tipoExportacion): static
    {
        $this->tipoExportacion = $tipoExportacion;

        return $this;
    }

    public function getPermisoExistente(): ?string
    {
        return $this->permisoExistente;
    }

    public function setPermisoExistente(?string $permisoExistente): static
    {
        $this->permisoExistente = $permisoExistente;

        return $this;
    }

    /**
     * @return Permiso[]
     */
    public function getPermisos(): array
    {
        return $this->permisos;
    }

    /**
     * @param Permiso[] $permisos
     */
    public function setPermisos(array $permisos): static
    {
        $this->permisos = array_values($permisos);

        return $this;
    }

    public function addPermiso(Permiso $permiso): static
    {
        $this->permisos[] = $permiso;

        return $this;
    }

    public function getPaisDestino(): int
    {
        return $this->paisDestino;
    }

    /**
     * País de destino del comprobante (FEXGetPARAM_DST_pais).
     */
    public function setPaisDestino(int $paisDestino): static
    {
        $this->paisDestino = $paisDestino;

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

    public function getCliente(): string
    {
        return $this->cliente;
    }

    public function setCliente(string $cliente): static
    {
        $this->cliente = $cliente;

        return $this;
    }

    public function getCuitPaisCliente(): ?string
    {
        return $this->cuitPaisCliente;
    }

    /**
     * CUIT genérico del país del cliente (FEXGetPARAM_DST_CUIT). Obligatorio si no se
     * informa el identificador impositivo.
     */
    public function setCuitPaisCliente(?string $cuitPaisCliente): static
    {
        $this->cuitPaisCliente = $cuitPaisCliente;

        return $this;
    }

    public function getDomicilioCliente(): ?string
    {
        return $this->domicilioCliente;
    }

    public function setDomicilioCliente(?string $domicilioCliente): static
    {
        $this->domicilioCliente = $domicilioCliente;

        return $this;
    }

    public function getIdImpositivo(): ?string
    {
        return $this->idImpositivo;
    }

    /**
     * Identificador impositivo del cliente en su país.
     */
    public function setIdImpositivo(?string $idImpositivo): static
    {
        $this->idImpositivo = $idImpositivo;

        return $this;
    }

    public function getMoneda(): string
    {
        return $this->moneda;
    }

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
     * CanMisMonExt: el comprobante se cancela en la misma moneda extranjera.
     */
    public function setPagoMismaMoneda(?bool $pagoMismaMoneda): static
    {
        $this->pagoMismaMoneda = $pagoMismaMoneda;

        return $this;
    }

    public function getObservacionesComerciales(): ?string
    {
        return $this->observacionesComerciales;
    }

    public function setObservacionesComerciales(?string $observacionesComerciales): static
    {
        $this->observacionesComerciales = $observacionesComerciales;

        return $this;
    }

    /**
     * Total del comprobante. Si no se indicó, se calcula a partir de los ítems.
     */
    public function getImpTotal(): float
    {
        return $this->impTotal ?? $this->calculateTotal();
    }

    public function setImpTotal(?float $impTotal): static
    {
        $this->impTotal = $impTotal;

        return $this;
    }

    public function calculateTotal(): float
    {
        $total = 0.0;
        foreach ($this->items as $item) {
            $total += $item->isBonificacionGeneral() ? -$item->getTotal() : $item->getTotal();
        }

        return round($total, 2);
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

    /**
     * @return ExportCbteAsociado[]
     */
    public function getCbtesAsociados(): array
    {
        return $this->cbtesAsociados;
    }

    /**
     * @param ExportCbteAsociado[] $cbtesAsociados
     */
    public function setCbtesAsociados(array $cbtesAsociados): static
    {
        $this->cbtesAsociados = array_values($cbtesAsociados);

        return $this;
    }

    public function addCbteAsociado(ExportCbteAsociado $cbteAsociado): static
    {
        $this->cbtesAsociados[] = $cbteAsociado;

        return $this;
    }

    public function getFormaPago(): ?string
    {
        return $this->formaPago;
    }

    public function setFormaPago(?string $formaPago): static
    {
        $this->formaPago = $formaPago;

        return $this;
    }

    public function getIncoterms(): ?string
    {
        return $this->incoterms;
    }

    public function setIncoterms(?string $incoterms, ?string $descripcion = null): static
    {
        $this->incoterms = $incoterms;
        if ($descripcion !== null) {
            $this->incotermsDescripcion = $descripcion;
        }

        return $this;
    }

    public function getIncotermsDescripcion(): ?string
    {
        return $this->incotermsDescripcion;
    }

    public function setIncotermsDescripcion(?string $incotermsDescripcion): static
    {
        $this->incotermsDescripcion = $incotermsDescripcion;

        return $this;
    }

    public function getIdioma(): int
    {
        return $this->idioma;
    }

    /**
     * 1 = español, 2 = inglés, 3 = portugués.
     */
    public function setIdioma(int $idioma): static
    {
        $this->idioma = $idioma;

        return $this;
    }

    /**
     * @return ExportItem[]
     */
    public function getItems(): array
    {
        return $this->items;
    }

    /**
     * @param ExportItem[] $items
     */
    public function setItems(array $items): static
    {
        $this->items = array_values($items);

        return $this;
    }

    public function addItem(ExportItem $item): static
    {
        $this->items[] = $item;

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

    public function addOpcional(Opcional $opcional): static
    {
        $this->opcionales[] = $opcional;

        return $this;
    }

    public function getFechaPago(): ?\DateTimeInterface
    {
        return $this->fechaPago;
    }

    /**
     * Fecha de pago (obligatoria en servicios y otros).
     */
    public function setFechaPago(?\DateTimeInterface $fechaPago): static
    {
        $this->fechaPago = $fechaPago;

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
     * @param int[] $actividades
     */
    public function setActividades(array $actividades): static
    {
        $this->actividades = array_values(array_map('intval', $actividades));

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
