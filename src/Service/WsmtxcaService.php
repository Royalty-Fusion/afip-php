<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Service;

use RoyaltyFusion\Afip\Auth\AuthManager;
use RoyaltyFusion\Afip\Catalog\AlicuotaIva;
use RoyaltyFusion\Afip\Catalog\Concepto;
use RoyaltyFusion\Afip\Catalog\Moneda;
use RoyaltyFusion\Afip\Catalog\TipoComprobante;
use RoyaltyFusion\Afip\Endpoints;
use RoyaltyFusion\Afip\Environment;
use RoyaltyFusion\Afip\Exception\AfipException;
use RoyaltyFusion\Afip\Model\Response\AuthorizationResult;
use RoyaltyFusion\Afip\Model\Response\CaeaInfo;
use RoyaltyFusion\Afip\Model\Response\CaeDetail;
use RoyaltyFusion\Afip\Model\Sale\Invoice;
use RoyaltyFusion\Afip\Model\Sale\SaleDetail;
use RoyaltyFusion\Afip\Soap\CurlTransport;
use RoyaltyFusion\Afip\Soap\SoapClient;
use RoyaltyFusion\Afip\Soap\SoapTransportInterface;
use RoyaltyFusion\Afip\Support\Arr;
use RoyaltyFusion\Afip\Support\Date;
use RoyaltyFusion\Afip\Support\Num;

/**
 * WSMTXCA — Factura electrónica con detalle de ítems (clases A, B y M, incluida FCE).
 *
 * A diferencia de WSFEv1, ARCA recibe cada ítem con su unidad de medida, condición de IVA,
 * IVA e importe. Usar {@see \RoyaltyFusion\Afip\Calculator\InvoiceCalculator::calculate()}
 * con $porItem = true para calcular los importes.
 */
class WsmtxcaService extends AbstractJavaService
{
    public const SERVICE = 'wsmtxca';
    public const NAMESPACE = 'http://impl.service.wsmtxca.afip.gov.ar/service/';

    /** Código de condición de IVA del ítem para no gravado y exento. */
    public const ITEM_NO_GRAVADO = 1;
    public const ITEM_EXENTO = 2;

    /** Número de comprobante no correlativo. */
    public const ERROR_NUMERO = 102;
    /** No se registran comprobantes (último autorizado = 0). */
    public const ERROR_SIN_COMPROBANTES = 1502;

    public function __construct(
        AuthManager $auth,
        string $cuit,
        Environment $environment = Environment::HOMOLOGACION,
        ?SoapTransportInterface $transport = null,
        ?string $endpoint = null
    ) {
        parent::__construct($auth, $cuit, new SoapClient(
            $endpoint ?? Endpoints::get(Endpoints::WSMTXCA, $environment),
            self::NAMESPACE,
            $transport ?? new CurlTransport(),
            false,
            self::NAMESPACE
        ));
    }

    public function getServiceName(): string
    {
        return self::SERVICE;
    }

    /**
     * @return array{appserver: string, dbserver: string, authserver: string}
     */
    public function dummy(): array
    {
        $r = $this->client->call('dummy', [], '');

        return [
            'appserver' => (string) Arr::string($r, 'appserver', ''),
            'dbserver' => (string) Arr::string($r, 'dbserver', ''),
            'authserver' => (string) Arr::string($r, 'authserver', ''),
        ];
    }

    public function getLastVoucher(int $puntoVenta, int $tipoComprobante): int
    {
        $r = $this->request('consultarUltimoComprobanteAutorizado', static fn (array $auth): array => [
            'authRequest' => $auth,
            'consultaUltimoComprobanteAutorizadoRequest' => [
                'codigoTipoComprobante' => $tipoComprobante,
                'numeroPuntoVenta' => $puntoVenta,
            ],
        ]);
        $errors = self::codigos($r, 'arrayErrores.codigoDescripcion');
        foreach ($errors as $error) {
            if ($error->getCode() === self::ERROR_SIN_COMPROBANTES) {
                return 0;
            }
        }
        self::throwIfErrors('consultarUltimoComprobanteAutorizado', $errors);

        return (int) Arr::int($r, 'numeroComprobante', 0);
    }

