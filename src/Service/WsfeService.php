<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Service;

use RoyaltyFusion\Afip\Auth\AccessTicket;
use RoyaltyFusion\Afip\Auth\AuthManager;
use RoyaltyFusion\Afip\Catalog\Concepto;
use RoyaltyFusion\Afip\Catalog\Moneda;
use RoyaltyFusion\Afip\Endpoints;
use RoyaltyFusion\Afip\Environment;
use RoyaltyFusion\Afip\Exception\AfipException;
use RoyaltyFusion\Afip\Model\Response\AuthorizationResult;
use RoyaltyFusion\Afip\Model\Response\CaeaInfo;
use RoyaltyFusion\Afip\Model\Response\VoucherInfo;
use RoyaltyFusion\Afip\Model\Sale\Invoice;
use RoyaltyFusion\Afip\Soap\CurlTransport;
use RoyaltyFusion\Afip\Soap\SoapClient;
use RoyaltyFusion\Afip\Soap\SoapTransportInterface;
use RoyaltyFusion\Afip\Support\Arr;
use RoyaltyFusion\Afip\Support\Date;
use RoyaltyFusion\Afip\Support\Num;

/**
 * WSFEv1 — Factura Electrónica de mercado interno (CAE y CAEA).
 *
 * Este servicio NO valida los comprobantes: para validar antes de enviar use
 * {@see \RoyaltyFusion\Afip\See::send()} o {@see \RoyaltyFusion\Afip\Validator\InvoiceValidator}.
 */
class WsfeService extends AbstractService
{
    public const SERVICE = 'wsfe';
    public const NAMESPACE = 'http://ar.gov.afip.dif.FEV1/';

    /** Códigos de error de autenticación (token/sign inválidos o vencidos). */
    private const AUTH_ERRORS = [600];
    /** FECompConsultar: comprobante inexistente. */
    public const ERROR_SIN_RESULTADOS = 602;

    public function __construct(
        AuthManager $auth,
        string $cuit,
        Environment $environment = Environment::HOMOLOGACION,
        ?SoapTransportInterface $transport = null,
        ?string $endpoint = null
    ) {
        parent::__construct($auth, $cuit, new SoapClient(
            $endpoint ?? Endpoints::get(Endpoints::WSFE, $environment),
            self::NAMESPACE,
            $transport ?? new CurlTransport(),
            true,
            self::NAMESPACE
        ));
    }

    public function getServiceName(): string
    {
        return self::SERVICE;
    }

    /**
     * Estado de los servidores de ARCA (no requiere autenticación).
     *
     * @return array{AppServer: string, DbServer: string, AuthServer: string}
     */
    public function dummy(): array
    {
        $result = $this->client->call('FEDummy', [])['FEDummyResult'] ?? [];

        return [
            'AppServer' => (string) Arr::string($result, 'AppServer', ''),
            'DbServer' => (string) Arr::string($result, 'DbServer', ''),
            'AuthServer' => (string) Arr::string($result, 'AuthServer', ''),
        ];
    }

    /**
     * Último número de comprobante autorizado (0 si no hay ninguno).
     */
    public function getLastVoucher(int $puntoVenta, int $tipoComprobante): int
    {
        $result = $this->request('FECompUltimoAutorizado', static fn (array $auth): array => [
            'Auth' => $auth,
            'PtoVta' => $puntoVenta,
            'CbteTipo' => $tipoComprobante,
        ]);
        self::throwIfErrors('FECompUltimoAutorizado', self::messages($result, 'Errors.Err'));

        return (int) Arr::int($result, 'CbteNro', 0);
    }

