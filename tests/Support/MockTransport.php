<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Tests\Support;

use RoyaltyFusion\Afip\Exception\TransportException;
use RoyaltyFusion\Afip\Soap\SoapTransportInterface;

/**
 * Transporte simulado: responde según la operación SOAP invocada.
 */
final class MockTransport implements SoapTransportInterface
{
    /** @var array<int, array{url: string, body: string, headers: array<string, string>, operation: string}> */
    public array $requests = [];

    /** @var array<string, array<int, string|callable|\Throwable>> */
    private array $responses = [];

    /**
     * Encola una respuesta para la operación (se consumen en orden; la última se repite).
     */
    public function on(string $operation, string|callable|\Throwable $response): self
    {
        $this->responses[$operation][] = $response;

        return $this;
    }

    public function send(string $url, string $body, array $headers): string
    {
        $operation = self::operation($body);
        $this->requests[] = ['url' => $url, 'body' => $body, 'headers' => $headers, 'operation' => $operation];

        if (!isset($this->responses[$operation]) || $this->responses[$operation] === []) {
            throw new \LogicException(sprintf('MockTransport: no hay respuesta configurada para %s', $operation));
        }
        $response = count($this->responses[$operation]) > 1
            ? array_shift($this->responses[$operation])
            : $this->responses[$operation][0];

        if ($response instanceof \Throwable) {
            throw $response;
        }
        if (is_callable($response)) {
            return $response($body, $url, $headers);
        }

        return $response;
    }

    /**
     * @return array<int, array{url: string, body: string, headers: array<string, string>, operation: string}>
     */
    public function requestsFor(string $operation): array
    {
        return array_values(array_filter($this->requests, static fn (array $r): bool => $r['operation'] === $operation));
    }

    public static function operation(string $body): string
    {
        $doc = new \DOMDocument();
        $doc->loadXML($body);
        $bodies = $doc->getElementsByTagNameNS('http://schemas.xmlsoap.org/soap/envelope/', 'Body');
        foreach ($bodies->item(0)->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                return $child->localName;
            }
        }

        return '';
    }

    public static function envelope(string $content): string
    {
        return '<?xml version="1.0" encoding="utf-8"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" '
            . 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema"><soap:Body>'
            . $content . '</soap:Body></soap:Envelope>';
    }

    public static function fault(string $code, string $message): string
    {
        return self::envelope('<soap:Fault><faultcode>' . $code . '</faultcode><faultstring>' . htmlspecialchars($message) . '</faultstring></soap:Fault>');
    }

    public static function wsfe(string $operation, string $result): string
    {
        return self::envelope(sprintf(
            '<%1$sResponse xmlns="http://ar.gov.afip.dif.FEV1/"><%1$sResult>%2$s</%1$sResult></%1$sResponse>',
            $operation,
            $result
        ));
    }

    public static function wsfex(string $operation, string $result): string
    {
        return self::envelope(sprintf(
            '<%1$sResponse xmlns="http://ar.gov.afip.dif.fexv1/"><%1$sResult>%2$s</%1$sResult></%1$sResponse>',
            $operation,
            $result
        ));
    }

    public static function loginCms(string $token = 'TOKEN', string $sign = 'SIGN', string $expiration = '+12 hours'): string
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('America/Argentina/Buenos_Aires'));
        $ta = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><loginTicketResponse version="1.0"><header>'
            . '<source>CN=wsaahomo, O=AFIP, C=AR, SERIALNUMBER=CUIT 33693450239</source><destination>SERIALNUMBER=CUIT 20111111112, CN=erp-test</destination>'
            . '<uniqueId>123456</uniqueId><generationTime>' . $now->modify('-10 minutes')->format('c') . '</generationTime>'
            . '<expirationTime>' . $now->modify($expiration)->format('c') . '</expirationTime></header>'
            . '<credentials><token>' . $token . '</token><sign>' . $sign . '</sign></credentials></loginTicketResponse>';

        return self::envelope('<ns1:loginCmsResponse xmlns:ns1="http://wsaa.view.sua.dvadac.desein.afip.gov"><ns1:loginCmsReturn>'
            . htmlspecialchars($ta, ENT_XML1) . '</ns1:loginCmsReturn></ns1:loginCmsResponse>');
    }

    /**
     * Respuesta de un servicio Java (WSMTXCA, WSCT, WSFECRED): {operacion}Response con hijos sin namespace.
     */
    public static function java(string $namespace, string $operation, string $content): string
    {
        return self::envelope(sprintf('<ns1:%1$sResponse xmlns:ns1="%2$s">%3$s</ns1:%1$sResponse>', $operation, $namespace, $content));
    }

    /**
     * Agrega una respuesta exitosa de WSAA.
     */
    public function withLogin(): self
    {
        return $this->on('loginCms', self::loginCms());
    }
}
