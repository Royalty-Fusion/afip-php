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
 * Padrón A5 — Constancia de Inscripción (ws_sr_constancia_inscripcion).
 *
 * Devuelve datos generales, domicilio fiscal, impuestos (IVA, monotributo) y
 * actividades. Ideal para autocompletar clientes y deducir su condición frente al IVA.
 */
class PadronA5Service extends AbstractPadronService
{
    public const SERVICE = Endpoints::PADRON_A5;
    public const NAMESPACE = 'http://a5.soap.ws.server.puc.sr/';

    public function __construct(
        AuthManager $auth,
        string $cuit,
        Environment $environment = Environment::HOMOLOGACION,
        ?SoapTransportInterface $transport = null,
        ?string $endpoint = null
    ) {
        parent::__construct($auth, $cuit, new SoapClient(
            $endpoint ?? Endpoints::get(Endpoints::PADRON_A5, $environment),
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

    /**
     * Consulta un contribuyente por CUIT. Devuelve null si no existe.
     */
    public function getPersona(string $cuit): ?Persona
    {
        $response = $this->callPadron('getPersona_v2', Cuit::normalize($cuit));
        if ($response === null) {
            return null;
        }
        $data = Arr::get($response, 'personaReturn');

        return is_array($data) ? Persona::fromA5($data) : null;
    }

    /**
     * Consulta varios contribuyentes (hasta 250 por llamada).
     *
     * @param string[] $cuits
     *
     * @return Persona[]
     */
    public function getPersonas(array $cuits): array
    {
        $ids = array_values(array_map(static fn (string $c): string => Cuit::normalize($c), $cuits));
        $response = $this->callWithTicket(
            'getPersonaList_v2',
            fn ($ticket): array => [
                'token' => $ticket->getToken(),
                'sign' => $ticket->getSign(),
                'cuitRepresentada' => $this->cuit,
                'idPersona' => $ids,
            ],
            static fn (): bool => false
        );
        $result = [];
        foreach (Arr::list(Arr::get($response, 'personaListReturn.persona')) as $persona) {
            if (is_array($persona)) {
                $result[] = Persona::fromA5($persona);
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function dummy(): array
    {
        return (array) ($this->client->call('dummy', [])['return'] ?? []);
    }
}
