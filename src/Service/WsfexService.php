<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Service;

use RoyaltyFusion\Afip\Auth\AccessTicket;
use RoyaltyFusion\Afip\Auth\AuthManager;
use RoyaltyFusion\Afip\Catalog\Exportacion;
use RoyaltyFusion\Afip\Catalog\Moneda;
use RoyaltyFusion\Afip\Catalog\TipoComprobante;
use RoyaltyFusion\Afip\Endpoints;
use RoyaltyFusion\Afip\Environment;
use RoyaltyFusion\Afip\Exception\AfipException;
use RoyaltyFusion\Afip\Model\Export\ExportInvoice;
use RoyaltyFusion\Afip\Model\Response\ExportAuthorizationResult;
use RoyaltyFusion\Afip\Model\Response\Message;
use RoyaltyFusion\Afip\Soap\CurlTransport;
use RoyaltyFusion\Afip\Soap\SoapClient;
use RoyaltyFusion\Afip\Soap\SoapTransportInterface;
use RoyaltyFusion\Afip\Support\Arr;
use RoyaltyFusion\Afip\Support\Date;
use RoyaltyFusion\Afip\Support\Num;

/**
 * WSFEXv1 — Factura Electrónica de Exportación (comprobantes clase E: 19, 20 y 21).
 */
class WsfexService extends AbstractService
{
    public const SERVICE = 'wsfex';
    public const NAMESPACE = 'http://ar.gov.afip.dif.fexv1/';

