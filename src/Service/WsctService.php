<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Service;

use RoyaltyFusion\Afip\Auth\AuthManager;
use RoyaltyFusion\Afip\Catalog\Moneda;
use RoyaltyFusion\Afip\Endpoints;
use RoyaltyFusion\Afip\Environment;
use RoyaltyFusion\Afip\Exception\AfipException;
use RoyaltyFusion\Afip\Model\Response\AuthorizationResult;
use RoyaltyFusion\Afip\Model\Response\CaeDetail;
use RoyaltyFusion\Afip\Model\Sale\SaleDetail;
use RoyaltyFusion\Afip\Model\Sale\TurismoInvoice;
use RoyaltyFusion\Afip\Soap\CurlTransport;
use RoyaltyFusion\Afip\Soap\SoapClient;
use RoyaltyFusion\Afip\Soap\SoapTransportInterface;
use RoyaltyFusion\Afip\Support\Arr;
use RoyaltyFusion\Afip\Support\Date;
use RoyaltyFusion\Afip\Support\Num;

/**
 * WSCT — Comprobantes de turismo clase T (195, 196, 197).
 */
class WsctService extends AbstractJavaService
{
    public const SERVICE = 'wsct';
    public const NAMESPACE = 'http://ar.gob.afip.wsct/CTService/';

