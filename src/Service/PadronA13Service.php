<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Service;

use RoyaltyFusion\Afip\Auth\AuthManager;
use RoyaltyFusion\Afip\Endpoints;
use RoyaltyFusion\Afip\Environment;
use RoyaltyFusion\Afip\Model\Padron\Persona;
use RoyaltyFusion\Afip\Soap\CurlTransport;
use RoyaltyFusion\Afip\Soap\SoapClient;
use RoyaltyFusion\Afip\Soap\SoapTransportInterface;
use RoyaltyFusion\Afip\Support\Arr;
use RoyaltyFusion\Afip\Support\Cuit;

/**
 * Padrón A13 (ws_sr_padron_a13): datos de identificación y domicilios. Permite además
 * obtener las CUIT/CUIL asociadas a un número de DNI.
 */
class PadronA13Service extends AbstractPadronService
{
    public const SERVICE = Endpoints::PADRON_A13;
    public const NAMESPACE = 'http://a13.soap.ws.server.puc.sr/';

    public function __construct(
        AuthManager $auth,
        string $cuit,
        Environment $environment = Environment::HOMOLOGACION,
        ?SoapTransportInterface $transport = null,
        ?string $endpoint = null
    ) {
        parent::__construct($auth, $cuit, new SoapClient(
            $endpoint ?? Endpoints::get(Endpoints::PADRON_A13, $environment),
            self::NAMESPACE,
            $transport ?? new CurlTransport(),
            false,
            null
        ));
    }

    public function getServiceName(): string
    {
        return self::SERVICE;
    }

    public function getPersona(string $cuit): ?Persona
    {
        $response = $this->callPadron('getPersona', Cuit::normalize($cuit));
        if ($response === null) {
            return null;
        }
        $persona = Arr::get($response, 'personaReturn.persona');

        return is_array($persona) ? Persona::fromA13($persona) : null;
    }

    /**
     * CUIT/CUIL asociadas a un DNI.
     *
     * @return string[]
     */
    public function getCuitsByDocumento(string $documento): array
    {
        $documento = preg_replace('/\D/', '', $documento) ?? '';
        $response = $this->callPadron('getIdPersonaListByDocumento', null, ['documento' => $documento]);
        if ($response === null) {
            return [];
        }

        return array_values(array_map('strval', Arr::list(Arr::get($response, 'idPersonaListReturn.idPersona'))));
    }

    /**
     * @return array<string, mixed>
     */
    public function dummy(): array
    {
        return (array) ($this->client->call('dummy', [])['return'] ?? []);
    }
}
