<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RoyaltyFusion\Afip\Auth\AccessTicket;
use RoyaltyFusion\Afip\Auth\AuthManager;
use RoyaltyFusion\Afip\Auth\PdoTicketStorage;
use RoyaltyFusion\Afip\Auth\Wsaa;
use RoyaltyFusion\Afip\Catalog\CondicionIva;
use RoyaltyFusion\Afip\Catalog\Moneda;
use RoyaltyFusion\Afip\Environment;
use RoyaltyFusion\Afip\Exception\TransportException;
use RoyaltyFusion\Afip\Exception\UncertainAuthorizationException;
use RoyaltyFusion\Afip\Exception\ValidationException;
use RoyaltyFusion\Afip\Exception\VoucherMismatchException;
use RoyaltyFusion\Afip\Lock\NumberingLockInterface;
use RoyaltyFusion\Afip\Lock\PdoNumberingLock;
use RoyaltyFusion\Afip\Report\RepresentationData;
use RoyaltyFusion\Afip\See;
use RoyaltyFusion\Afip\SeeConfig;
use RoyaltyFusion\Afip\Support\Fingerprint;
use RoyaltyFusion\Afip\Support\Redactor;
use RoyaltyFusion\Afip\Tests\Support\Factory;
use RoyaltyFusion\Afip\Tests\Support\MockTransport;
use RoyaltyFusion\Afip\Tests\Support\TestCertificate;

/**
 * Requisitos de integración con el ERP (multi-tenant, BD, reconciliación, logs, reloj).
 */