    public function __construct(
        AuthManager $auth,
        string $cuit,
        Environment $environment = Environment::HOMOLOGACION,
        ?SoapTransportInterface $transport = null,
        ?string $endpoint = null
    ) {
        parent::__construct($auth, $cuit, new SoapClient(
            $endpoint ?? Endpoints::get(Endpoints::WSCT, $environment),
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
     * @return array<string, mixed>
     */
    public function dummy(): array
    {
        $r = $this->client->call('dummy', [], '');

        return (array) ($r['dummyReturn'] ?? $r);
    }

    public function getLastVoucher(int $puntoVenta, int $tipoComprobante): int
    {
        $r = $this->ret('consultarUltimoComprobanteAutorizado', static fn (array $auth): array => [
            'authRequest' => $auth,
            'codigoTipoComprobante' => $tipoComprobante,
            'numeroPuntoVenta' => $puntoVenta,
        ]);
        $errors = self::codigos($r, 'arrayErrores.codigoDescripcion');
        if ($errors !== [] && Arr::get($r, 'numeroComprobante') === null) {
            foreach ($errors as $error) {
                $msg = mb_strtolower($error->getMessage());
                if (str_contains($msg, 'no se registran') || str_contains($msg, 'no existen comprobantes')) {
                    return 0;
                }
            }
            self::throwIfErrors('consultarUltimoComprobanteAutorizado', $errors);
        }

        return (int) Arr::int($r, 'numeroComprobante', 0);
    }

    public function authorize(TurismoInvoice $invoice): AuthorizationResult
    {
        $comprobante = $this->buildComprobante($invoice);
        $r = $this->ret('autorizarComprobante', static fn (array $auth): array => [
            'authRequest' => $auth,
            'comprobanteRequest' => $comprobante,
        ]);

        $resultado = (string) Arr::string($r, 'resultado', '');
        $approved = in_array($resultado, ['A', 'O'], true);
        $cae = Arr::string($r, 'comprobanteResponse.CAE');
        $errors = [
            ...self::codigos($r, 'arrayErrores.codigoDescripcion'),
            ...self::codigos($r, 'arrayErroresFormato.codigoDescripcionString'),
        ];
        $detail = new CaeDetail(
            $approved ? 'A' : 'R',
            $cae !== null && $cae !== '' ? $cae : null,
            Date::fromAfip(Arr::string($r, 'comprobanteResponse.fechaVencimientoCAE')),
            (int) $invoice->getNumero(),
            (int) $invoice->getNumero(),
            Date::fromAfip(Arr::string($r, 'comprobanteResponse.fechaEmision')) ?? Date::toDate($invoice->getFechaEmision()),
            (int) $invoice->getClient()?->getTipoDoc(),
            (string) $invoice->getClient()?->getNumDoc(),
            self::codigos($r, 'arrayObservaciones.codigoDescripcion'),
            $r
        );
        $result = (new AuthorizationResult($approved ? 'A' : 'R', null, $invoice->getPuntoVenta(), $invoice->getTipoComprobante(), null, [$detail], $errors, [], $r))
            ->setXml($this->client->getLastRequest(), $this->client->getLastResponse());
        if ($result->isSuccess()) {
            $invoice->setCae($result->getCae());
            $invoice->setCaeVencimiento($result->getCaeVencimiento());
        }

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getVoucher(int $tipoComprobante, int $puntoVenta, int $numero): ?array
    {
        $r = $this->ret('consultarComprobanteTipoPVentaNro', static fn (array $auth): array => [
            'authRequest' => $auth,
            'codigoTipoComprobante' => $tipoComprobante,
            'numeroPuntoVenta' => $puntoVenta,
            'numeroComprobante' => $numero,
        ]);
        $comprobante = Arr::get($r, 'comprobante');
        if (is_array($comprobante)) {
            return $comprobante;
        }
        $errors = self::codigos($r, 'arrayErrores.codigoDescripcion');
        foreach ($errors as $error) {
            $msg = mb_strtolower($error->getMessage());
            if (str_contains($msg, 'no exist') || str_contains($msg, 'no se registra') || str_contains($msg, 'no se encontr')) {
                return null;
            }
        }
        self::throwIfErrors('consultarComprobanteTipoPVentaNro', $errors);

        return null;
    }

    /**
     * @return array<int, array{numero: int, bloqueado: bool, baja: ?string}>
     */
    public function getSalesPoints(): array
    {
        $r = $this->ret('consultarPuntosVenta', static fn (array $auth): array => ['authRequest' => $auth]);

        return array_map(static fn (mixed $p): array => [
            'numero' => (int) Arr::int($p, 'numeroPuntoVenta', 0),
            'bloqueado' => strtoupper((string) Arr::string($p, 'bloqueado', 'N')) === 'S',
            'baja' => Arr::string($p, 'fechaBaja') ?: null,
        ], array_values(array_filter(Arr::list(Arr::get($r, 'arrayPuntosVenta.puntoVenta')), 'is_array')));
    }

    /** @return array<int, array{codigo: string, descripcion: string}> */
    public function getVoucherTypes(): array
    {
        return $this->param('consultarTiposComprobantes', 'arrayTiposComprobantes.codigoDescripcion');
    }

    /** @return array<int, array{codigo: string, descripcion: string}> */
    public function getDocumentTypes(): array
    {
        return $this->param('consultarTiposDocumento', 'arrayTiposDocumento.codigoDescripcion');
    }

    /** @return array<int, array{codigo: string, descripcion: string}> */
    public function getVatTypes(): array
    {
        return $this->param('consultarTiposIVA', 'arrayTiposIVA.codigoDescripcionString');
    }

    /** @return array<int, array{codigo: string, descripcion: string}> */
    public function getVatConditions(): array
    {
        return $this->param('consultarCondicionesIVA', 'arrayCondicionesIVA.codigoDescripcionString');
    }

    /** @return array<int, array{codigo: string, descripcion: string}> */
    public function getCurrencies(): array
    {
        return $this->param('consultarMonedas', 'arrayTiposMoneda.codigoDescripcionString');
    }

    /** @return array<int, array{codigo: string, descripcion: string}> */
    public function getCountries(): array
    {
        return $this->param('consultarPaises', 'arrayPaises.codigoDescripcionString');
    }

    /** @return array<int, array{codigo: string, descripcion: string}> */
    public function getCountryCuits(): array
    {
        return $this->param('consultarCUITsPaises', 'arrayCuitPaises.codigoDescripcionString');
    }

    /** @return array<int, array{codigo: string, descripcion: string}> */
    public function getTaxTypes(): array
    {
        return $this->param('consultarTiposTributo', 'arrayTiposTributo.codigoDescripcionString');
    }

    /** @return array<int, array{codigo: string, descripcion: string}> */
    public function getAdditionalDataTypes(): array
    {
        return $this->param('consultarTiposDatosAdicionales', 'arrayTiposDatosAdicionales.codigoDescripcionString');
    }

    /** @return array<int, array{codigo: string, descripcion: string}> */
    public function getPaymentMethods(): array
    {
        return $this->param('consultarFormasPago', 'arrayFormasPago.codigoDescripcion');
    }

    /** @return array<int, array{codigo: string, descripcion: string}> */
    public function getCardTypes(int $formaPago): array
    {
        return $this->param('consultarTiposTarjeta', 'arrayTiposTarjeta.codigoDescripcion', ['formaPago' => $formaPago]);
    }

    /** @return array<int, array{codigo: string, descripcion: string}> */
    public function getAccountTypes(): array
    {
        return $this->param('consultarTiposCuenta', 'arrayTiposCuenta.codigoDescripcion');
    }

    /** @return array<int, array{codigo: string, descripcion: string}> */
    public function getItemTypes(): array
    {
        return $this->param('consultarTiposItem', 'arrayTiposItem.codigoDescripcion');
    }

    /** @return array<int, array{codigo: string, descripcion: string}> */
    public function getTourismItemCodes(): array
    {
        return $this->param('consultarCodigosItemTurismo', 'arrayCodigosItem.codigoDescripcion');
    }

    /** @return array<int, array{codigo: string, descripcion: string}> */
    public function getIssuerReceiverRelations(): array
    {
        return $this->param('consultarRelacionEmisorReceptor', 'arrayRelacionesEmisorReceptor.codigoDescripcion');
    }

    public function getQuotation(string $moneda): float
    {
        $r = $this->ret('consultarCotizacion', static fn (array $auth): array => ['authRequest' => $auth, 'codigoMoneda' => $moneda]);
        self::throwIfErrors('consultarCotizacion', self::codigos($r, 'arrayErrores.codigoDescripcion'));

        return (float) Arr::float($r, 'cotizacionMoneda', 0.0);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildComprobante(TurismoInvoice $invoice): array
    {
        $client = $invoice->getClient();
        if ($client === null || $invoice->getNumero() === null) {
            throw new AfipException('El comprobante debe tener receptor y número asignado.');
        }
        $moneda = $invoice->getMoneda();

        $data = [
            'codigoTipoComprobante' => $invoice->getTipoComprobante(),
            'numeroPuntoVenta' => $invoice->getPuntoVenta(),
            'numeroComprobante' => $invoice->getNumero(),
            'fechaEmision' => $invoice->getFechaEmision()->format('Y-m-d'),
            'codigoTipoAutorizacion' => 'E',
            'codigoTipoDocumento' => $client->getTipoDoc(),
            'numeroDocumento' => $client->getNumDoc(),
            'idImpositivo' => (string) $client->getCondicionIva(),
            'codigoPais' => $invoice->getCodigoPais(),
            'domicilioReceptor' => $invoice->getDomicilioReceptor(),
            'codigoRelacionEmisorReceptor' => $invoice->getCodigoRelacionEmisorReceptor(),
            'importeGravado' => Num::format($invoice->getImpNeto()),
            'importeNoGravado' => Num::format($invoice->getImpNoGravado()),
            'importeExento' => Num::format($invoice->getImpExento()),
            'importeOtrosTributos' => $invoice->getTributos() !== [] ? Num::format($invoice->getImpTributos()) : null,
            'importeReintegro' => Num::format($invoice->getImporteReintegro()),
            'importeTotal' => Num::format($invoice->getImpTotal()),
            'codigoMoneda' => $moneda,
            'cotizacionMoneda' => Num::formatCompact($moneda === Moneda::PESOS ? 1.0 : $invoice->getCotizacion(), 6),
            'cancelaEnMismaMonedaExtranjera' => $moneda === Moneda::PESOS ? null : ($invoice->getPagoMismaMoneda() ? 'S' : 'N'),
            'observaciones' => $invoice->getObservaciones(),
            'arrayItems' => ['item' => array_map(static function (SaleDetail $d): array {
                if ($d->getImporteItem() === null) {
                    throw new AfipException(sprintf('El ítem "%s" no tiene importe: calcule con InvoiceCalculator::calculate($invoice, true).', $d->getDescripcion()));
                }

                return [
                    'tipo' => $d->getTipoItem(),
                    'codigoTurismo' => $d->getCodigoTurismo(),
                    'codigo' => $d->getCodigo(),
                    'descripcion' => $d->getDescripcion(),
                    'codigoAlicuotaIVA' => WsmtxcaService::itemVatCode($d),
                    'importeIVA' => Num::format((float) $d->getImporteIva()),
                    'importeItem' => Num::format($d->getImporteItem()),
                ];
            }, $invoice->getDetails())],
            'arrayComprobantesAsociados' => $invoice->getCbtesAsociados() === [] ? null : ['comprobanteAsociado' => array_map(static fn ($a): array => [
                'codigoTipoComprobante' => $a->getTipo(),
                'numeroPuntoVenta' => $a->getPuntoVenta(),
                'numeroComprobante' => $a->getNumero(),
            ], $invoice->getCbtesAsociados())],
            'arrayOtrosTributos' => $invoice->getTributos() === [] ? null : ['otroTributo' => array_map(static fn ($t): array => [
                'codigo' => $t->getId(),
                'descripcion' => $t->getDescripcion(),
                'baseImponible' => Num::format($t->getBaseImp()),
                'importe' => Num::format($t->getImporte()),
            ], $invoice->getTributos())],
            'arraySubtotalesIVA' => $invoice->getIvas() === [] ? null : ['subtotalIVA' => array_map(static fn ($i): array => [
                'codigo' => $i->getId(),
                'importe' => Num::format($i->getImporte()),
            ], $invoice->getIvas())],
            'arrayDatosAdicionales' => $invoice->getDatosAdicionales() === [] ? null : ['tipoDatoAdicional' => array_map(static function ($d): array {
                $node = ['t' => $d->getTipo()];
                foreach ($d->getCampos() as $i => $valor) {
                    $node['c' . ($i + 1)] = $valor;
                }

                return $node;
            }, $invoice->getDatosAdicionales())],
            'arrayFormasPago' => $invoice->getFormasPago() === [] ? null : ['formaPago' => array_map(static fn ($f): array => [
                'codigo' => $f->getCodigo(),
                'tipoTarjeta' => $f->getTipoTarjeta(),
                'numeroTarjeta' => $f->getNumeroTarjeta(),
                'swiftCode' => $f->getSwiftCode(),
                'tipoCuenta' => $f->getTipoCuenta(),
                'numeroCuenta' => $f->getNumeroCuenta(),
            ], $invoice->getFormasPago())],
        ];

        return $data;
    }

    /**
     * Las respuestas de WSCT vienen envueltas en "{operacion}Return".
     *
     * @param callable(array<string, mixed>): array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function ret(string $operation, callable $params): array
    {
        $r = $this->request($operation, $params);
        $inner = $r[$operation . 'Return'] ?? null;

        return is_array($inner) ? $inner : $r;
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<int, array{codigo: string, descripcion: string}>
     */
    private function param(string $operation, string $path, array $extra = []): array
    {
        $r = $this->ret($operation, static fn (array $auth): array => ['authRequest' => $auth] + $extra);
        self::throwIfErrors($operation, self::codigos($r, 'arrayErrores.codigoDescripcion'));

        return self::codigoDescripcionList($r, $path);
    }
}