    /**
     * Solicita el CAE de un comprobante con detalle de ítems.
     */
    public function authorize(Invoice $invoice): AuthorizationResult
    {
        $comprobante = $this->buildComprobante($invoice);
        $r = $this->request('autorizarComprobante', static fn (array $auth): array => [
            'authRequest' => $auth,
            'comprobanteCAERequest' => $comprobante,
        ]);

        $result = $this->toResult($r, 'comprobanteResponse', 'CAE', 'fechaVencimientoCAE', $invoice);
        if ($result->isSuccess()) {
            $invoice->setCae($result->getCae());
            $invoice->setCaeVencimiento($result->getCaeVencimiento());
        }

        return $result;
    }

    /**
     * Consulta un comprobante autorizado. Devuelve null si no existe.
     *
     * @return array<string, mixed>|null nodo "comprobante" tal como lo devuelve ARCA
     */
    public function getVoucher(int $tipoComprobante, int $puntoVenta, int $numero): ?array
    {
        $r = $this->request('consultarComprobante', static fn (array $auth): array => [
            'authRequest' => $auth,
            'consultaComprobanteRequest' => [
                'codigoTipoComprobante' => $tipoComprobante,
                'numeroPuntoVenta' => $puntoVenta,
                'numeroComprobante' => $numero,
            ],
        ]);
        $comprobante = Arr::get($r, 'comprobante');
        if (is_array($comprobante)) {
            return $comprobante;
        }
        // Inexistente: ARCA informa un error; cualquier otro error se propaga.
        $errors = self::codigos($r, 'arrayErrores.codigoDescripcion');
        foreach ($errors as $error) {
            $msg = mb_strtolower($error->getMessage());
            if (str_contains($msg, 'no exist') || str_contains($msg, 'no se registra') || str_contains($msg, 'no se encontr')) {
                return null;
            }
        }
        self::throwIfErrors('consultarComprobante', $errors);

        return null;
    }

    // ------------------------------------------------------------------ CAEA

    public function requestCaea(int $periodo, int $orden): CaeaInfo
    {
        $r = $this->request('solicitarCAEA', static fn (array $auth): array => [
            'authRequest' => $auth,
            'solicitudCAEA' => ['periodo' => $periodo, 'orden' => $orden],
        ]);
        self::throwIfErrors('solicitarCAEA', self::codigos($r, 'arrayErrores.codigoDescripcion'));

        return self::caeaInfo((array) Arr::get($r, 'CAEAResponse', []));
    }

    public function getCaea(string $caea): CaeaInfo
    {
        $r = $this->request('consultarCAEA', static fn (array $auth): array => ['authRequest' => $auth, 'CAEA' => $caea]);
        self::throwIfErrors('consultarCAEA', self::codigos($r, 'arrayErrores.codigoDescripcion'));

        return self::caeaInfo((array) Arr::get($r, 'CAEAResponse', []));
    }

    /**
     * @return CaeaInfo[]
     */
    public function getCaeaBetween(\DateTimeInterface $desde, \DateTimeInterface $hasta): array
    {
        $r = $this->request('consultarCAEAEntreFechas', static fn (array $auth): array => [
            'authRequest' => $auth,
            'fechaDesde' => $desde->format('Y-m-d'),
            'fechaHasta' => $hasta->format('Y-m-d'),
        ]);
        self::throwIfErrors('consultarCAEAEntreFechas', self::codigos($r, 'arrayErrores.codigoDescripcion'));

        return array_map(static fn (mixed $n): CaeaInfo => self::caeaInfo((array) $n), Arr::list(Arr::get($r, 'arrayCAEAResponse.CAEAResponse')));
    }

