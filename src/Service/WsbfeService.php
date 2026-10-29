<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Service;

use RoyaltyFusion\Afip\Auth\AccessTicket;
use RoyaltyFusion\Afip\Auth\AuthManager;
use RoyaltyFusion\Afip\Catalog\Moneda;
use RoyaltyFusion\Afip\Catalog\TipoTributo;
use RoyaltyFusion\Afip\Endpoints;
use RoyaltyFusion\Afip\Environment;
use RoyaltyFusion\Afip\Exception\AfipException;
use RoyaltyFusion\Afip\Model\Response\ExportAuthorizationResult;
use RoyaltyFusion\Afip\Model\Response\Message;
use RoyaltyFusion\Afip\Model\Sale\BonoFiscalInvoice;
use RoyaltyFusion\Afip\Model\Sale\SaleDetail;
use RoyaltyFusion\Afip\Soap\CurlTransport;
use RoyaltyFusion\Afip\Soap\SoapClient;
use RoyaltyFusion\Afip\Soap\SoapTransportInterface;
use RoyaltyFusion\Afip\Support\Arr;
use RoyaltyFusion\Afip\Support\Date;
use RoyaltyFusion\Afip\Support\Num;

/**
 * WSBFEv1 — Bono Fiscal Electrónico (bienes de capital).
 */
class WsbfeService extends AbstractService
{
    public const SERVICE = 'wsbfe';
    public const NAMESPACE = 'http://ar.gov.afip.dif.bfev1/';

    /** Tributos que admite WSBFE y el campo de importe al que se suman. */
    public const TRIBUTOS = [
        TipoTributo::PERCEPCION_IVA => 'Imp_perc',
        TipoTributo::PERCEPCION_IVA_NO_CATEGORIZADO => 'Imp_perc',
        TipoTributo::IMPUESTOS_PROVINCIALES => 'Imp_iibb',
        TipoTributo::IIBB => 'Imp_iibb',
        TipoTributo::PERCEPCION_IIBB => 'Imp_iibb',
        TipoTributo::IMPUESTOS_MUNICIPALES => 'Imp_perc_mun',
        TipoTributo::PERCEPCION_MUNICIPAL => 'Imp_perc_mun',
        TipoTributo::IMPUESTOS_INTERNOS => 'Imp_internos',
    ];

