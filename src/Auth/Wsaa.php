<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Auth;

use RoyaltyFusion\Afip\Endpoints;
use RoyaltyFusion\Afip\Environment;
use RoyaltyFusion\Afip\Exception\CertificateException;
use RoyaltyFusion\Afip\Exception\SoapFaultException;
use RoyaltyFusion\Afip\Exception\WsaaException;
use RoyaltyFusion\Afip\Soap\CurlTransport;
use RoyaltyFusion\Afip\Soap\SoapClient;
use RoyaltyFusion\Afip\Soap\SoapTransportInterface;
use RoyaltyFusion\Afip\Support\Date;

/**
 * Web Service de Autenticación y Autorización (WSAA).
 *
 * 1. Genera el TRA (loginTicketRequest) para el servicio de negocio.
 * 2. Lo firma en formato CMS (PKCS#7 SignedData con el contenido incluido) con el
 *    certificado y la clave privada.
 * 3. Lo envía a loginCms y obtiene el Ticket de Acceso (token + sign).
 */
final class Wsaa
{
    public const NAMESPACE = 'http://wsaa.view.sua.dvadac.desein.afip.gov';

    private SoapClient $client;

    public function __construct(
        private readonly Certificate $certificate,
        Environment $environment = Environment::HOMOLOGACION,
        ?SoapTransportInterface $transport = null,
        ?string $endpoint = null
    ) {
        $this->client = new SoapClient(
            $endpoint ?? Endpoints::get(Endpoints::WSAA, $environment),
            self::NAMESPACE,
            $transport ?? new CurlTransport(),
            true,
            null
        );
    }

    /**
     * Solicita un nuevo Ticket de Acceso para el servicio (ej. "wsfe", "wsfex",
     * "ws_sr_constancia_inscripcion").
     */
    public function login(string $service): AccessTicket
    {
        if ($this->certificate->isExpired()) {
            throw new CertificateException(sprintf(
                'El certificado venció el %s. Genere uno nuevo en ARCA.',
                $this->certificate->getValidTo()->format('d/m/Y H:i')
            ));
        }

        $cms = $this->sign($this->buildTra($service));

        try {
            $response = $this->client->call('loginCms', ['in0' => $cms]);
        } catch (SoapFaultException $e) {
            throw new WsaaException(
                sprintf('WSAA rechazó el login para "%s": %s', $service, $e->getFaultString()),
                $e->getShortFaultCode(),
                $e
            );
        }

        $xml = $response['loginCmsReturn'] ?? null;
        if (!is_string($xml) || $xml === '') {
            throw new WsaaException('Respuesta de WSAA sin loginCmsReturn.');
        }

        try {
            return AccessTicket::fromXml($xml, $service);
        } catch (\RuntimeException $e) {
            throw new WsaaException('No se pudo interpretar el Ticket de Acceso: ' . $e->getMessage(), null, $e);
        }
    }

    /**
     * Genera el Ticket de Requerimiento de Acceso (TRA).
     */
    public function buildTra(string $service, ?\DateTimeImmutable $now = null): string
    {
        $now ??= Date::now();
        // Se adelanta la hora de generación para tolerar diferencias de reloj con ARCA.
        $generation = $now->modify('-10 minutes');
        $expiration = $now->modify('+10 minutes');

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $root = $doc->createElement('loginTicketRequest');
        $root->setAttribute('version', '1.0');
        $doc->appendChild($root);
        $header = $doc->createElement('header');
        $root->appendChild($header);
        $header->appendChild($doc->createElement('uniqueId', (string) $now->getTimestamp()));
        $header->appendChild($doc->createElement('generationTime', $generation->format('c')));
        $header->appendChild($doc->createElement('expirationTime', $expiration->format('c')));
        $root->appendChild($doc->createElement('service', $service));

        return (string) $doc->saveXML();
    }

    /**
     * Firma el TRA en CMS y devuelve el resultado DER codificado en base64.
     */
    public function sign(string $tra): string
    {
        $dir = sys_get_temp_dir();
        $in = tempnam($dir, 'tra');
        $out = tempnam($dir, 'cms');
        if ($in === false || $out === false) {
            throw new WsaaException('No se pudieron crear archivos temporales para firmar el TRA.');
        }

        try {
            file_put_contents($in, $tra);
            $ok = openssl_pkcs7_sign(
                $in,
                $out,
                $this->certificate->getCertificate(),
                $this->certificate->getPrivateKey(),
                [],
                PKCS7_BINARY
            );
            if (!$ok) {
                $errors = [];
                while (($m = openssl_error_string()) !== false) {
                    $errors[] = $m;
                }
                throw new WsaaException('No se pudo firmar el TRA: ' . implode('; ', $errors));
            }

            return self::extractSmimeBody((string) file_get_contents($out));
        } finally {
            @unlink($in);
            @unlink($out);
        }
    }

    /**
     * openssl_pkcs7_sign genera un mensaje S/MIME: se descartan los encabezados y se
     * devuelve solo el bloque base64.
     */
    private static function extractSmimeBody(string $smime): string
    {
        $smime = str_replace("\r\n", "\n", $smime);
        $pos = strpos($smime, "\n\n");
        $body = $pos === false ? $smime : substr($smime, $pos + 2);

        return preg_replace('/\s+/', '', $body) ?? '';
    }

    public function getClient(): SoapClient
    {
        return $this->client;
    }

    public function getCertificate(): Certificate
    {
        return $this->certificate;
    }
}
