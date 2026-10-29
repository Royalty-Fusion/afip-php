<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Service;

use RoyaltyFusion\Afip\Auth\AccessTicket;
use RoyaltyFusion\Afip\Exception\SoapFaultException;
use RoyaltyFusion\Afip\Model\Response\Message;
use RoyaltyFusion\Afip\Support\Arr;

/**
 * Base de los servicios Java de ARCA (WSMTXCA, WSCT, WSFECRED): el elemento del request
 * se llama "{operacion}Request", la autenticación va en authRequest{token, sign,
 * cuitRepresentada} y los mensajes vienen como codigoDescripcion{codigo, descripcion}.
 */
abstract class AbstractJavaService extends AbstractService
{
    /**
     * @param callable(array<string, mixed>): array<string, mixed> $params recibe authRequest
     *
     * @return array<string, mixed> contenido del elemento "{operacion}Response"
     */
    protected function request(string $operation, callable $params): array
    {
        $build = fn (AccessTicket $ticket): array => $params([
            'token' => $ticket->getToken(),
            'sign' => $ticket->getSign(),
            'cuitRepresentada' => $this->cuit,
        ]);

        try {
            $response = $this->client->call($operation, $build($this->ticket()), $operation . 'Request');
            if (!self::hasAuthError($response)) {
                return $response;
            }
        } catch (SoapFaultException $e) {
            if (!self::isAuthMessage($e->getFaultString())) {
                throw $e;
            }
        }

        // Token vencido o inválido: se renueva y se reintenta una vez.
        $this->auth->invalidate($this->getServiceName());

        return $this->client->call($operation, $build($this->ticket(true)), $operation . 'Request');
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return Message[]
     */
    protected static function codigos(array $node, string $path): array
    {
        return array_map(
            static fn (mixed $m): Message => Message::fromArray($m),
            array_values(array_filter(Arr::list(Arr::get($node, $path)), 'is_array'))
        );
    }

    /**
     * @param array<string, mixed> $response
     */
    private static function hasAuthError(array $response): bool
    {
        $nodes = [$response];
        foreach ($response as $value) {
            if (is_array($value)) {
                $nodes[] = $value; // respuestas envueltas en "{operacion}Return"
            }
        }
        foreach ($nodes as $node) {
            foreach (self::codigos($node, 'arrayErrores.codigoDescripcion') as $error) {
                if (self::isAuthMessage($error->getMessage())) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function isAuthMessage(string $message): bool
    {
        $message = mb_strtolower($message);

        return str_contains($message, 'token') || str_contains($message, 'firma') || str_contains($message, 'expirad');
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return array<int, array{codigo: string, descripcion: string}>
     */
    protected static function codigoDescripcionList(array $node, string $path): array
    {
        $result = [];
        foreach (Arr::list(Arr::get($node, $path)) as $item) {
            if (is_array($item)) {
                $result[] = [
                    'codigo' => (string) Arr::string($item, 'codigo', ''),
                    'descripcion' => (string) Arr::string($item, 'descripcion', ''),
                ];
            }
        }

        return $result;
    }
}