    public function __construct(
        AuthManager $auth,
        string $cuit,
        Environment $environment = Environment::HOMOLOGACION,
        ?SoapTransportInterface $transport = null,
        ?string $endpoint = null
    ) {
        parent::__construct($auth, $cuit, new SoapClient(
            $endpoint ?? Endpoints::get(Endpoints::WSBFE, $environment),
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
     * @return array<string, mixed>
     */
    public function dummy(): array
    {
        $r = $this->client->call('BFEDummy', [])['BFEDummyResult'] ?? [];

        return is_array($r) ? $r : [];
    }

    public function getLastId(): int
    {
        $r = $this->request('BFEGetLast_ID', static fn (array $auth): array => ['Auth' => $auth]);
        self::throwIfErrors('BFEGetLast_ID', self::bfeErrors($r));

        return (int) Arr::int($r, 'BFEResultGet.Id', 0);
    }

    public function getLastVoucher(int $puntoVenta, int $tipoComprobante): int
    {
        $r = $this->request('BFEGetLast_CMP', static fn (array $auth): array => [
            'Auth' => $auth + ['Pto_venta' => $puntoVenta, 'Tipo_cbte' => $tipoComprobante],
        ]);
        self::throwIfErrors('BFEGetLast_CMP', self::bfeErrors($r));

        return (int) Arr::int($r, 'BFEResult_LastCMP.Cbte_nro', 0);
    }

    public function authorize(BonoFiscalInvoice $invoice): ExportAuthorizationResult
    {
        $cmp = $this->buildRequest($invoice);
        $r = $this->request('BFEAuthorize', static fn (array $auth): array => ['Auth' => $auth, 'Cmp' => $cmp]);

        $errors = self::bfeErrors($r);
        $cae = Arr::string($r, 'BFEResultAuth.Cae');
        $obs = trim((string) Arr::string($r, 'BFEResultAuth.Obs', ''));
        $result = (new ExportAuthorizationResult(
            (string) Arr::string($r, 'BFEResultAuth.Resultado', $errors === [] ? '' : 'R'),
            $cae !== null && trim($cae) !== '' ? trim($cae) : null,
            Date::fromAfip(Arr::string($r, 'BFEResultAuth.Fch_venc_Cae')),
            (int) $invoice->getNumero(),
            Date::fromAfip(Arr::string($r, 'BFEResultAuth.Fch_cbte')),
            Arr::string($r, 'BFEResultAuth.Reproceso'),
            $obs === '' ? null : $obs,
            $errors,
            [],
            $r
        ))->setXml($this->client->getLastRequest(), $this->client->getLastResponse());

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
        $r = $this->request('BFEGetCMP', static fn (array $auth): array => [
            'Auth' => $auth,
            'Cmp' => ['Tipo_cbte' => $tipoComprobante, 'Punto_vta' => $puntoVenta, 'Cbte_nro' => $numero],
        ]);
        $data = Arr::get($r, 'BFEResultGet');
        if (is_array($data) && trim((string) Arr::string($data, 'Cae', '')) !== '') {
            return $data;
        }
        $errors = self::bfeErrors($r);
        foreach ($errors as $error) {
            $msg = mb_strtolower($error->getMessage());
            if (str_contains($msg, 'no existe') || str_contains($msg, 'no encontr') || str_contains($msg, 'no se registra')) {
                return null;
            }
        }
        self::throwIfErrors('BFEGetCMP', $errors);

        return null;
    }

    /** @return array<int, array<string, mixed>> */
    public function getVoucherTypes(): array
    {
        return $this->param('BFEGetPARAM_Tipo_Cbte');
    }

    /** @return array<int, array<string, mixed>> */
    public function getDocumentTypes(): array
    {
        return $this->param('BFEGetPARAM_Tipo_doc');
    }

    /** @return array<int, array<string, mixed>> */
    public function getVatTypes(): array
    {
        return $this->param('BFEGetPARAM_Tipo_IVA');
    }

    /** @return array<int, array<string, mixed>> */
    public function getOptionalTypes(): array
    {
        return $this->param('BFEGetPARAM_Tipo_Opc');
    }

    /** @return array<int, array<string, mixed>> */
    public function getZones(): array
    {
        return $this->param('BFEGetPARAM_Zonas');
    }

    /** @return array<int, array<string, mixed>> */
    public function getUnits(): array
    {
        return $this->param('BFEGetPARAM_UMed');
    }

    /** @return array<int, array<string, mixed>> */
    public function getCurrencies(): array
    {
        return $this->param('BFEGetPARAM_MON');
    }

    /** @return array<int, array<string, mixed>> */
    public function getNcmCodes(): array
    {
        return $this->param('BFEGetPARAM_NCM', 'Auth');
    }

    /**
     * @return array<string, mixed>
     */
    public function buildRequest(BonoFiscalInvoice $invoice): array
    {
        $client = $invoice->getClient();
        if ($client === null || $invoice->getId() === null || $invoice->getNumero() === null) {
            throw new AfipException('El bono fiscal debe tener receptor, Id y número asignados.');
        }
        $impuestos = ['Imp_perc' => 0.0, 'Imp_iibb' => 0.0, 'Imp_perc_mun' => 0.0, 'Imp_internos' => 0.0];
        foreach ($invoice->getTributos() as $tributo) {
            $campo = self::TRIBUTOS[$tributo->getId()] ?? null;
            if ($campo === null) {
                throw new AfipException(sprintf('WSBFE no admite el tributo %s.', TipoTributo::description($tributo->getId())));
            }
            $impuestos[$campo] += $tributo->getImporte();
        }
        $moneda = $invoice->getMoneda();

        $cmp = [
            'Id' => $invoice->getId(),
            'Tipo_doc' => $client->getTipoDoc(),
            'Nro_doc' => $client->getNumDoc() === '' ? '0' : $client->getNumDoc(),
            'Zona' => $invoice->getZona(),
            'Tipo_cbte' => $invoice->getTipoComprobante(),
            'Punto_vta' => $invoice->getPuntoVenta(),
            'Cbte_nro' => $invoice->getNumero(),
            'Imp_total' => Num::format($invoice->getImpTotal()),
            'Imp_tot_conc' => Num::format($invoice->getImpNoGravado()),
            'Imp_neto' => Num::format($invoice->getImpNeto()),
            'Impto_liq' => Num::format($invoice->getImpIva()),
            'Impto_liq_rni' => '0.00',
            'Imp_op_ex' => Num::format($invoice->getImpExento()),
            'Imp_perc' => Num::format($impuestos['Imp_perc']),
            'Imp_iibb' => Num::format($impuestos['Imp_iibb']),
            'Imp_perc_mun' => Num::format($impuestos['Imp_perc_mun']),
            'Imp_internos' => Num::format($impuestos['Imp_internos']),
            'Imp_moneda_Id' => $moneda,
            'Imp_moneda_ctz' => Num::formatCompact($moneda === Moneda::PESOS ? 1.0 : $invoice->getCotizacion(), 6),
            'Fecha_cbte' => Date::toAfip($invoice->getFechaEmision()),
            'Fecha_vto_pago' => Date::toAfip($invoice->getFechaVencimientoPago()),
            'CanMisMonExt' => $moneda === Moneda::PESOS ? null : ($invoice->getPagoMismaMoneda() ? 'S' : 'N'),
            'CondicionIVAReceptorId' => $client->getCondicionIva(),
            'Opcionales' => $invoice->getOpcionales() === [] ? null : ['Opcional' => array_map(static fn ($o): array => [
                'Id' => $o->getId(),
                'Valor' => $o->getValor(),
            ], $invoice->getOpcionales())],
            'Items' => ['Item' => array_map(static function (SaleDetail $d): array {
                $item = $d->getImporteItem();
                if ($item === null) {
                    throw new AfipException(sprintf('El ítem "%s" no tiene importe: calcule con InvoiceCalculator::calculate($invoice, true).', $d->getDescripcion()));
                }
                $rate = $d->getTratamientoIva() === SaleDetail::GRAVADO && $d->getAlicuotaIva() !== null
                    ? \RoyaltyFusion\Afip\Catalog\AlicuotaIva::rate((int) $d->getAlicuotaIva()) / 100
                    : 0.0;
                $unit = $d->isPrecioIncluyeIva() ? $d->getPrecioUnitario() / (1 + $rate) : $d->getPrecioUnitario();
                $unit = round($unit, 6);
                $net = Num::round($item - (float) $d->getImporteIva());
                $bonif = Num::round($d->getCantidad() * $unit - $net);
                if ($bonif < 0 || ($bonif <= 0.01 && $d->getBonificacion() == 0.0 && $d->getBonificacionPorcentaje() == 0.0)) {
                    $unit = round($net / $d->getCantidad(), 6);
                    $bonif = 0.0;
                }

                return [
                    'Pro_codigo_ncm' => $d->getCodigoNcm(),
                    'Pro_codigo_sec' => (string) $d->getCodigoSecretaria(),
                    'Pro_ds' => $d->getDescripcion(),
                    'Pro_qty' => Num::formatCompact($d->getCantidad(), 6),
                    'Pro_umed' => $d->getCodigoUnidadMedida(),
                    'Pro_precio_uni' => Num::formatCompact($unit, 6),
                    'Imp_bonif' => Num::format($bonif),
                    'Imp_total' => Num::format($item),
                    'Iva_id' => WsmtxcaService::itemVatCode($d),
                ];
            }, $invoice->getDetails())],
            'CbtesAsoc' => $invoice->getCbtesAsociados() === [] ? null : ['CbteAsoc' => array_map(static fn ($a): array => [
                'Tipo_cbte' => $a->getTipo(),
                'Punto_vta' => $a->getPuntoVenta(),
                'Cbte_nro' => $a->getNumero(),
                'Cuit' => $a->getCuit(),
                'Fecha_cbte' => Date::toAfip($a->getFecha()),
            ], $invoice->getCbtesAsociados())],
        ];

        return $cmp;
    }

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
                $result = $response[$operation . 'Result'] ?? [];
                foreach (self::bfeErrors(is_array($result) ? $result : []) as $error) {
                    $msg = mb_strtolower($error->getMessage());
                    if (str_contains($msg, 'token') || str_contains($msg, 'firma')) {
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
     * @param array<string, mixed> $result
     *
     * @return Message[]
     */
    private static function bfeErrors(array $result): array
    {
        $errors = [];
        foreach (Arr::list(Arr::get($result, 'BFEErr')) as $err) {
            if (is_array($err) && (int) Arr::int($err, 'ErrCode', 0) !== 0) {
                $errors[] = Message::fromArray($err);
            }
        }

        return $errors;
    }

    /**
     * Las operaciones de parámetros de WSBFE usan "auth" en minúscula (salvo NCM).
     *
     * @return array<int, array<string, mixed>>
     */
    private function param(string $operation, string $authNode = 'auth'): array
    {
        $r = $this->request($operation, static fn (array $auth): array => [$authNode => $auth]);
        self::throwIfErrors($operation, self::bfeErrors($r));
        $get = Arr::get($r, 'BFEResultGet');
        if (!is_array($get) || $get === []) {
            return [];
        }

        return array_values(array_filter(Arr::list(reset($get)), 'is_array'));
    }
}