    /**
     * Solicita CAE para uno o más comprobantes del mismo tipo y punto de venta
     * (FECAESolicitar). Los comprobantes deben tener número asignado.
     *
     * Si ARCA aprueba, completa CAE y vencimiento en cada Invoice.
     *
     * @param Invoice|Invoice[] $invoices
     */
    public function authorize(Invoice|array $invoices): AuthorizationResult
    {
        $invoices = $invoices instanceof Invoice ? [$invoices] : array_values($invoices);
        $this->assertBatch($invoices);
        $first = $invoices[0];

        $detail = array_map(fn (Invoice $i): array => $this->buildDetail($i), $invoices);
        $result = $this->request('FECAESolicitar', static fn (array $auth): array => [
            'Auth' => $auth,
            'FeCAEReq' => [
                'FeCabReq' => [
                    'CantReg' => count($invoices),
                    'PtoVta' => $first->getPuntoVenta(),
                    'CbteTipo' => $first->getTipoComprobante(),
                ],
                'FeDetReq' => ['FECAEDetRequest' => $detail],
            ],
        ]);

        $authorization = AuthorizationResult::fromArray($result)
            ->setXml($this->client->getLastRequest(), $this->client->getLastResponse());
        $this->applyResult($invoices, $authorization);

        return $authorization;
    }

    /**
     * Consulta un comprobante autorizado (FECompConsultar). Devuelve null si no existe.
     */
    public function getVoucher(int $tipoComprobante, int $puntoVenta, int $numero): ?VoucherInfo
    {
        $result = $this->request('FECompConsultar', static fn (array $auth): array => [
            'Auth' => $auth,
            'FeCompConsReq' => [
                'CbteTipo' => $tipoComprobante,
                'CbteNro' => $numero,
                'PtoVta' => $puntoVenta,
            ],
        ]);
        $errors = self::messages($result, 'Errors.Err');
        foreach ($errors as $error) {
            if ($error->getCode() === self::ERROR_SIN_RESULTADOS) {
                return null;
            }
        }
        self::throwIfErrors('FECompConsultar', $errors);
        $data = Arr::get($result, 'ResultGet');

        return is_array($data) ? new VoucherInfo($data) : null;
    }

    /**
     * Cantidad máxima de comprobantes por FECAESolicitar.
     */
    public function getMaxRecordsPerRequest(): int
    {
        $result = $this->request('FECompTotXRequest', static fn (array $auth): array => ['Auth' => $auth]);
        self::throwIfErrors('FECompTotXRequest', self::messages($result, 'Errors.Err'));

        return (int) Arr::int($result, 'RegXReq', 0);
    }

    // ------------------------------------------------------------------ CAEA

    /**
     * Solicita un CAEA para el período (yyyymm) y quincena (1 o 2).
     */
    public function requestCaea(int $periodo, int $orden): CaeaInfo
    {
        $result = $this->request('FECAEASolicitar', static fn (array $auth): array => [
            'Auth' => $auth,
            'Periodo' => $periodo,
            'Orden' => $orden,
        ]);
        self::throwIfErrors('FECAEASolicitar', self::messages($result, 'Errors.Err'));

        return new CaeaInfo((array) Arr::get($result, 'ResultGet', []));
    }

    /**
     * Consulta un CAEA ya otorgado.
     */
    public function getCaea(int $periodo, int $orden): CaeaInfo
    {
        $result = $this->request('FECAEAConsultar', static fn (array $auth): array => [
            'Auth' => $auth,
            'Periodo' => $periodo,
            'Orden' => $orden,
        ]);
        self::throwIfErrors('FECAEAConsultar', self::messages($result, 'Errors.Err'));

        return new CaeaInfo((array) Arr::get($result, 'ResultGet', []));
    }

    /**
     * Informa comprobantes emitidos con CAEA (FECAEARegInformativo).
     *
     * @param Invoice|Invoice[] $invoices
     */
    public function informCaeaVouchers(Invoice|array $invoices): AuthorizationResult
    {
        $invoices = $invoices instanceof Invoice ? [$invoices] : array_values($invoices);
        $this->assertBatch($invoices);
        $first = $invoices[0];

        $detail = array_map(fn (Invoice $i): array => $this->buildDetail($i, true), $invoices);
        $result = $this->request('FECAEARegInformativo', static fn (array $auth): array => [
            'Auth' => $auth,
            'FeCAEARegInfReq' => [
                'FeCabReq' => [
                    'CantReg' => count($invoices),
                    'PtoVta' => $first->getPuntoVenta(),
                    'CbteTipo' => $first->getTipoComprobante(),
                ],
                'FeDetReq' => ['FECAEADetRequest' => $detail],
            ],
        ]);

        return AuthorizationResult::fromArray($result)
            ->setXml($this->client->getLastRequest(), $this->client->getLastResponse());
    }

