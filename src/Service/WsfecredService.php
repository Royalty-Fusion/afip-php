<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Service;

use RoyaltyFusion\Afip\Auth\AuthManager;
use RoyaltyFusion\Afip\Endpoints;
use RoyaltyFusion\Afip\Environment;
use RoyaltyFusion\Afip\Model\Response\OperationResult;
use RoyaltyFusion\Afip\Soap\CurlTransport;
use RoyaltyFusion\Afip\Soap\SoapClient;
use RoyaltyFusion\Afip\Soap\SoapTransportInterface;
use RoyaltyFusion\Afip\Support\Arr;
use RoyaltyFusion\Afip\Support\Cuit;
use RoyaltyFusion\Afip\Support\Date;
use RoyaltyFusion\Afip\Support\Num;

/**
 * WSFECRED — Factura de Crédito Electrónica MiPyME (RG 4367).
 *
 * Como emisor: verificar si el receptor está obligado a recibir FCE y desde qué monto.
 * Como receptor (compras): consultar las FCE recibidas, aceptarlas, rechazarlas,
 * rechazar notas e informar la cancelación total.
 */
class WsfecredService extends AbstractJavaService
{
    public const SERVICE = 'wsfecred';
    public const NAMESPACE = 'http://ar.gob.afip.wsfecred/FECredService/';

    public const ROL_EMISOR = 'Emisor';
    public const ROL_RECEPTOR = 'Receptor';

