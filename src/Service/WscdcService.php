<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Service;

use RoyaltyFusion\Afip\Auth\AccessTicket;
use RoyaltyFusion\Afip\Auth\AuthManager;
use RoyaltyFusion\Afip\Endpoints;
use RoyaltyFusion\Afip\Environment;
use RoyaltyFusion\Afip\Exception\AfipException;
use RoyaltyFusion\Afip\Model\Response\OperationResult;
use RoyaltyFusion\Afip\Soap\CurlTransport;
use RoyaltyFusion\Afip\Soap\SoapClient;
use RoyaltyFusion\Afip\Soap\SoapTransportInterface;
use RoyaltyFusion\Afip\Support\Arr;
use RoyaltyFusion\Afip\Support\Cuit;
use RoyaltyFusion\Afip\Support\Date;
use RoyaltyFusion\Afip\Support\Num;

/**
 * WSCDC — Constatación de comprobantes: verifica ante ARCA que un comprobante recibido
 * (por ejemplo, la factura de un proveedor) fue autorizado con esos datos.
 */
class WscdcService extends AbstractService
{
    public const SERVICE = 'wscdc';
    public const NAMESPACE = 'http://servicios1.afip.gob.ar/wscdc/';

    public const MODO_CAE = 'CAE';
    public const MODO_CAEA = 'CAEA';
    public const MODO_CAI = 'CAI';

    public function __construct(
        AuthManager $auth,
        string $cuit,
        Environment $environment = Environment::HOMOLOGACION,
        ?SoapTransportInterface $transport = null,
        ?string $endpoint = null
    ) {
        parent::__construct($auth, $cuit, new SoapClient(
            $endpoint ?? Endpoints::get(Endpoints::WSCDC, $environment),
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
        return (array) ($this->client->call('ComprobanteDummy', [])['ComprobanteDummyResult'] ?? []);
    }

    /**
     * Constata un comprobante. Resultado A = válido, R = rechazado (ver observaciones).
     *
     * @param array<int, array{Id: string, Valor: string}> $opcionales
     */
    public function verify(
        string $cuitEmisor,
        int $puntoVenta,
        int $tipoComprobante,
        int $numero,
        \DateTimeInterface $fecha,
        float $importeTotal,
        string $codigoAutorizacion,
        string $modo = self::MODO_CAE,
        ?int $docTipoReceptor = null,
        ?string $docNroReceptor = null,
        array $opcionales = []
    ): OperationResult {
        $cmp = [
            'CbteModo' => $modo,
            'CuitEmisor' => Cuit::normalize($cuitEmisor),
            'PtoVta' => $puntoVenta,
            'CbteTipo' => $tipoComprobante,
            'CbteNro' => $numero,
            'CbteFch' => Date::toAfip($fecha),
            'ImpTotal' => Num::format($importeTotal),
            'CodAutorizacion' => $codigoAutorizacion,
            'DocTipoReceptor' => $docTipoReceptor === null ? null : (string) $docTipoReceptor,
            'DocNroReceptor' => $docNroReceptor,
            'Opcionales' => $opcionales === [] ? null : ['Opcional' => array_map(static fn (array $o): array => ['Id' => $o['Id'], 'Valor' => $o['Valor']], $opcionales)],
        ];
        $r = $this->request('ComprobanteConstatar', static fn (array $auth): array => ['Auth' => $auth, 'CmpReq' => $cmp]);

        return new OperationResult(
            (string) Arr::string($r, 'Resultado', ''),
            self::messages($r, 'Observaciones.Obs'),
            self::messages($r, 'Errors.Err'),
            $r
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function getModes(): array
    {
        return $this->param('ComprobantesModalidadConsultar');
    }

    /** @return array<int, array<string, mixed>> */
    public function getVoucherTypes(): array
    {
        return $this->param('ComprobantesTipoConsultar');
    }

    /** @return array<int, array<string, mixed>> */
    public function getDocumentTypes(): array
    {
        return $this->param('DocumentosTipoConsultar');
    }

    /** @return array<int, array<string, mixed>> */
    public function getOptionalTypes(): array
    {
        return $this->param('OpcionalesTipoConsultar');
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function request(string $operation, callable $params): array
    {
        $response = $this->callWithTicket(
            $operation,
            fn (AccessTicket $ticket): array => $params(['Token' => $ticket->getToken(), 'Sign' => $ticket->getSign(), 'Cuit' => $this->cuit]),
            static function (array $response) use ($operation): bool {
                foreach (self::messages((array) ($response[$operation . 'Result'] ?? []), 'Errors.Err') as $error) {
                    if ($error->getCode() === 600 || stripos($error->getMessage(), 'token') !== false) {
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
     * @return array<int, array<string, mixed>>
     */
    private function param(string $operation): array
    {
        $r = $this->request($operation, static fn (array $auth): array => ['Auth' => $auth]);
        self::throwIfErrors($operation, self::messages($r, 'Errors.Err'));
        $get = Arr::get($r, 'ResultGet');

        return is_array($get) && $get !== [] ? array_values(array_filter(Arr::list(reset($get)), 'is_array')) : [];
    }
}