    /**
     * Informa un comprobante emitido con CAEA (el Invoice debe tener CAEA, número y fecha/hora de generación).
     */
    public function informCaeaVoucher(Invoice $invoice): AuthorizationResult
    {
        $comprobante = $this->buildComprobante($invoice, true);
        $r = $this->request('informarComprobanteCAEA', static fn (array $auth): array => [
            'authRequest' => $auth,
            'comprobanteCAEARequest' => $comprobante,
        ]);

        return $this->toResult($r, 'comprobanteCAEAResponse', 'CAEA', null, $invoice);
    }

    /**
     * @return array<string, mixed>
     */
    public function informCaeaNotUsed(string $caea, ?int $puntoVenta = null): array
    {
        $operation = $puntoVenta === null ? 'informarCAEANoUtilizado' : 'informarCAEANoUtilizadoPtoVta';
        $r = $this->request($operation, static fn (array $auth): array => ['authRequest' => $auth, 'CAEA' => $caea]
            + ($puntoVenta === null ? [] : ['numeroPuntoVenta' => $puntoVenta]));
        self::throwIfErrors($operation, self::codigos($r, 'arrayErrores.codigoDescripcion'));

        return $r;
    }

    // ------------------------------------------------------------ Parámetros

    /** @return array<int, array{codigo: string, descripcion: string}> */
    public function getVoucherTypes(): array
    {
        return $this->param('consultarTiposComprobante', 'arrayTiposComprobante');
    }

    /** @return array<int, array{codigo: string, descripcion: string}> */
    public function getDocumentTypes(): array
    {
        return $this->param('consultarTiposDocumento', 'arrayTiposDocumento');
    }

    /** @return array<int, array{codigo: string, descripcion: string}> */
    public function getVatRates(): array
    {
        return $this->param('consultarAlicuotasIVA', 'arrayAlicuotasIVA');
    }

    /**
     * Condiciones de IVA de los ítems (1 no gravado, 2 exento, 3..9 alícuotas).
     *
     * @return array<int, array{codigo: string, descripcion: string}>
     */
    public function getItemVatConditions(): array
    {
        return $this->param('consultarCondicionesIVA', 'arrayCondicionesIVA');
    }

    /** @return array<int, array{codigo: string, descripcion: string}> */
    public function getCurrencies(): array
    {
        return $this->param('consultarMonedas', 'arrayMonedas');
    }

    /** @return array<int, array{codigo: string, descripcion: string}> */
    public function getUnits(): array
    {
        return $this->param('consultarUnidadesMedida', 'arrayUnidadesMedida');
    }

    /** @return array<int, array{codigo: string, descripcion: string}> */
    public function getTaxTypes(): array
    {
        return $this->param('consultarTiposTributo', 'arrayTiposTributo');
    }

    /** @return array<int, array{codigo: string, descripcion: string}> */
    public function getAdditionalDataTypes(): array
    {
        return $this->param('consultarTiposDatosAdicionales', 'arrayTiposDatosAdicionales');
    }

    public function getQuotation(string $moneda): float
    {
        $r = $this->request('consultarCotizacionMoneda', static fn (array $auth): array => ['authRequest' => $auth, 'codigoMoneda' => $moneda]);
        self::throwIfErrors('consultarCotizacionMoneda', self::codigos($r, 'arrayErrores.codigoDescripcion'));

        return (float) Arr::float($r, 'cotizacionMoneda', 0.0);
    }

    /**
     * Puntos de venta (todos, solo CAE o solo CAEA).
     *
     * @return array<int, array{numero: int, bloqueado: bool, baja: ?string}>
     */
    public function getSalesPoints(string $tipo = 'CAE'): array
    {
        $operation = match (strtoupper($tipo)) {
            'CAE' => 'consultarPuntosVentaCAE',
            'CAEA' => 'consultarPuntosVentaCAEA',
            default => 'consultarPuntosVenta',
        };
        $r = $this->request($operation, static fn (array $auth): array => ['authRequest' => $auth]);

        return array_map(static fn (mixed $p): array => [
            'numero' => (int) Arr::int($p, 'numeroPuntoVenta', 0),
            'bloqueado' => strtoupper((string) Arr::string($p, 'bloqueado', 'N')) === 'S',
            'baja' => Arr::string($p, 'fechaBaja') ?: null,
        ], array_values(array_filter(Arr::list(Arr::get($r, 'arrayPuntosVenta.puntoVenta')), 'is_array')));
    }

