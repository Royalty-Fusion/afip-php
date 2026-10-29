<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Tests\Support;

use RoyaltyFusion\Afip\Auth\Certificate;

/**
 * Genera un certificado autofirmado con el formato de los que emite ARCA
 * (serialNumber = "CUIT nnnnnnnnnnn").
 */
final class TestCertificate
{
    /** @var array<string, array{0: string, 1: string}> */
    private static array $cache = [];

    /**
     * @return array{0: string, 1: string} [certificado PEM, clave PEM]
     */
    public static function pem(string $cuit = '20111111112', int $days = 365): array
    {
        $key = $cuit . '_' . $days;
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }
        $privateKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $dn = [
            'countryName' => 'AR',
            'organizationName' => 'Empresa de Prueba',
            'commonName' => 'erp-test',
            'serialNumber' => 'CUIT ' . $cuit,
        ];
        $csr = openssl_csr_new($dn, $privateKey, ['digest_alg' => 'sha256']);
        $x509 = openssl_csr_sign($csr, null, $privateKey, $days, ['digest_alg' => 'sha256']);
        openssl_x509_export($x509, $certPem);
        openssl_pkey_export($privateKey, $keyPem);

        return self::$cache[$key] = [$certPem, $keyPem];
    }

    public static function create(string $cuit = '20111111112'): Certificate
    {
        [$cert, $key] = self::pem($cuit);

        return Certificate::fromStrings($cert, $key);
    }
}