final class ErpIntegrationTest extends TestCase
{
    private static function pdo(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    private static function voucher(int $nro, string $total, string $fecha, string $docNro = '30500010912'): string
    {
        return MockTransport::wsfe('FECompConsultar', sprintf(
            '<ResultGet><Concepto>1</Concepto><DocTipo>80</DocTipo><DocNro>%s</DocNro><CbteDesde>%d</CbteDesde><CbteHasta>%2$d</CbteHasta>'
            . '<CbteFch>%s</CbteFch><ImpTotal>%s</ImpTotal><MonId>PES</MonId><MonCotiz>1</MonCotiz><Resultado>A</Resultado>'
            . '<CodAutorizacion>76000000000099</CodAutorizacion><EmisionTipo>CAE</EmisionTipo><FchVto>20261108</FchVto><PtoVta>1</PtoVta><CbteTipo>1</CbteTipo></ResultGet>',
            $docNro,
            $nro,
            $fecha,
            $total
        ));
    }

    public function testPdoTicketStorageSurvivesAndLocks(): void
    {
        $pdo = self::pdo();
        $storage = new PdoTicketStorage($pdo);
        $storage->createTable();

        self::assertNull($storage->get('k'));
        $ticket = new AccessTicket('T', 'S', new \DateTimeImmutable('-1 hour'), new \DateTimeImmutable('+11 hours'), 'wsfe');
        $storage->set('k', $ticket);
        $storage->set('k', $ticket); // upsert
        self::assertSame('T', $storage->get('k')?->getToken());
        self::assertSame('wsfe', $storage->get('k')?->getService());
        self::assertSame(42, $storage->withLock('k', static fn (): int => 42));
        $storage->delete('k');
        self::assertNull($storage->get('k'));

        // Un nuevo proceso (otro AuthManager) reutiliza el ticket guardado en la BD.
        $transport = (new MockTransport())->withLogin();
        $cert = TestCertificate::create();
        (new AuthManager(new Wsaa($cert, Environment::HOMOLOGACION, $transport), $storage))->getTicket('wsfe');
        (new AuthManager(new Wsaa($cert, Environment::HOMOLOGACION, $transport), $storage))->getTicket('wsfe');
        self::assertCount(1, $transport->requests);
    }

    public function testPdoNumberingLockRunsCallback(): void
    {
        self::assertSame('ok', (new PdoNumberingLock(self::pdo()))->run('x', static fn (): string => 'ok'));
    }

    public function testFromConfigAndLockKeyIncludesEnvironment(): void
    {
        [$cert, $key] = TestCertificate::pem();
        $lock = new class () implements NumberingLockInterface {
            /** @var string[] */
            public array $keys = [];

            public function run(string $key, callable $callback): mixed
            {
                $this->keys[] = $key;

                return $callback();
            }
        };
        $transport = (new MockTransport())->withLogin()
            ->on('FEParamGetPtosVenta', MockTransport::wsfe('FEParamGetPtosVenta', '<ResultGet/>'))
            ->on('FECAESolicitar', WsfeServiceTest::caeResponse(42));
        $storage = new PdoTicketStorage(self::pdo());
        $storage->createTable();

        $see = See::fromConfig([
            'cert_pem' => $cert,
            'key_pem' => $key,
            'cuit' => '20-11111111-2',
            'environment' => 'homologacion',
            'ticket_storage' => $storage,
            'numbering_lock' => $lock,
            'transport' => $transport,
        ])->setCheckLastVoucherDate(false);

        self::assertSame('20111111112', $see->getCuit());
        self::assertTrue($see->send(Factory::facturaA()->setNumero(42))->isSuccess());
        self::assertSame(['homologacion_wsfe_20111111112_1_1'], $lock->keys);

        $this->expectException(\InvalidArgumentException::class);
        SeeConfig::fromArray(['cuit' => '20111111112']);
    }

    public function testReconcileFromAnotherProcess(): void
    {
        $invoice = Factory::facturaA()->setNumero(42);
        $fecha = $invoice->getFechaEmision()->format('Ymd');
        $transport = (new MockTransport())->withLogin()
            ->on('FECompConsultar', self::voucher(42, '2585.75', $fecha))
            ->on('FECompConsultar', MockTransport::wsfe('FECompConsultar', '<Errors><Err><Code>602</Code><Msg>Sin Resultados</Msg></Err></Errors>'))
            ->on('FECompConsultar', self::voucher(42, '1.00', $fecha));
        $see = (new See())->setCertificate(TestCertificate::create())->setTransport($transport);

        $result = $see->reconcile($invoice);
        self::assertNotNull($result);
        self::assertTrue($result->isRecovered());
        self::assertSame('76000000000099', $invoice->getCae());

        self::assertNull($see->reconcile(Factory::facturaA()->setNumero(43)));

        $this->expectException(VoucherMismatchException::class);
        $see->reconcile(Factory::facturaA()->setNumero(42));
    }

    public function testUncertainExceptionIsSerializable(): void
    {
        $transport = (new MockTransport())->withLogin()
            ->on('FEParamGetPtosVenta', MockTransport::wsfe('FEParamGetPtosVenta', '<ResultGet/>'))
            ->on('FECAESolicitar', new TransportException('timeout'))
            ->on('FECompConsultar', new TransportException('sin red'));
        $see = (new See())->setCertificate(TestCertificate::create())->setTransport($transport)->setRecoveryPolicy(1, 0);
        $invoice = Factory::facturaA()->setNumero(42);

        try {
            $see->send($invoice);
            self::fail('Se esperaba UncertainAuthorizationException');
        } catch (UncertainAuthorizationException $e) {
            $data = json_decode((string) json_encode($e), true);
            self::assertSame('wsfe', $data['servicio']);
            self::assertSame('homologacion', $data['ambiente']);
            self::assertSame('20111111112', $data['cuit']);
            self::assertSame(1, $data['tipoComprobante']);
            self::assertSame(1, $data['puntoVenta']);
            self::assertSame([42], $data['numeros']);
            self::assertSame(Fingerprint::of($invoice, '20111111112'), $data['requestHash']);
            self::assertSame(64, strlen($data['requestHash']));
        }
    }

    public function testPsrLoggerWithRedaction(): void
    {
        $logger = new class () {
            /** @var array<int, array{0: string, 1: string, 2: array<string, mixed>}> */
            public array $records = [];

            /**
             * @param array<string, mixed> $context
             */
            public function log(mixed $level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [(string) $level, (string) $message, $context];
            }
        };
        $transport = (new MockTransport())->withLogin()
            ->on('FECompUltimoAutorizado', MockTransport::wsfe('FECompUltimoAutorizado', '<CbteNro>7</CbteNro>'));
        $see = (new See())->setCertificate(TestCertificate::create())->setTransport($transport)
            ->setTicketStorage(new \RoyaltyFusion\Afip\Auth\MemoryTicketStorage())->setLogger($logger);
        $see->getWsfe()->getLastVoucher(1, 1);

        $all = json_encode($logger->records);
        self::assertStringNotContainsString('<Token>TOKEN</Token>', (string) $all);
        self::assertStringNotContainsString('TOKEN', (string) $all);
        self::assertStringNotContainsString('SIGN', (string) $all);
        self::assertSame('debug', $logger->records[0][0]);
        self::assertSame('loginCms', $logger->records[0][2]['operation']);
        self::assertStringContainsString('<in0>***</in0>', $logger->records[0][2]['request']);
        self::assertStringContainsString('<Token>***</Token>', $logger->records[1][2]['request']);

        self::assertSame("-----BEGIN PRIVATE KEY-----***-----END PRIVATE KEY-----", Redactor::pem("-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----"));
    }

    public function testClockDrivesValidationDate(): void
    {
        $see = (new See())->setCertificate(TestCertificate::create())
            ->setClock(static fn (): \DateTimeImmutable => new \DateTimeImmutable('2030-01-15 10:00:00'));
        $invoice = Factory::facturaA()->setFechaEmision(new \DateTimeImmutable('2030-01-15'));
        self::assertTrue($see->validate($invoice)->isValid());
        self::assertTrue($see->validate(Factory::facturaA())->hasCode('FECHA_FUERA_DE_RANGO'));

        $psrClock = new class () {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2030-01-15 10:00:00');
            }
        };
        self::assertTrue($see->setClock($psrClock)->validate($invoice)->isValid());
    }

