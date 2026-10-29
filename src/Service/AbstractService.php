<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Service;

use RoyaltyFusion\Afip\Auth\AccessTicket;
use RoyaltyFusion\Afip\Auth\AuthManager;
use RoyaltyFusion\Afip\Exception\ServiceException;
use RoyaltyFusion\Afip\Model\Response\Message;
use RoyaltyFusion\Afip\Soap\SoapClient;
use RoyaltyFusion\Afip\Support\Arr;
use RoyaltyFusion\Afip\Support\Cuit;

/**
 * Base de los servicios de negocio: administra el ticket de acceso y el cliente SOAP.
 */
abstract class AbstractService
{
    protected string $cuit;

    public function __construct(
        protected readonly AuthManager $auth,
        string $cuit,
        protected readonly SoapClient $client
    ) {
        $this->cuit = Cuit::normalize($cuit);
        if (!Cuit::isValid($this->cuit)) {
            throw new \InvalidArgumentException(sprintf('El CUIT "%s" no es válido.', $cuit));
        }
    }

    /**
     * Nombre del servicio para el WSAA (ej. "wsfe").
     */
    abstract public function getServiceName(): string;

    public function getCuit(): string
    {
        return $this->cuit;
    }

    public function getClient(): SoapClient
    {
        return $this->client;
    }

    public function getLastRequest(): ?string
    {
        return $this->client->getLastRequest();
    }

    public function getLastResponse(): ?string
    {
        return $this->client->getLastResponse();
    }

    protected function ticket(bool $forceRefresh = false): AccessTicket
    {
        return $this->auth->getTicket($this->getServiceName(), $forceRefresh);
    }

    /**
     * Ejecuta la operación. Si ARCA informa token inválido o vencido, renueva el ticket y
     * reintenta una única vez.
     *
     * @param callable(AccessTicket): array<string, mixed> $params
     * @param callable(array<string, mixed>): bool          $isAuthError
     *
     * @return array<string, mixed>
     */
    protected function callWithTicket(string $operation, callable $params, callable $isAuthError): array
    {
        $response = $this->client->call($operation, $params($this->ticket()));
        if ($isAuthError($response)) {
            $this->auth->invalidate($this->getServiceName());
            $response = $this->client->call($operation, $params($this->ticket(true)));
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return Message[]
     */
    protected static function messages(array $result, string $path): array
    {
        return array_map(static fn (mixed $m): Message => Message::fromArray($m), Arr::list(Arr::get($result, $path)));
    }

    /**
     * @param Message[] $errors
     */
    protected static function throwIfErrors(string $operation, array $errors): void
    {
        if ($errors !== []) {
            throw new ServiceException($operation, $errors);
        }
    }
}