    // --------------------------------------------------------------- Mapeo

    /**
     * Código de condición de IVA del ítem en WSMTXCA.
     */
    public static function itemVatCode(SaleDetail $detail): int
    {
        return match ($detail->getTratamientoIva()) {
            SaleDetail::EXENTO => self::ITEM_EXENTO,
            SaleDetail::NO_GRAVADO => self::ITEM_NO_GRAVADO,
            default => (int) $detail->getAlicuotaIva(),
        };
    }

    /**
     * Arma el nodo ComprobanteType respetando el orden del WSDL.
     *
     * @return array<string, mixed>
     */
    public function buildComprobante(Invoice $invoice, bool $caea = false): array
    {
        $client = $invoice->getClient();
        if ($client === null || $invoice->getNumero() === null) {
            throw new AfipException('El comprobante debe tener receptor y número asignado.');
        }
        $servicios = Concepto::includesServices($invoice->getConcepto());
        $moneda = $invoice->getMoneda();
        $discrimina = in_array(TipoComprobante::clase($invoice->getTipoComprobante()), ['A', 'M'], true);

        $data = [
            'codigoTipoComprobante' => $invoice->getTipoComprobante(),
            'numeroPuntoVenta' => $invoice->getPuntoVenta(),
            'numeroComprobante' => $invoice->getNumero(),
            'fechaEmision' => $invoice->getFechaEmision()->format('Y-m-d'),
            'codigoTipoAutorizacion' => $caea ? 'A' : null,
            'codigoAutorizacion' => $caea ? $invoice->getCaea() : null,
            'fechaVencimiento' => null,
            'codigoTipoDocumento' => $client->getTipoDoc(),
            'numeroDocumento' => $client->getNumDoc() === '' ? '0' : $client->getNumDoc(),
            'importeGravado' => Num::format($invoice->getImpNeto()),
            'importeNoGravado' => Num::format($invoice->getImpNoGravado()),
            'importeExento' => Num::format($invoice->getImpExento()),
            'importeSubtotal' => Num::format($invoice->getImpNeto() + $invoice->getImpNoGravado() + $invoice->getImpExento()),
            'importeOtrosTributos' => $invoice->getTributos() !== [] ? Num::format($invoice->getImpTributos()) : null,
            'importeTotal' => Num::format($invoice->getImpTotal()),
            'codigoMoneda' => $moneda,
            'cotizacionMoneda' => Num::formatCompact($moneda === Moneda::PESOS ? 1.0 : $invoice->getCotizacion(), 6),
            'observaciones' => $invoice->getObservaciones(),
            'codigoConcepto' => $invoice->getConcepto(),
            'fechaServicioDesde' => $servicios ? $invoice->getFechaServicioDesde()?->format('Y-m-d') : null,
            'fechaServicioHasta' => $servicios ? $invoice->getFechaServicioHasta()?->format('Y-m-d') : null,
            'fechaVencimientoPago' => $invoice->getFechaVencimientoPago()?->format('Y-m-d'),
            'fechaHoraGen' => $caea ? $invoice->getFechaHoraGeneracion()?->format('Y-m-d\TH:i:s') : null,
            'cancelaEnMismaMonedaExtranjera' => $moneda === Moneda::PESOS ? null : ($invoice->getPagoMismaMoneda() ? 'S' : 'N'),
            'condicionIVAReceptor' => $client->getCondicionIva(),
            'arrayComprobantesAsociados' => null,
            'periodoComprobantesAsociados' => null,
            'arrayOtrosTributos' => null,
            'arrayItems' => ['item' => array_map(fn (SaleDetail $d): array => $this->buildItem($d, $discrimina), $invoice->getDetails())],
            'arraySubtotalesIVA' => null,
            'arrayDatosAdicionales' => null,
            'arrayCompradores' => null,
        ];

        if ($invoice->getCbtesAsociados() !== []) {
            $data['arrayComprobantesAsociados'] = ['comprobanteAsociado' => array_map(static fn ($a): array => [
                'codigoTipoComprobante' => $a->getTipo(),
                'numeroPuntoVenta' => $a->getPuntoVenta(),
                'numeroComprobante' => $a->getNumero(),
                'cuit' => $a->getCuit(),
                'fechaEmision' => $a->getFecha()?->format('Y-m-d'),
            ], $invoice->getCbtesAsociados())];
        }
        if ($invoice->getPeriodoAsociado() !== null) {
            $data['periodoComprobantesAsociados'] = [
                'fechaDesde' => $invoice->getPeriodoAsociado()->getDesde()->format('Y-m-d'),
                'fechaHasta' => $invoice->getPeriodoAsociado()->getHasta()->format('Y-m-d'),
            ];
        }
        if ($invoice->getTributos() !== []) {
            $data['arrayOtrosTributos'] = ['otroTributo' => array_map(static fn ($t): array => [
                'codigo' => $t->getId(),
                'descripcion' => $t->getDescripcion(),
                'baseImponible' => Num::format($t->getBaseImp()),
                'importe' => Num::format($t->getImporte()),
            ], $invoice->getTributos())];
        }
        if ($invoice->getIvas() !== []) {
            $data['arraySubtotalesIVA'] = ['subtotalIVA' => array_map(static fn ($i): array => [
                'codigo' => $i->getId(),
                'importe' => Num::format($i->getImporte()),
            ], $invoice->getIvas())];
        }
        if ($invoice->getDatosAdicionales() !== []) {
            $data['arrayDatosAdicionales'] = ['datoAdicional' => array_map(static function ($d): array {
                $node = ['t' => $d->getTipo()];
                foreach ($d->getCampos() as $i => $valor) {
                    $node['c' . ($i + 1)] = $valor;
                }

                return $node;
            }, $invoice->getDatosAdicionales())];
        }
        if ($invoice->getCompradores() !== []) {
            $data['arrayCompradores'] = ['comprador' => array_map(static fn ($c): array => [
                'codigoTipoDocumento' => $c->getTipoDoc(),
                'numeroDocumento' => $c->getNumDoc(),
                'porcentaje' => Num::format($c->getPorcentaje()),
            ], $invoice->getCompradores())];
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildItem(SaleDetail $detail, bool $discrimina): array
    {
        $item = $detail->getImporteItem();
        $iva = $detail->getImporteIva();
        if ($item === null || $iva === null) {
            throw new AfipException(sprintf(
                'El ítem "%s" no tiene IVA/importe por ítem: calcule con InvoiceCalculator::calculate($invoice, true).',
                $detail->getDescripcion()
            ));
        }
        $gravado = $detail->getTratamientoIva() === SaleDetail::GRAVADO;
        $rate = $gravado && $detail->getAlicuotaIva() !== null ? AlicuotaIva::rate((int) $detail->getAlicuotaIva()) / 100 : 0.0;

        // En A/M el precio y la bonificación van sin IVA (precio × cantidad − bonificación + IVA = importe);
        // en B van con IVA incluido (precio × cantidad − bonificación = importe).
        $target = $discrimina ? Num::round($item - $iva) : $item;
        $unit = $detail->getPrecioUnitario();
        if ($discrimina && $detail->isPrecioIncluyeIva()) {
            $unit /= (1 + $rate);
        } elseif (!$discrimina && !$detail->isPrecioIncluyeIva()) {
            $unit *= (1 + $rate);
        }
        $qty = $detail->getCantidad();
        $unit = round($unit, 6);
        $bonif = Num::round($qty * $unit - $target);
        if ($bonif < 0 || ($bonif <= 0.01 && $detail->getBonificacion() == 0.0 && $detail->getBonificacionPorcentaje() == 0.0)) {
            // Sin bonificación: el precio se ajusta para que cierre exactamente con el importe.
            $unit = round($target / $qty, 6);
            $bonif = 0.0;
        }

        return [
            'unidadesMtx' => $detail->getUnidadesMtx(),
            'codigoMtx' => $detail->getCodigoMtx(),
            'codigo' => $detail->getCodigo(),
            'descripcion' => $detail->getDescripcion(),
            'cantidad' => Num::formatCompact($qty, 6),
            'codigoUnidadMedida' => $detail->getCodigoUnidadMedida(),
            'precioUnitario' => Num::formatCompact($unit, 6),
            'importeBonificacion' => Num::formatCompact($bonif, 6),
            'codigoCondicionIVA' => self::itemVatCode($detail),
            'importeIVA' => $discrimina && $gravado ? Num::format($iva) : null,
            'importeItem' => Num::format($item),
        ];
    }

    /**
     * @param array<string, mixed> $r
     */
    private function toResult(array $r, string $node, string $caeField, ?string $vtoField, Invoice $invoice): AuthorizationResult
    {
        $resultado = (string) Arr::string($r, 'resultado', '');
        $observaciones = self::codigos($r, 'arrayObservaciones.codigoDescripcion');
        $errors = self::codigos($r, 'arrayErrores.codigoDescripcion');
        $events = self::codigos($r, 'evento');
        $cae = Arr::string($r, $node . '.' . $caeField);
        $approved = in_array($resultado, ['A', 'O'], true); // O = aprobado con observaciones

        $detail = new CaeDetail(
            $approved ? 'A' : 'R',
            $cae !== null && $cae !== '' ? $cae : null,
            $vtoField !== null ? Date::fromAfip(Arr::string($r, $node . '.' . $vtoField)) : null,
            (int) Arr::int($r, $node . '.numeroComprobante', (int) $invoice->getNumero()),
            (int) Arr::int($r, $node . '.numeroComprobante', (int) $invoice->getNumero()),
            Date::fromAfip(Arr::string($r, $node . '.fechaEmision')) ?? Date::toDate($invoice->getFechaEmision()),
            (int) $invoice->getClient()?->getTipoDoc(),
            (string) $invoice->getClient()?->getNumDoc(),
            $observaciones,
            $r
        );

        return (new AuthorizationResult(
            $approved ? 'A' : 'R',
            null,
            $invoice->getPuntoVenta(),
            $invoice->getTipoComprobante(),
            Date::fromAfip(Arr::string($r, 'fechaProceso')),
            [$detail],
            $errors,
            $events,
            $r
        ))->setXml($this->client->getLastRequest(), $this->client->getLastResponse());
    }

    /**
     * @param array<string, mixed> $n
     */
    private static function caeaInfo(array $n): CaeaInfo
    {
        return new CaeaInfo([
            'CAEA' => Arr::string($n, 'CAEA'),
            'Periodo' => Arr::string($n, 'periodo'),
            'Orden' => Arr::string($n, 'orden'),
            'FchVigDesde' => str_replace('-', '', (string) Arr::string($n, 'fechaDesde', '')),
            'FchVigHasta' => str_replace('-', '', (string) Arr::string($n, 'fechaHasta', '')),
            'FchTopeInf' => str_replace('-', '', (string) Arr::string($n, 'fechaTopeInforme', '')),
            'FchProceso' => str_replace('-', '', (string) Arr::string($n, 'fechaProceso', '')),
            'Observaciones' => ['Obs' => array_map(static fn ($m): array => ['Code' => $m['codigo'], 'Msg' => $m['descripcion']], self::codigoDescripcionList($n, 'arrayObservaciones.codigoDescripcion'))],
        ]);
    }

    /**
     * @return array<int, array{codigo: string, descripcion: string}>
     */
    private function param(string $operation, string $arrayNode): array
    {
        $r = $this->request($operation, static fn (array $auth): array => ['authRequest' => $auth]);
        self::throwIfErrors($operation, self::codigos($r, 'arrayErrores.codigoDescripcion'));

        return self::codigoDescripcionList($r, $arrayNode . '.codigoDescripcion');
    }
}