    /**
     * Informa que un punto de venta no tuvo movimientos con el CAEA.
     *
     * @return array<string, mixed>
     */
    public function informCaeaWithoutMovement(string $caea, int $puntoVenta): array
    {
        $result = $this->request('FECAEASinMovimientoInformar', static fn (array $auth): array => [
            'Auth' => $auth,
            'PtoVta' => $puntoVenta,
            'CAEA' => $caea,
        ]);
        self::throwIfErrors('FECAEASinMovimientoInformar', self::messages($result, 'Errors.Err'));

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getCaeaWithoutMovement(string $caea, int $puntoVenta): array
    {
        $result = $this->request('FECAEASinMovimientoConsultar', static fn (array $auth): array => [
            'Auth' => $auth,
            'CAEA' => $caea,
            'PtoVta' => $puntoVenta,
        ]);
        self::throwIfErrors('FECAEASinMovimientoConsultar', self::messages($result, 'Errors.Err'));

        return Arr::list(Arr::get($result, 'ResultGet.FECAEASinMov'));
    }

    // ------------------------------------------------------------ Parámetros

    /** @return array<int, array<string, mixed>> */
    public function getVoucherTypes(): array
    {
        return $this->param('FEParamGetTiposCbte');
    }

    /** @return array<int, array<string, mixed>> */
    public function getConceptTypes(): array
    {
        return $this->param('FEParamGetTiposConcepto');
    }

    /** @return array<int, array<string, mixed>> */
    public function getDocumentTypes(): array
    {
        return $this->param('FEParamGetTiposDoc');
    }

    /** @return array<int, array<string, mixed>> */
    public function getVatTypes(): array
    {
        return $this->param('FEParamGetTiposIva');
    }

    /** @return array<int, array<string, mixed>> */
    public function getCurrencies(): array
    {
        return $this->param('FEParamGetTiposMonedas');
    }

    /** @return array<int, array<string, mixed>> */
    public function getOptionalTypes(): array
    {
        return $this->param('FEParamGetTiposOpcional');
    }

    /** @return array<int, array<string, mixed>> */
    public function getTaxTypes(): array
    {
        return $this->param('FEParamGetTiposTributos');
    }

    /**
     * Puntos de venta habilitados para web services (Nro, EmisionTipo, Bloqueado, FchBaja).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSalesPoints(): array
    {
        return $this->param('FEParamGetPtosVenta');
    }

    /** @return array<int, array<string, mixed>> */
    public function getCountries(): array
    {
        return $this->param('FEParamGetTiposPaises');
    }

    /**
     * Actividades vigentes del emisor.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getActivities(): array
    {
        return $this->param('FEParamGetActividades');
    }

    /**
     * Condiciones frente al IVA del receptor (RG 5616), opcionalmente filtradas por clase (A, B, C, M).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getReceptorVatConditions(?string $clase = null): array
    {
        return $this->param('FEParamGetCondicionIvaReceptor', $clase === null ? [] : ['ClaseCmp' => $clase]);
    }

    /**
     * Cotización oficial de una moneda. La fecha (FchCotiz) es un parámetro agregado en
     * WSFEv1 4.x (RG 5616); si no se indica, no se envía y ARCA devuelve la cotización vigente.
     *
     * @return array{moneda: string, cotizacion: float, fecha: ?\DateTimeImmutable}
     */
    public function getQuotation(string $moneda, ?\DateTimeInterface $fecha = null): array
    {
        $result = $this->request('FEParamGetCotizacion', static fn (array $auth): array => [
            'Auth' => $auth,
            'MonId' => $moneda,
            'FchCotiz' => Date::toAfip($fecha),
        ]);
        self::throwIfErrors('FEParamGetCotizacion', self::messages($result, 'Errors.Err'));

        return [
            'moneda' => (string) Arr::string($result, 'ResultGet.MonId', $moneda),
            'cotizacion' => (float) Arr::float($result, 'ResultGet.MonCotiz', 0.0),
            'fecha' => Date::fromAfip(Arr::string($result, 'ResultGet.FchCotiz')),
        ];
    }

    // --------------------------------------------------------------- Mapeo

    /**
     * Convierte el comprobante en el nodo FECAEDetRequest (o FECAEADetRequest para CAEA),
     * respetando el orden de elementos del WSDL.
     *
     * @return array<string, mixed>
     */
    public function buildDetail(Invoice $invoice, bool $caea = false): array
    {
        $client = $invoice->getClient();
        if ($client === null) {
            throw new AfipException('El comprobante no tiene receptor.');
        }
        if ($invoice->getNumero() === null) {
            throw new AfipException('El comprobante no tiene número asignado.');
        }
        $servicios = Concepto::includesServices($invoice->getConcepto());
        $moneda = $invoice->getMoneda();

        $detail = [
            'Concepto' => $invoice->getConcepto(),
            'DocTipo' => $client->getTipoDoc(),
            'DocNro' => $client->getNumDoc() === '' ? '0' : $client->getNumDoc(),
            'CbteDesde' => $invoice->getNumero(),
            'CbteHasta' => $invoice->getNumeroHasta(),
            'CbteFch' => Date::toAfip($invoice->getFechaEmision()),
            'ImpTotal' => Num::format($invoice->getImpTotal()),
            'ImpTotConc' => Num::format($invoice->getImpNoGravado()),
            'ImpNeto' => Num::format($invoice->getImpNeto()),
            'ImpOpEx' => Num::format($invoice->getImpExento()),
            'ImpTrib' => Num::format($invoice->getImpTributos()),
            'ImpIVA' => Num::format($invoice->getImpIva()),
            'FchServDesde' => $servicios ? Date::toAfip($invoice->getFechaServicioDesde()) : null,
            'FchServHasta' => $servicios ? Date::toAfip($invoice->getFechaServicioHasta()) : null,
            'FchVtoPago' => Date::toAfip($invoice->getFechaVencimientoPago()),
            'MonId' => $moneda,
            'MonCotiz' => Num::formatCompact($moneda === Moneda::PESOS ? 1.0 : $invoice->getCotizacion(), 6),
            'CanMisMonExt' => $moneda === Moneda::PESOS ? null : ($invoice->getPagoMismaMoneda() ? 'S' : 'N'),
            'CondicionIVAReceptorId' => $client->getCondicionIva(),
            'CbtesAsoc' => null,
            'Tributos' => null,
            'Iva' => null,
            'Opcionales' => null,
            'Compradores' => null,
            'PeriodoAsoc' => null,
            'Actividades' => null,
        ];

        if ($invoice->getCbtesAsociados() !== []) {
            $detail['CbtesAsoc'] = ['CbteAsoc' => array_map(static fn ($a): array => [
                'Tipo' => $a->getTipo(),
                'PtoVta' => $a->getPuntoVenta(),
                'Nro' => $a->getNumero(),
                'Cuit' => $a->getCuit(),
                'CbteFch' => Date::toAfip($a->getFecha()),
            ], $invoice->getCbtesAsociados())];
        }
        if ($invoice->getTributos() !== []) {
            $detail['Tributos'] = ['Tributo' => array_map(static fn ($t): array => [
                'Id' => $t->getId(),
                'Desc' => $t->getDescripcion(),
                'BaseImp' => Num::format($t->getBaseImp()),
                'Alic' => Num::format($t->getAlicuota()),
                'Importe' => Num::format($t->getImporte()),
            ], $invoice->getTributos())];
        }
        if ($invoice->getIvas() !== []) {
            $detail['Iva'] = ['AlicIva' => array_map(static fn ($i): array => [
                'Id' => $i->getId(),
                'BaseImp' => Num::format($i->getBaseImp()),
                'Importe' => Num::format($i->getImporte()),
            ], $invoice->getIvas())];
        }
        if ($invoice->getOpcionales() !== []) {
            $detail['Opcionales'] = ['Opcional' => array_map(static fn ($o): array => [
                'Id' => $o->getId(),
                'Valor' => $o->getValor(),
            ], $invoice->getOpcionales())];
        }
        if ($invoice->getCompradores() !== []) {
            $detail['Compradores'] = ['Comprador' => array_map(static fn ($c): array => [
                'DocTipo' => $c->getTipoDoc(),
                'DocNro' => $c->getNumDoc(),
                'Porcentaje' => Num::format($c->getPorcentaje()),
            ], $invoice->getCompradores())];
        }
        if ($invoice->getPeriodoAsociado() !== null) {
            $detail['PeriodoAsoc'] = [
                'FchDesde' => Date::toAfip($invoice->getPeriodoAsociado()->getDesde()),
                'FchHasta' => Date::toAfip($invoice->getPeriodoAsociado()->getHasta()),
            ];
        }
        if ($invoice->getActividades() !== []) {
            $detail['Actividades'] = ['Actividad' => array_map(
                static fn (int $id): array => ['Id' => $id],
                $invoice->getActividades()
            )];
        }

        if ($caea) {
            $detail['CAEA'] = $invoice->getCaea();
            $detail['CbteFchHsGen'] = $invoice->getFechaHoraGeneracion()?->format('YmdHis');
        }

        return $detail;
    }

    // ------------------------------------------------------------ Internos

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    protected function request(string $operation, callable $params): array
    {
        $response = $this->callWithTicket(
            $operation,
            fn (AccessTicket $ticket): array => $params([
                'Token' => $ticket->getToken(),
                'Sign' => $ticket->getSign(),
                'Cuit' => $this->cuit,
            ]),
            static function (array $response) use ($operation): bool {
                foreach (Arr::list(Arr::get($response, $operation . 'Result.Errors.Err')) as $error) {
                    if (in_array((int) Arr::int($error, 'Code', 0), self::AUTH_ERRORS, true)) {
                        return true;
                    }
                }

                return false;
            }
        );

        $result = $response[$operation . 'Result'] ?? null;
        if (!is_array($result)) {
            throw new AfipException(sprintf('Respuesta inesperada de %s.', $operation));
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<int, array<string, mixed>>
     */
    private function param(string $operation, array $extra = []): array
    {
        $result = $this->request($operation, static fn (array $auth): array => ['Auth' => $auth] + $extra);
        $errors = self::messages($result, 'Errors.Err');
        foreach ($errors as $error) {
            if ($error->getCode() === self::ERROR_SIN_RESULTADOS) {
                return []; // 602 "Sin Resultados": la tabla está vacía (ej. puntos de venta en homologación)
            }
        }
        self::throwIfErrors($operation, $errors);
        $resultGet = Arr::get($result, 'ResultGet');
        if (!is_array($resultGet) || $resultGet === []) {
            return [];
        }
        // ResultGet contiene un único tipo de nodo hijo repetido (CbteTipo, DocTipo, ...).
        $items = reset($resultGet);

        return array_values(array_filter(Arr::list($items), 'is_array'));
    }

    /**
     * @param Invoice[] $invoices
     */
    private function assertBatch(array $invoices): void
    {
        if ($invoices === []) {
            throw new \InvalidArgumentException('Debe enviar al menos un comprobante.');
        }
        $first = $invoices[0];
        foreach ($invoices as $invoice) {
            if ($invoice->getTipoComprobante() !== $first->getTipoComprobante() || $invoice->getPuntoVenta() !== $first->getPuntoVenta()) {
                throw new \InvalidArgumentException('Todos los comprobantes de una solicitud deben tener el mismo tipo y punto de venta.');
            }
        }
    }

    /**
     * @param Invoice[] $invoices
     */
    private function applyResult(array $invoices, AuthorizationResult $result): void
    {
        foreach ($result->getDetails() as $detail) {
            if (!$detail->isApproved()) {
                continue;
            }
            foreach ($invoices as $invoice) {
                if ($invoice->getNumero() === $detail->getNumeroDesde()) {
                    $invoice->setCae($detail->getCae());
                    $invoice->setCaeVencimiento($detail->getCaeVencimiento());
                }
            }
        }
    }
}