    public function __construct(
        AuthManager $auth,
        string $cuit,
        Environment $environment = Environment::HOMOLOGACION,
        ?SoapTransportInterface $transport = null,
        ?string $endpoint = null
    ) {
        parent::__construct($auth, $cuit, new SoapClient(
            $endpoint ?? Endpoints::get(Endpoints::WSFEX, $environment),
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
        $result = $this->client->call('FEXDummy', [])['FEXDummyResult'] ?? [];

        return is_array($result) ? $result : [];
    }

    /**
     * Último Id de solicitud utilizado (el próximo FEXAuthorize debe usar Id + 1).
     */
    public function getLastId(): int
    {
        $result = $this->request('FEXGetLast_ID', static fn (array $auth): array => ['Auth' => $auth]);
        self::throwIfErrors('FEXGetLast_ID', self::fexErrors($result));

        return (int) Arr::int($result, 'FEXResultGet.Id', 0);
    }

    /**
     * Último número autorizado para el tipo y punto de venta.
     */
    public function getLastVoucher(int $puntoVenta, int $tipoComprobante): int
    {
        $result = $this->request('FEXGetLast_CMP', static fn (array $auth): array => [
            'Auth' => $auth + ['Pto_venta' => $puntoVenta, 'Cbte_Tipo' => $tipoComprobante],
        ]);
        self::throwIfErrors('FEXGetLast_CMP', self::fexErrors($result));

        return (int) Arr::int($result, 'FEXResult_LastCMP.Cbte_nro', 0);
    }

    /**
     * Solicita el CAE del comprobante de exportación. El comprobante debe tener Id y número.
     */
    public function authorize(ExportInvoice $invoice): ExportAuthorizationResult
    {
        $cmp = $this->buildRequest($invoice);
        $result = $this->request('FEXAuthorize', static fn (array $auth): array => [
            'Auth' => $auth,
            'Cmp' => $cmp,
        ]);

        $authorization = ExportAuthorizationResult::fromArray($result)
            ->setXml($this->client->getLastRequest(), $this->client->getLastResponse());
        if ($authorization->isSuccess()) {
            $invoice->setCae($authorization->getCae());
            $invoice->setCaeVencimiento($authorization->getCaeVencimiento());
        }

        return $authorization;
    }

    /**
     * Consulta un comprobante de exportación autorizado. Devuelve null si no existe.
     *
     * @return array<string, mixed>|null
     */
    public function getVoucher(int $tipoComprobante, int $puntoVenta, int $numero): ?array
    {
        $result = $this->request('FEXGetCMP', static fn (array $auth): array => [
            'Auth' => $auth,
            'Cmp' => [
                'Cbte_tipo' => $tipoComprobante,
                'Punto_vta' => $puntoVenta,
                'Cbte_nro' => $numero,
            ],
        ]);
        $data = Arr::get($result, 'FEXResultGet');
        if (is_array($data) && Arr::string($data, 'Cae') !== null) {
            return $data;
        }
        $errors = self::fexErrors($result);
        if ($errors === []) {
            return null;
        }
        // Comprobante inexistente: ARCA lo informa como error.
        foreach ($errors as $error) {
            if (stripos($error->getMessage(), 'no existe') !== false || stripos($error->getMessage(), 'no encontr') !== false) {
                return null;
            }
        }
        self::throwIfErrors('FEXGetCMP', $errors);

        return null;
    }

    /**
     * Verifica un permiso de embarque.
     *
     * @return array<string, mixed>
     */
    public function checkPermit(string $permiso, int $paisDestino): array
    {
        $result = $this->request('FEXCheck_Permiso', static fn (array $auth): array => [
            'Auth' => $auth,
            'ID_Permiso' => $permiso,
            'Dst_merc' => $paisDestino,
        ]);
        self::throwIfErrors('FEXCheck_Permiso', self::fexErrors($result));

        return (array) Arr::get($result, 'FEXResultGet', []);
    }

    /** @return array<int, array<string, mixed>> */
    public function getVoucherTypes(): array
    {
        return $this->param('FEXGetPARAM_Cbte_Tipo');
    }

    /** @return array<int, array<string, mixed>> */
    public function getExportTypes(): array
    {
        return $this->param('FEXGetPARAM_Tipo_Expo');
    }

    /** @return array<int, array<string, mixed>> */
    public function getIncoterms(): array
    {
        return $this->param('FEXGetPARAM_Incoterms');
    }

    /** @return array<int, array<string, mixed>> */
    public function getLanguages(): array
    {
        return $this->param('FEXGetPARAM_Idiomas');
    }

    /** @return array<int, array<string, mixed>> */
    public function getUnits(): array
    {
        return $this->param('FEXGetPARAM_UMed');
    }

    /** @return array<int, array<string, mixed>> */
    public function getCountries(): array
    {
        return $this->param('FEXGetPARAM_DST_pais');
    }

    /**
     * CUIT genéricos por país (para Cuit_pais_cliente).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCountryCuits(): array
    {
        return $this->param('FEXGetPARAM_DST_CUIT');
    }

    /** @return array<int, array<string, mixed>> */
    public function getCurrencies(): array
    {
        return $this->param('FEXGetPARAM_MON');
    }

    /** @return array<int, array<string, mixed>> */
    public function getCurrenciesWithQuotation(\DateTimeInterface $fecha): array
    {
        return $this->param('FEXGetPARAM_MON_CON_COTIZACION', ['Fecha_CTZ' => Date::toAfip($fecha)]);
    }

    /** @return array<int, array<string, mixed>> */
    public function getSalesPoints(): array
    {
        return $this->param('FEXGetPARAM_PtoVenta');
    }

    /** @return array<int, array<string, mixed>> */
    public function getOptionalTypes(): array
    {
        return $this->param('FEXGetPARAM_Opcionales');
    }

    /** @return array<int, array<string, mixed>> */
    public function getActivities(): array
    {
        return $this->param('FEXGetPARAM_Actividades');
    }

    /**
     * @return array{moneda: string, cotizacion: float, fecha: ?\DateTimeImmutable}
     */
    public function getQuotation(string $moneda): array
    {
        $result = $this->request('FEXGetPARAM_Ctz', static fn (array $auth): array => [
            'Auth' => $auth,
            'Mon_id' => $moneda,
        ]);
        self::throwIfErrors('FEXGetPARAM_Ctz', self::fexErrors($result));

        return [
            'moneda' => $moneda,
            'cotizacion' => (float) Arr::float($result, 'FEXResultGet.Mon_ctz', 0.0),
            'fecha' => Date::fromAfip(Arr::string($result, 'FEXResultGet.Mon_fecha')),
        ];
    }

    /**
     * Convierte el comprobante en el nodo Cmp (ClsFEXRequest) respetando el orden del WSDL.
     *
     * @return array<string, mixed>
     */
    public function buildRequest(ExportInvoice $invoice): array
    {
        if ($invoice->getId() === null || $invoice->getNumero() === null) {
            throw new AfipException('El comprobante de exportación debe tener Id y número asignados.');
        }
        $moneda = $invoice->getMoneda();
        $isFactura = $invoice->getTipoComprobante() === TipoComprobante::FACTURA_EXPORTACION;

        $cmp = [
            'Id' => $invoice->getId(),
            'Fecha_cbte' => Date::toAfip($invoice->getFechaEmision()),
            'Cbte_Tipo' => $invoice->getTipoComprobante(),
            'Punto_vta' => $invoice->getPuntoVenta(),
            'Cbte_nro' => $invoice->getNumero(),
            'Tipo_expo' => $invoice->getTipoExportacion(),
            'Permiso_existente' => (string) $invoice->getPermisoExistente(),
            'Permisos' => null,
            'Dst_cmp' => $invoice->getPaisDestino(),
            'Cliente' => $invoice->getCliente(),
            'Cuit_pais_cliente' => $invoice->getCuitPaisCliente() !== null && $invoice->getCuitPaisCliente() !== ''
                ? preg_replace('/\D/', '', $invoice->getCuitPaisCliente())
                : null,
            'Domicilio_cliente' => $invoice->getDomicilioCliente(),
            'Id_impositivo' => $invoice->getIdImpositivo(),
            'Moneda_Id' => $moneda,
            'Moneda_ctz' => Num::formatCompact($moneda === Moneda::PESOS ? 1.0 : $invoice->getCotizacion(), 6),
            'CanMisMonExt' => ($isFactura && $moneda !== Moneda::PESOS && $invoice->getPagoMismaMoneda() !== null)
                ? ($invoice->getPagoMismaMoneda() ? 'S' : 'N')
                : null,
            'Obs_comerciales' => $invoice->getObservacionesComerciales(),
            'Imp_total' => Num::format($invoice->getImpTotal()),
            'Obs' => $invoice->getObservaciones(),
            'Cmps_asoc' => null,
            'Forma_pago' => $invoice->getFormaPago(),
            'Incoterms' => $invoice->getIncoterms(),
            'Incoterms_Ds' => $invoice->getIncotermsDescripcion(),
            'Idioma_cbte' => $invoice->getIdioma(),
            'Items' => null,
            'Opcionales' => null,
            'Fecha_pago' => Date::toAfip($invoice->getFechaPago()),
            'Actividades' => null,
        ];

        if ($invoice->getPermisos() !== []) {
            $cmp['Permisos'] = ['Permiso' => array_map(static fn ($p): array => [
                'Id_permiso' => $p->getId(),
                'Dst_merc' => $p->getPaisDestino(),
            ], $invoice->getPermisos())];
        }
        if ($invoice->getCbtesAsociados() !== []) {
            $cmp['Cmps_asoc'] = ['Cmp_asoc' => array_map(static fn ($a): array => [
                'Cbte_tipo' => $a->getTipo(),
                'Cbte_punto_vta' => $a->getPuntoVenta(),
                'Cbte_nro' => $a->getNumero(),
                'Cbte_cuit' => preg_replace('/\D/', '', $a->getCuit()),
            ], $invoice->getCbtesAsociados())];
        }
        if ($invoice->getItems() !== []) {
            $cmp['Items'] = ['Item' => array_map(static fn ($i): array => [
                'Pro_codigo' => $i->getCodigo(),
                'Pro_ds' => $i->getDescripcion(),
                'Pro_qty' => Num::formatCompact($i->getCantidad(), 6),
                'Pro_umed' => $i->getUnidad(),
                'Pro_precio_uni' => Num::formatCompact($i->getPrecioUnitario(), 6),
                'Pro_bonificacion' => Num::format($i->getBonificacion()),
                'Pro_total_item' => Num::format($i->getTotal()),
            ], $invoice->getItems())];
        }
        if ($invoice->getOpcionales() !== []) {
            $cmp['Opcionales'] = ['Opcional' => array_map(static fn ($o): array => [
                'Id' => $o->getId(),
                'Valor' => $o->getValor(),
            ], $invoice->getOpcionales())];
        }
        if ($invoice->getActividades() !== []) {
            $cmp['Actividades'] = ['Actividad' => array_map(
                static fn (int $id): array => ['Id' => $id],
                $invoice->getActividades()
            )];
        }

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
                foreach (self::fexErrors(is_array($result) ? $result : []) as $error) {
                    if (stripos($error->getMessage(), 'token') !== false || stripos($error->getMessage(), 'firma') !== false) {
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
    private static function fexErrors(array $result): array
    {
        $errors = [];
        foreach (Arr::list(Arr::get($result, 'FEXErr')) as $err) {
            if (is_array($err) && (int) Arr::int($err, 'ErrCode', 0) !== 0) {
                $errors[] = Message::fromArray($err);
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<int, array<string, mixed>>
     */
    private function param(string $operation, array $extra = []): array
    {
        $result = $this->request($operation, static fn (array $auth): array => ['Auth' => $auth] + $extra);
        self::throwIfErrors($operation, self::fexErrors($result));
        $resultGet = Arr::get($result, 'FEXResultGet');
        if (!is_array($resultGet) || $resultGet === []) {
            return [];
        }
        $items = reset($resultGet);

        return array_values(array_filter(Arr::list($items), 'is_array'));
    }
}
