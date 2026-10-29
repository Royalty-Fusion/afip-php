<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Auth;

use RoyaltyFusion\Afip\Exception\CertificateException;
use RoyaltyFusion\Afip\Support\Date;

/**
 * Certificado X.509 emitido por ARCA y su clave privada.
 *
 * Se puede cargar desde archivos PEM (.crt + .key), desde strings PEM o desde un
 * contenedor PKCS#12 (.p12 / .pfx).
 */
final class Certificate
{
    private \OpenSSLCertificate $certificate;
    private \OpenSSLAsymmetricKey $privateKey;
    /** @var array<string, mixed> */
    private array $info;

    private function __construct(string $certificatePem, string $privateKeyPem, ?string $passphrase)
    {
        $certificate = @openssl_x509_read($certificatePem);
        if ($certificate === false) {
            throw new CertificateException('El certificado no es un X.509 PEM válido: ' . self::opensslErrors());
        }
        $privateKey = @openssl_pkey_get_private($privateKeyPem, $passphrase ?? '');
        if ($privateKey === false) {
            throw new CertificateException('No se pudo leer la clave privada (¿contraseña incorrecta?): ' . self::opensslErrors());
        }
        if (!openssl_x509_check_private_key($certificate, $privateKey)) {
            throw new CertificateException('La clave privada no corresponde al certificado.');
        }
        $info = openssl_x509_parse($certificate);
        if ($info === false) {
            throw new CertificateException('No se pudo interpretar el certificado.');
        }

        $this->certificate = $certificate;
        $this->privateKey = $privateKey;
        $this->info = $info;
    }

    public static function fromFiles(string $certificatePath, string $privateKeyPath, ?string $passphrase = null): self
    {
        return new self(self::read($certificatePath), self::read($privateKeyPath), $passphrase);
    }

    public static function fromStrings(string $certificatePem, string $privateKeyPem, ?string $passphrase = null): self
    {
        return new self($certificatePem, $privateKeyPem, $passphrase);
    }

    public static function fromPkcs12(string $pkcs12, string $password): self
    {
        $certs = [];
        if (!openssl_pkcs12_read($pkcs12, $certs, $password)) {
            throw new CertificateException('No se pudo leer el PKCS#12 (¿contraseña incorrecta?): ' . self::opensslErrors());
        }

        return new self((string) $certs['cert'], (string) $certs['pkey'], null);
    }

    public static function fromPkcs12File(string $path, string $password): self
    {
        return self::fromPkcs12(self::read($path), $password);
    }

    public function getCertificate(): \OpenSSLCertificate
    {
        return $this->certificate;
    }

    public function getPrivateKey(): \OpenSSLAsymmetricKey
    {
        return $this->privateKey;
    }

    public function getCertificatePem(): string
    {
        $out = '';
        openssl_x509_export($this->certificate, $out);

        return $out;
    }

    /**
     * CUIT del titular, tomado del campo serialNumber del sujeto ("CUIT 20123456789").
     */
    public function getCuit(): ?string
    {
        $serial = $this->info['subject']['serialNumber'] ?? null;
        if (is_array($serial)) {
            $serial = reset($serial);
        }
        if (is_string($serial) && preg_match('/(\d{11})/', $serial, $m)) {
            return $m[1];
        }

        return null;
    }

    public function getSubjectName(): string
    {
        $cn = $this->info['subject']['CN'] ?? '';

        return is_array($cn) ? (string) reset($cn) : (string) $cn;
    }

    public function getValidFrom(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . (int) $this->info['validFrom_time_t']))->setTimezone(Date::timezone());
    }

    public function getValidTo(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . (int) $this->info['validTo_time_t']))->setTimezone(Date::timezone());
    }

    public function isExpired(?\DateTimeInterface $at = null): bool
    {
        $at ??= Date::now();

        return $at > $this->getValidTo() || $at < $this->getValidFrom();
    }

    /**
     * Huella SHA-1 del certificado (sirve como clave de caché del TA).
     */
    public function getFingerprint(): string
    {
        return (string) openssl_x509_fingerprint($this->certificate, 'sha1');
    }

    private static function read(string $path): string
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new CertificateException(sprintf('No se puede leer el archivo "%s".', $path));
        }

        return (string) file_get_contents($path);
    }

    private static function opensslErrors(): string
    {
        $messages = [];
        while (($message = openssl_error_string()) !== false) {
            $messages[] = $message;
        }

        return implode('; ', $messages);
    }
}