    public function testGetCotizacion(): void
    {
        $transport = (new MockTransport())->withLogin()
            ->on('FEParamGetCotizacion', MockTransport::wsfe('FEParamGetCotizacion', '<ResultGet><MonId>DOL</MonId><MonCotiz>1050.1234567</MonCotiz><FchCotiz>20261028</FchCotiz></ResultGet>'));
        $see = (new See())->setCertificate(TestCertificate::create())->setTransport($transport);
        self::assertSame(1050.123457, $see->getCotizacion(Moneda::DOLAR, new \DateTimeImmutable('2026-10-28')));
        self::assertSame(1.0, $see->getCotizacion('PES'));
        self::assertStringContainsString('<FchCotiz>20261028</FchCotiz>', $transport->requestsFor('FEParamGetCotizacion')[0]['body']);
    }

    public function testRepresentationData(): void
    {
        $see = (new See())->setCertificate(TestCertificate::create());
        $a = Factory::facturaA()->setNumero(42)->setCae('76123456789012')->setCaeVencimiento(new \DateTimeImmutable('2026-11-08'));
        $a->getClient()->setCondicionIva(CondicionIva::MONOTRIBUTO);
        $data = $see->getRepresentationData($a);

        self::assertSame('A', $data['letra']);
        self::assertSame('01', $data['codigo']);
        self::assertSame('00001', $data['puntoVenta']);
        self::assertSame('00000042', $data['numero']);
        self::assertSame('76123456789012', $data['cae']);
        self::assertSame('2026-11-08', $data['caeVencimiento']);
        self::assertStringStartsWith('https://www.afip.gob.ar/fe/qr/?p=', $data['qrUrl']);
        self::assertSame(42, $data['qrData']['nroCmp']);
        self::assertContains(RepresentationData::LEYENDA_RG5003, $data['leyendas']);
        self::assertNull($data['transparenciaFiscal']);
        self::assertCount(2, $data['importes']['ivas']);

        $b = Factory::facturaB()->setNumero(7)->setCae('76123456789013');
        $data = $see->getRepresentationData($b);
        self::assertSame('06', $data['codigo']);
        self::assertSame(21.0, $data['transparenciaFiscal']['ivaContenido']);
        self::assertContains(RepresentationData::LEYENDA_CONSUMIDOR_FINAL, $data['leyendas']);
    }

    public function testValidationErrorsAreNeverSent(): void
    {
        $transport = (new MockTransport())->withLogin();
        $see = See::fromConfig(['certificate' => TestCertificate::create(), 'transport' => $transport]);
        $this->expectException(ValidationException::class);
        $see->send(Factory::facturaA()->setImpTotal(1));
    }
}