    public function __construct(
        AuthManager $auth,
        string $cuit,
        Environment $environment = Environment::HOMOLOGACION,
        ?SoapTransportInterface $transport = null,
        ?string $endpoint = null
    ) {
        parent::__construct($auth, $cuit, new SoapClient(
            $endpoint ?? Endpoints::get(Endpoints::WSFECRED, $environment),
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
     * Identificador de un comprobante FCE.
     *
     * @return array{CUITEmisor: string, codTipoCmp: int, ptoVta: int, nroCmp: int}
     */
    public static function comprobante(string $cuitEmisor, int $tipo, int $puntoVenta, int $numero): array
    {
        return ['CUITEmisor' => Cuit::normalize($cuitEmisor), 'codTipoCmp' => $tipo, 'ptoVta' => $puntoVenta, 'nroCmp' => $numero];
    }

    /**
     * Identificador de la cuenta corriente de una FCE: por código de cuenta corriente o por
     * la factura (ARCA admite uno u otro; si se indican ambos se usa el código).
     *
     * @param array{CUITEmisor: string, codTipoCmp: int, ptoVta: int, nroCmp: int}|null $factura
     *
     * @return array{codCtaCte?: int, idFactura?: array<string, mixed>}
     */
    public static function cuentaCorriente(?int $codCtaCte, ?array $factura = null): array
    {
        if (($codCtaCte === null || $codCtaCte <= 0) && $factura === null) {
            throw new \InvalidArgumentException('Indique el código de cuenta corriente o la factura.');
        }

        return array_filter(['codCtaCte' => $codCtaCte, 'idFactura' => $factura], static fn ($v): bool => $v !== null && $v !== 0);
    }

    /**
     * @return array<string, mixed>
     */
    public function dummy(): array
    {
        $r = $this->client->call('dummy', [], '');

        return (array) ($r['dummyReturn'] ?? $r);
    }

    /**
     * ¿El receptor está obligado a recibir FCE para una operación de esa fecha? y desde qué monto.
     *
     * @return array{obligado: bool, montoDesde: float}
     */
    public function getObligation(string $cuitReceptor, ?\DateTimeInterface $fecha = null): array
    {
        $fecha ??= Date::today();
        $r = $this->ret('consultarMontoObligadoRecepcion', static fn (array $auth): array => [
            'authRequest' => $auth,
            'cuitConsultada' => Cuit::normalize($cuitReceptor),
            'fechaEmision' => $fecha->format('Y-m-d'),
        ]);
        self::throwIfErrors('consultarMontoObligadoRecepcion', self::codigos($r, 'arrayErrores.codigoDescripcion'));

        return [
            'obligado' => strtoupper((string) Arr::string($r, 'obligado', 'N')) === 'S',
            'montoDesde' => (float) Arr::float($r, 'montoDesde', 0.0),
        ];
    }

    /**
     * ¿La CUIT está obligada (u optó) a recibir FCE? y desde qué fecha.
     *
     * @return array{obligado: bool, desde: ?\DateTimeImmutable}
     */
    public function getReceiverStatus(string $cuit): array
    {
        $r = $this->ret('consultarObligadoRecepcion', static fn (array $auth): array => [
            'authRequest' => $auth,
            'cuitConsultada' => Cuit::normalize($cuit),
        ]);
        self::throwIfErrors('consultarObligadoRecepcion', self::codigos($r, 'arrayErrores.codigoDescripcion'));

        return [
            'obligado' => strtoupper((string) Arr::string($r, 'respuesta', 'N')) === 'S',
            'desde' => Date::fromAfip(Arr::string($r, 'desde')),
        ];
    }

    /**
     * Comprobantes FCE emitidos o recibidos.
     *
     * @param array{cuitContraparte?: string, tipo?: int, estado?: string, fecha?: array{tipo: string, desde: \DateTimeInterface, hasta: \DateTimeInterface}, codCtaCte?: int, estadoCtaCte?: string, pagina?: int} $filtros
     *
     * @return array{comprobantes: array<int, array<string, mixed>>, hayMas: bool, pagina: int}
     */
    public function getVouchers(string $rol = self::ROL_RECEPTOR, array $filtros = []): array
    {
        $r = $this->ret('consultarComprobantes', static fn (array $auth): array => [
            'authRequest' => $auth,
            'rolCUITRepresentada' => $rol,
            'CUITContraparte' => isset($filtros['cuitContraparte']) ? Cuit::normalize($filtros['cuitContraparte']) : null,
            'codTipoCmp' => $filtros['tipo'] ?? null,
            'estadoCmp' => $filtros['estado'] ?? null,
            'fecha' => isset($filtros['fecha']) ? [
                'tipo' => $filtros['fecha']['tipo'],
                'desde' => $filtros['fecha']['desde']->format('Y-m-d'),
                'hasta' => $filtros['fecha']['hasta']->format('Y-m-d'),
            ] : null,
            'codCtaCte' => $filtros['codCtaCte'] ?? null,
            'estadoCtaCte' => $filtros['estadoCtaCte'] ?? null,
            'nroPagina' => $filtros['pagina'] ?? null,
        ], 'consultarCmpReturn');
        self::throwIfErrors('consultarComprobantes', self::codigos($r, 'arrayErrores.codigoDescripcion'));

        return [
            'comprobantes' => array_values(array_filter(Arr::list(Arr::get($r, 'arrayComprobantes.comprobante')), 'is_array')),
            'hayMas' => strtoupper((string) Arr::string($r, 'hayMas', 'N')) === 'S',
            'pagina' => (int) Arr::int($r, 'nroPagina', 1),
        ];
    }

    /**
     * Cuentas corrientes de FCE.
     *
     * @param array{cuitContraparte?: string, fecha?: array{tipo: string, desde: \DateTimeInterface, hasta: \DateTimeInterface}, estadoCtaCte?: string, pagina?: int, opcionTransferencia?: string} $filtros
     *
     * @return array{cuentas: array<int, array<string, mixed>>, hayMas: bool, pagina: int}
     */
    public function getAccounts(string $rol = self::ROL_RECEPTOR, array $filtros = []): array
    {
        $r = $this->ret('consultarCtasCtes', static fn (array $auth): array => [
            'authRequest' => $auth,
            'rolCUITRepresentada' => $rol,
            'CUITContraparte' => isset($filtros['cuitContraparte']) ? Cuit::normalize($filtros['cuitContraparte']) : null,
            'fecha' => isset($filtros['fecha']) ? [
                'tipo' => $filtros['fecha']['tipo'],
                'desde' => $filtros['fecha']['desde']->format('Y-m-d'),
                'hasta' => $filtros['fecha']['hasta']->format('Y-m-d'),
            ] : null,
            'estadoCtaCte' => $filtros['estadoCtaCte'] ?? null,
            'nroPagina' => $filtros['pagina'] ?? null,
            'opcionTransferencia' => $filtros['opcionTransferencia'] ?? null,
        ]);
        self::throwIfErrors('consultarCtasCtes', self::codigos($r, 'arrayErrores.codigoDescripcion'));

        return [
            'cuentas' => array_values(array_filter(Arr::list(Arr::get($r, 'arrayInfosCtaCte.infoCtaCte')), 'is_array')),
            'hayMas' => strtoupper((string) Arr::string($r, 'hayMas', 'N')) === 'S',
            'pagina' => (int) Arr::int($r, 'nroPagina', 1),
        ];
    }

    /**
     * Detalle de la cuenta corriente de una FCE.
     *
     * @param array{codCtaCte: int, idFactura: array<string, mixed>} $idCtaCte
     *
     * @return array<string, mixed>|null
     */
    public function getAccount(array $idCtaCte): ?array
    {
        $id = self::idCtaCte($idCtaCte);
        $r = $this->ret('consultarCtaCte', static fn (array $auth): array => ['authRequest' => $auth, 'idCtaCte' => $id]);
        self::throwIfErrors('consultarCtaCte', self::codigos($r, 'arrayErrores.codigoDescripcion'));
        $cta = Arr::get($r, 'ctaCte');

        return is_array($cta) ? $cta : null;
    }

    /**
     * Acepta una FCE recibida (total o parcial, con retenciones, ajustes y notas a confirmar).
     *
     * @param array{codCtaCte: int, idFactura: array<string, mixed>} $idCtaCte
     * @param array{
     *     notas?: array<int, array{idNota: array<string, mixed>, acepta: bool}>,
     *     formasCancelacion?: array<int, array{codigo: int, descripcion: string}>,
     *     retenciones?: array<int, array{codTipo: int, importe: float, porcentaje: float, descMotivo?: string}>,
     *     ajustes?: array<int, array{codigo: int, importe: float}>,
     *     tipoCancelacion?: string,
     *     importeCancelado?: float,
     *     importeTotalRetPesos?: float,
     *     importeEmbargoPesos?: float,
     *     cbuComprador?: string
     * } $opciones
     */
    public function accept(array $idCtaCte, float $saldoAceptado, string $moneda = 'PES', float $cotizacion = 1.0, array $opciones = []): OperationResult
    {
        $params = [
            'idCtaCte' => self::idCtaCte($idCtaCte),
            'arrayConfirmarNotasDC' => isset($opciones['notas']) ? ['confirmarNota' => array_map(static fn (array $n): array => [
                'acepta' => $n['acepta'] ? 'S' : 'N',
                'idNota' => self::idComprobante($n['idNota']),
            ], $opciones['notas'])] : null,
            'arrayFormasCancelacion' => isset($opciones['formasCancelacion']) ? ['codigoDescripcion' => self::codigosDescripciones($opciones['formasCancelacion'])] : null,
            'arrayRetenciones' => isset($opciones['retenciones']) ? ['retencion' => array_map(static fn (array $ret): array => [
                'codTipo' => $ret['codTipo'],
                'importe' => Num::format($ret['importe']),
                'porcentaje' => Num::format($ret['porcentaje']),
                'descMotivo' => isset($ret['descMotivo']) ? self::texto250($ret['descMotivo'], 'descMotivo') : null,
            ], $opciones['retenciones'])] : null,
            'arrayAjustesOperacion' => isset($opciones['ajustes']) ? ['ajuste' => array_map(static fn (array $a): array => [
                'codigo' => $a['codigo'],
                'importe' => Num::format($a['importe']),
            ], $opciones['ajustes'])] : null,
            'tipoCancelacion' => $opciones['tipoCancelacion'] ?? null,
            'importeCancelado' => isset($opciones['importeCancelado']) ? Num::format($opciones['importeCancelado']) : null,
            'importeTotalRetPesos' => isset($opciones['importeTotalRetPesos']) ? Num::format($opciones['importeTotalRetPesos']) : null,
            'importeEmbargoPesos' => isset($opciones['importeEmbargoPesos']) ? Num::format($opciones['importeEmbargoPesos']) : null,
            'saldoAceptado' => Num::format($saldoAceptado),
            'codMoneda' => $moneda,
            'cotizacionMonedaUlt' => Num::formatCompact($cotizacion, 6),
            'informaCBU' => isset($opciones['cbuComprador']) ? 'S' : null,
            'CBUComprador' => $opciones['cbuComprador'] ?? null,
        ];

        return $this->operation('aceptarFECred', $params);
    }

    /**
     * Rechaza una FCE recibida.
     *
     * @param array{codCtaCte: int, idFactura: array<string, mixed>} $idCtaCte
     * @param array<int, array{codMotivo: int, descMotivo: string, justificacion: string}> $motivos
     */
    public function reject(array $idCtaCte, array $motivos): OperationResult
    {
        return $this->operation('rechazarFECred', [
            'idCtaCte' => self::idCtaCte($idCtaCte),
            'arrayMotivosRechazo' => ['motivoRechazo' => self::motivos($motivos)],
        ]);
    }

    /**
     * Rechaza una nota de débito/crédito FCE mientras la factura no fue aceptada ni rechazada.
     *
     * @param array{CUITEmisor: string, codTipoCmp: int, ptoVta: int, nroCmp: int} $idNota
     * @param array<int, array{codMotivo: int, descMotivo: string, justificacion: string}> $motivos
     */
    public function rejectNote(array $idNota, array $motivos): OperationResult
    {
        return $this->operation('rechazarNotaDC', [
            'idComprobante' => self::idComprobante($idNota),
            'arrayMotivosRechazo' => ['motivoRechazo' => self::motivos($motivos)],
        ], 'rechazarNotaDCReturn');
    }

    /**
     * Informa que se canceló (pagó) totalmente una FCE aceptada.
     *
     * @param array{codCtaCte: int, idFactura: array<string, mixed>} $idCtaCte
     * @param array<int, array{codigo: int, descripcion: string}> $formasCancelacion
     */
    public function informTotalCancellation(array $idCtaCte, array $formasCancelacion, float $importe): OperationResult
    {
        return $this->operation('informarCancelacionTotalFECred', [
            'idCtaCte' => self::idCtaCte($idCtaCte),
            'arrayFormasCancelacion' => ['codigoDescripcion' => self::codigosDescripciones($formasCancelacion)],
            'importeCancelacion' => Num::format($importe),
        ]);
    }

    /**
     * Cambia la opción de transferencia (SCA / ADC) de una FCE emitida.
     *
     * @param array{codCtaCte: int, idFactura: array<string, mixed>} $idCtaCte
     */
    public function changeTransferOption(array $idCtaCte, string $opcion): OperationResult
    {
        return $this->operation('modificarOpcionTransferencia', ['idCtaCte' => self::idCtaCte($idCtaCte), 'opcionTransferencia' => $opcion]);
    }

    /** @return array<int, array<string, mixed>> */
    public function getRetentionTypes(): array
    {
        $r = $this->ret('consultarTiposRetenciones', static fn (array $auth): array => ['authRequest' => $auth]);

        return array_values(array_filter(Arr::list(Arr::get($r, 'arrayTiposRetenciones.tipoRetencion')), 'is_array'));
    }

    /** @return array<int, array{codigo: string, descripcion: string}> */
    public function getRejectionReasons(): array
    {
        return $this->codeDescription('consultarTiposMotivosRechazo');
    }

    /** @return array<int, array{codigo: string, descripcion: string}> */
    public function getCancellationMethods(): array
    {
        return $this->codeDescription('consultarTiposFormasCancelacion');
    }

    /** @return array<int, array{codigo: string, descripcion: string}> */
    public function getAdjustmentTypes(): array
    {
        return $this->codeDescription('consultarTiposAjustesOperacion');
    }

    /**
     * Normaliza el orden de los elementos (los servicios Java lo exigen).
     *
     * @param array<string, mixed> $id
     *
     * @return array<string, mixed>
     */
    private static function idComprobante(array $id): array
    {
        return [
            'CUITEmisor' => Cuit::normalize((string) ($id['CUITEmisor'] ?? $id['cuitEmisor'] ?? '')),
            'codTipoCmp' => (int) ($id['codTipoCmp'] ?? $id['tipo'] ?? 0),
            'ptoVta' => (int) ($id['ptoVta'] ?? $id['puntoVenta'] ?? 0),
            'nroCmp' => (int) ($id['nroCmp'] ?? $id['numero'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $id
     *
     * @return array<string, mixed>
     */
    private static function idCtaCte(array $id): array
    {
        // xsd:choice: código de cuenta corriente O identificación de la factura.
        if ((int) ($id['codCtaCte'] ?? 0) > 0) {
            return ['codCtaCte' => (int) $id['codCtaCte']];
        }
        if (!isset($id['idFactura'])) {
            throw new \InvalidArgumentException('Indique el código de cuenta corriente o la factura.');
        }

        return ['idFactura' => self::idComprobante((array) $id['idFactura'])];
    }

    private static function texto250(string $value, string $campo): string
    {
        $len = mb_strlen(trim($value));
        if ($len < 3 || $len > 250) {
            throw new \InvalidArgumentException(sprintf('El campo %s debe tener entre 3 y 250 caracteres.', $campo));
        }

        return $value;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     *
     * @return array<int, array{codigo: int, descripcion: string}>
     */
    private static function codigosDescripciones(array $items): array
    {
        return array_map(static fn (array $i): array => ['codigo' => (int) $i['codigo'], 'descripcion' => (string) ($i['descripcion'] ?? '')], array_values($items));
    }

    /**
     * @param array<int, array<string, mixed>> $motivos
     *
     * @return array<int, array{codMotivo: int, descMotivo: string, justificacion: string}>
     */
    private static function motivos(array $motivos): array
    {
        if ($motivos === []) {
            throw new \InvalidArgumentException('Debe indicar al menos un motivo de rechazo.');
        }
        foreach ($motivos as $m) {
            foreach (['descMotivo', 'justificacion'] as $campo) {
                $len = mb_strlen(trim((string) ($m[$campo] ?? '')));
                if ($len < 3 || $len > 250) {
                    throw new \InvalidArgumentException(sprintf('El campo %s del motivo de rechazo debe tener entre 3 y 250 caracteres.', $campo));
                }
            }
        }

        return array_map(static fn (array $m): array => [
            'codMotivo' => (int) $m['codMotivo'],
            'descMotivo' => (string) ($m['descMotivo'] ?? ''),
            'justificacion' => (string) ($m['justificacion'] ?? ''),
        ], array_values($motivos));
    }

    /**
     * @param array<string, mixed> $params
     */
    private function operation(string $operation, array $params, string $returnNode = 'operacionFECredReturn'): OperationResult
    {
        $r = $this->ret($operation, static fn (array $auth): array => ['authRequest' => $auth] + $params, $returnNode);

        return new OperationResult(
            (string) Arr::string($r, 'resultado', ''),
            self::codigos($r, 'arrayObservaciones.codigoDescripcion'),
            [...self::codigos($r, 'arrayErrores.codigoDescripcion'), ...self::codigos($r, 'arrayErroresFormato.codigoDescripcionString')],
            $r
        );
    }

    /**
     * @return array<int, array{codigo: string, descripcion: string}>
     */
    private function codeDescription(string $operation): array
    {
        $r = $this->ret($operation, static fn (array $auth): array => ['authRequest' => $auth], 'codigoDescripcionReturn');

        return self::codigoDescripcionList($r, 'arrayCodigoDescripcion.codigoDescripcion');
    }

    /**
     * Las respuestas vienen envueltas en un nodo "...Return".
     *
     * @param callable(array<string, mixed>): array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function ret(string $operation, callable $params, ?string $returnNode = null): array
    {
        $r = $this->request($operation, $params);
        $inner = $r[$returnNode ?? $operation . 'Return'] ?? null;
        if (!is_array($inner)) {
            // Algunas operaciones usan otro nombre para el nodo de retorno: se toma el único hijo.
            $children = array_filter($r, 'is_array');
            $inner = count($children) === 1 ? reset($children) : $r;
        }

        return $inner;
    }
}
