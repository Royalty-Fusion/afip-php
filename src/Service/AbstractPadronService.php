<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Service;

use RoyaltyFusion\Afip\Auth\AccessTicket;
use RoyaltyFusion\Afip\Exception\PadronException;
use RoyaltyFusion\Afip\Exception\SoapFaultException;

/**
 * Base de los servicios de padrón (JAX-WS): los errores llegan como SOAP Fault.
 */
abstract class AbstractPadronService extends AbstractService
{
    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>|null null si la persona no existe
     */
    protected function callPadron(string $operation, ?string $idPersona, array $extra = []): ?array
    {
        $params = fn (AccessTicket $ticket): array => [
            'token' => $ticket->getToken(),
            'sign' => $ticket->getSign(),
            'cuitRepresentada' => $this->cuit,
        ] + ($idPersona !== null ? ['idPersona' => $idPersona] : []) + $extra;

        try {
            return $this->client->call($operation, $params($this->ticket()));
        } catch (SoapFaultException $e) {
            if (self::isNotFound($e)) {
                return null;
            }
            if (!self::isAuthError($e)) {
                throw new PadronException('Error al consultar el padrón: ' . $e->getFaultString(), 0, $e);
            }
        }

        // Token vencido o inválido: se renueva y se reintenta una vez.
        $this->auth->invalidate($this->getServiceName());
        try {
            return $this->client->call($operation, $params($this->ticket(true)));
        } catch (SoapFaultException $e) {
            if (self::isNotFound($e)) {
                return null;
            }
            throw new PadronException('Error al consultar el padrón: ' . $e->getFaultString(), 0, $e);
        }
    }

    private static function isNotFound(SoapFaultException $e): bool
    {
        $message = mb_strtolower($e->getFaultString());

        return str_contains($message, 'no existe persona') || str_contains($message, 'no existe la persona');
    }

    private static function isAuthError(SoapFaultException $e): bool
    {
        $message = mb_strtolower($e->getFaultString());

        return str_contains($message, 'token') || str_contains($message, 'expir') || str_contains($message, 'firma');
    }
}
