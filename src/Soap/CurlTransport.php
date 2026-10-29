<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Soap;

use RoyaltyFusion\Afip\Exception\TransportException;

/**
 * Transporte basado en cURL.
 *
 * Los servidores de ARCA utilizan claves Diffie-Hellman cortas que OpenSSL 3 rechaza
 * con el nivel de seguridad por defecto ("dh key too small"). Por eso se usa
 * "DEFAULT@SECLEVEL=1" como lista de cifrados, sin desactivar la verificación del
 * certificado del servidor.
 */
final class CurlTransport implements SoapTransportInterface
{
    /**
     * @param array<int, mixed> $curlOptions Opciones extra de cURL (ej. proxy)
     */
    public function __construct(
        private readonly int $timeout = 60,
        private readonly int $connectTimeout = 15,
        private readonly ?string $caBundle = null,
        private readonly ?string $cipherList = 'DEFAULT@SECLEVEL=1',
        private readonly array $curlOptions = []
    ) {
    }

    public function send(string $url, string $body, array $headers): string
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new TransportException(sprintf('No se pudo inicializar cURL para %s', $url));
        }

        $httpHeaders = [];
        foreach ($headers as $name => $value) {
            $httpHeaders[] = $name . ': ' . $value;
        }
        $httpHeaders[] = 'Content-Length: ' . strlen($body);

        $options = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $httpHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_ENCODING => '',
        ];
        if ($this->cipherList !== null && self::usesOpenSsl()) {
            $options[CURLOPT_SSL_CIPHER_LIST] = $this->cipherList;
        }
        if ($this->caBundle !== null) {
            $options[CURLOPT_CAINFO] = $this->caBundle;
        }
        foreach ($this->curlOptions as $option => $value) {
            $options[$option] = $value;
        }
        curl_setopt_array($handle, $options);

        $response = curl_exec($handle);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($response === false || $errno !== 0) {
            throw new TransportException(sprintf('Error de comunicación con %s: (%d) %s', $url, $errno, $error));
        }
        $response = (string) $response;

        if ($status !== 200 && !($status === 500 && str_contains($response, 'Envelope'))) {
            throw new TransportException(
                sprintf('ARCA respondió HTTP %d en %s', $status, $url),
                $status,
                $response
            );
        }

        return $response;
    }

    /**
     * La sintaxis "@SECLEVEL" solo la entiende OpenSSL (no NSS / Secure Transport / Schannel).
     */
    private static function usesOpenSsl(): bool
    {
        $version = curl_version();

        return is_array($version) && stripos((string) ($version['ssl_version'] ?? ''), 'openssl') !== false;
    }
}
