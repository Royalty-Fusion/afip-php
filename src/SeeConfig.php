<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip;

use RoyaltyFusion\Afip\Auth\Certificate;
use RoyaltyFusion\Afip\Auth\TicketStorageInterface;
use RoyaltyFusion\Afip\Lock\NumberingLockInterface;
use RoyaltyFusion\Afip\Soap\SoapTransportInterface;
use RoyaltyFusion\Afip\Validator\ValidatorOptions;

/**
 * Configuración de una instancia de {@see See} (un emisor: CUIT, certificado y ambiente).
 * Pensada para ERP multi-empresa: cada establecimiento arma su propio See desde la base de
 * datos, sin estado global.
 */
final class SeeConfig
{
    /**
     * @param array<string, string> $endpoints Endpoints::* => URL
     * @param callable|object|null  $logger    callable o logger PSR-3
     * @param callable|object|null  $clock     callable o reloj PSR-20
     */
    public function __construct(
        public readonly Certificate $certificate,
        public readonly Environment $environment = Environment::HOMOLOGACION,
        public readonly ?string $cuit = null,
        public readonly ?TicketStorageInterface $ticketStorage = null,
        public readonly ?NumberingLockInterface $numberingLock = null,
        public readonly ?SoapTransportInterface $transport = null,
        public readonly ?ValidatorOptions $validatorOptions = null,
        public readonly array $endpoints = [],
        public readonly mixed $logger = null,
        public readonly mixed $clock = null,
        public readonly ?string $cacheDirectory = null,
    ) {
    }

    /**
     * Crea la configuración desde un array (por ejemplo, una fila de la base de datos).
     *
     * Claves admitidas:
     *  - certificado: 'certificate' (Certificate), o 'cert_pem' + 'key_pem' (+ 'passphrase'),
     *    o 'cert_file' + 'key_file' (+ 'passphrase'), o 'pkcs12' / 'pkcs12_file' + 'pkcs12_password'
     *  - 'cuit', 'environment' ('homologacion' | 'produccion' | Environment)
     *  - 'ticket_storage', 'numbering_lock', 'transport', 'validator_options',
     *    'endpoints', 'logger', 'clock', 'cache_dir'
     *
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $environment = $config['environment'] ?? Environment::HOMOLOGACION;

        return new self(
            certificate: self::certificate($config),
            environment: $environment instanceof Environment ? $environment : self::environment((string) $environment),
            cuit: isset($config['cuit']) ? (string) $config['cuit'] : null,
            ticketStorage: $config['ticket_storage'] ?? null,
            numberingLock: $config['numbering_lock'] ?? null,
            transport: $config['transport'] ?? null,
            validatorOptions: $config['validator_options'] ?? null,
            endpoints: (array) ($config['endpoints'] ?? []),
            logger: $config['logger'] ?? null,
            clock: $config['clock'] ?? null,
            cacheDirectory: isset($config['cache_dir']) ? (string) $config['cache_dir'] : null,
        );
    }

    /**
     * @param array<string, mixed> $c
     */
    private static function certificate(array $c): Certificate
    {
        $passphrase = isset($c['passphrase']) ? (string) $c['passphrase'] : null;

        return match (true) {
            ($c['certificate'] ?? null) instanceof Certificate => $c['certificate'],
            isset($c['cert_pem'], $c['key_pem']) => Certificate::fromStrings((string) $c['cert_pem'], (string) $c['key_pem'], $passphrase),
            isset($c['cert_file'], $c['key_file']) => Certificate::fromFiles((string) $c['cert_file'], (string) $c['key_file'], $passphrase),
            isset($c['pkcs12']) => Certificate::fromPkcs12((string) $c['pkcs12'], (string) ($c['pkcs12_password'] ?? '')),
            isset($c['pkcs12_file']) => Certificate::fromPkcs12File((string) $c['pkcs12_file'], (string) ($c['pkcs12_password'] ?? '')),
            default => throw new \InvalidArgumentException('Falta el certificado: indique certificate, cert_pem + key_pem, cert_file + key_file o pkcs12.'),
        };
    }

    private static function environment(string $value): Environment
    {
        return match (strtolower(trim($value))) {
            'produccion', 'prod', 'production' => Environment::PRODUCCION,
            'homologacion', 'homo', 'testing', 'test', 'dev' => Environment::HOMOLOGACION,
            default => throw new \InvalidArgumentException(sprintf('Ambiente "%s" inválido: use "homologacion" o "produccion".', $value)),
        };
    }
}
