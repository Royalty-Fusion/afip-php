<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Soap;

use RoyaltyFusion\Afip\Exception\AfipException;
use RoyaltyFusion\Afip\Exception\SoapFaultException;

/**
 * Cliente SOAP 1.1 liviano (no requiere ext-soap ni descargar el WSDL en cada request).
 *
 * Arma el sobre a partir de arrays respetando el orden de los elementos definido en los
 * WSDL de ARCA, y convierte la respuesta en arrays.
 */
final class SoapClient
{
    public const SOAP_ENV_NS = 'http://schemas.xmlsoap.org/soap/envelope/';

    private ?string $lastRequest = null;
    private ?string $lastResponse = null;

    /** @var callable|null */
    private $logger = null;

    /**
     * @param string $endpoint          URL del servicio
     * @param string $namespace         targetNamespace del WSDL
     * @param bool   $qualifiedChildren true si los hijos de la operación van en el namespace
     *                                  (servicios .NET y WSAA); false para servicios JAX-WS (padrón)
     * @param string|null $soapActionPrefix prefijo del SOAPAction (null = SOAPAction vacío)
     */
    public function __construct(
        private readonly string $endpoint,
        private readonly string $namespace,
        private readonly SoapTransportInterface $transport,
        private readonly bool $qualifiedChildren = true,
        private readonly ?string $soapActionPrefix = null
    ) {
    }

    /**
     * Registra un callback fn(string $operation, string $request, ?string $response, ?\Throwable $error)
     * para auditar los mensajes intercambiados.
     */
    public function setLogger(?callable $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Invoca una operación y devuelve el contenido de su elemento de respuesta.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function call(string $operation, array $params): array
    {
        $request = $this->buildEnvelope($operation, $params);
        $this->lastRequest = $request;
        $this->lastResponse = null;

        $headers = [
            'Content-Type' => 'text/xml; charset=utf-8',
            'SOAPAction' => '"' . ($this->soapActionPrefix === null ? '' : $this->soapActionPrefix . $operation) . '"',
        ];

        try {
            $response = $this->transport->send($this->endpoint, $request, $headers);
            $this->lastResponse = $response;
            $result = $this->parseResponse($operation, $response);
        } catch (\Throwable $e) {
            $this->log($operation, $request, $this->lastResponse, $e);
            throw $e;
        }
        $this->log($operation, $request, $response, null);

        return $result;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function buildEnvelope(string $operation, array $params): string
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $envelope = $doc->createElementNS(self::SOAP_ENV_NS, 'soapenv:Envelope');
        $doc->appendChild($envelope);
        $envelope->appendChild($doc->createElementNS(self::SOAP_ENV_NS, 'soapenv:Header'));
        $body = $doc->createElementNS(self::SOAP_ENV_NS, 'soapenv:Body');
        $envelope->appendChild($body);

        if ($this->qualifiedChildren) {
            $operationElement = $doc->createElementNS($this->namespace, $operation);
            XmlUtil::appendArray($doc, $operationElement, $params, $this->namespace);
        } else {
            $operationElement = $doc->createElementNS($this->namespace, 'ns1:' . $operation);
            XmlUtil::appendArray($doc, $operationElement, $params, null);
        }
        $body->appendChild($operationElement);

        return (string) $doc->saveXML();
    }

    /**
     * @return array<string, mixed>
     */
    public function parseResponse(string $operation, string $response): array
    {
        $doc = XmlUtil::load($response);
        $bodies = $doc->getElementsByTagNameNS(self::SOAP_ENV_NS, 'Body');
        if ($bodies->length === 0) {
            $bodies = $doc->getElementsByTagNameNS('http://www.w3.org/2003/05/soap-envelope', 'Body');
        }
        if ($bodies->length === 0) {
            throw new AfipException(sprintf('Respuesta SOAP sin Body en %s', $operation));
        }
        /** @var \DOMElement $body */
        $body = $bodies->item(0);

        $first = null;
        foreach ($body->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $first = $child;
                break;
            }
        }
        if ($first === null) {
            throw new AfipException(sprintf('Respuesta SOAP vacía en %s', $operation));
        }

        if ($first->localName === 'Fault') {
            $fault = XmlUtil::toArray($first);
            $fault = is_array($fault) ? $fault : [];
            $code = $fault['faultcode'] ?? ($fault['Code']['Value'] ?? 'Fault');
            $string = $fault['faultstring'] ?? ($fault['Reason']['Text'] ?? 'SOAP Fault');
            $detail = $fault['detail'] ?? null;
            throw new SoapFaultException(
                is_string($code) ? $code : 'Fault',
                is_string($string) ? $string : json_encode($string, JSON_UNESCAPED_UNICODE),
                is_string($detail) ? $detail : ($detail === null ? null : json_encode($detail, JSON_UNESCAPED_UNICODE))
            );
        }

        $result = XmlUtil::toArray($first);

        return is_array($result) ? $result : ['_value' => $result];
    }

    public function getLastRequest(): ?string
    {
        return $this->lastRequest;
    }

    public function getLastResponse(): ?string
    {
        return $this->lastResponse;
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    private function log(string $operation, string $request, ?string $response, ?\Throwable $error): void
    {
        if ($this->logger !== null) {
            ($this->logger)($operation, $request, $response, $error);
        }
    }
}
