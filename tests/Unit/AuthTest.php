<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RoyaltyFusion\Afip\Auth\AccessTicket;
use RoyaltyFusion\Afip\Auth\AuthManager;
use RoyaltyFusion\Afip\Auth\Certificate;
use RoyaltyFusion\Afip\Auth\FileTicketStorage;
use RoyaltyFusion\Afip\Auth\MemoryTicketStorage;
use RoyaltyFusion\Afip\Auth\Wsaa;
use RoyaltyFusion\Afip\Environment;
use RoyaltyFusion\Afip\Exception\CertificateException;
use RoyaltyFusion\Afip\Exception\WsaaException;
use RoyaltyFusion\Afip\Support\Date;
use RoyaltyFusion\Afip\Tests\Support\MockTransport;
use RoyaltyFusion\Afip\Tests\Support\TestCertificate;

final class AuthTest extends TestCase
{
    public function testCertificateReadsCuitAndValidity(): void
    {
        $cert = TestCertificate::create('20111111112');
        self::assertSame('20111111112', $cert->getCuit());
        self::assertFalse($cert->isExpired());
        self::assertNotSame('', $cert->getFingerprint());
    }

    public function testCertificateRejectsMismatchedKey(): void
    {
        [$cert] = TestCertificate::pem('20111111112');
        [, $otherKey] = TestCertificate::pem('20267565393');
        $this->expectException(CertificateException::class);
        Certificate::fromStrings($cert, $otherKey);
    }

    public function testTraHasRequiredFields(): void
    {
        $wsaa = new Wsaa(TestCertificate::create(), Environment::HOMOLOGACION, new MockTransport());
        $tra = $wsaa->buildTra('wsfe', new \DateTimeImmutable('2026-10-29 10:00:00', Date::timezone()));
        $xml = simplexml_load_string($tra);
        self::assertSame('wsfe', (string) $xml->service);
        self::assertSame('2026-10-29T09:50:00-03:00', (string) $xml->header->generationTime);
        self::assertSame('2026-10-29T10:10:00-03:00', (string) $xml->header->expirationTime);
        self::assertSame('1.0', (string) $xml['version']);
    }

    public function testCmsSignatureContainsTraAndVerifies(): void
    {
        $cert = TestCertificate::create();
        $wsaa = new Wsaa($cert, Environment::HOMOLOGACION, new MockTransport());
        $tra = $wsaa->buildTra('wsfe');
        $cms = $wsaa->sign($tra);

        $der = base64_decode($cms, true);
        self::assertNotFalse($der);
        $dir = sys_get_temp_dir();
        $in = tempnam($dir, 'cms');
        $content = tempnam($dir, 'content');
        file_put_contents($in, $der);
        try {
            $ok = openssl_cms_verify($in, OPENSSL_CMS_NOVERIFY | OPENSSL_CMS_BINARY, null, [], null, $content, null, null, OPENSSL_ENCODING_DER);
            self::assertTrue($ok, (string) openssl_error_string());
            self::assertSame($tra, file_get_contents($content));
        } finally {
            @unlink($in);
            @unlink($content);
        }
    }

    public function testLoginParsesTicket(): void
    {
        $transport = (new MockTransport())->on('loginCms', MockTransport::loginCms('TK', 'SG'));
        $wsaa = new Wsaa(TestCertificate::create(), Environment::HOMOLOGACION, $transport);
        $ticket = $wsaa->login('wsfe');

        self::assertSame('TK', $ticket->getToken());
        self::assertSame('SG', $ticket->getSign());
        self::assertTrue($ticket->isValid());
        self::assertSame('https://wsaahomo.afip.gov.ar/ws/services/LoginCms', $transport->requests[0]['url']);
        self::assertStringContainsString('<loginCms xmlns="http://wsaa.view.sua.dvadac.desein.afip.gov"><in0>', $transport->requests[0]['body']);
    }

    public function testAuthManagerReusesTicket(): void
    {
        $transport = (new MockTransport())->on('loginCms', MockTransport::loginCms());
        $manager = new AuthManager(new Wsaa(TestCertificate::create(), Environment::HOMOLOGACION, $transport), new MemoryTicketStorage());

        $manager->getTicket('wsfe');
        $manager->getTicket('wsfe');
        self::assertCount(1, $transport->requests);

        $manager->getTicket('wsfex');
        self::assertCount(2, $transport->requests);
    }

    public function testFileStorageSurvivesNewManager(): void
    {
        $dir = sys_get_temp_dir() . '/afip-test-' . bin2hex(random_bytes(4));
        $transport = (new MockTransport())->on('loginCms', MockTransport::loginCms());
        $cert = TestCertificate::create();

        (new AuthManager(new Wsaa($cert, Environment::HOMOLOGACION, $transport), new FileTicketStorage($dir)))->getTicket('wsfe');
        $ticket = (new AuthManager(new Wsaa($cert, Environment::HOMOLOGACION, $transport), new FileTicketStorage($dir)))->getTicket('wsfe');

        self::assertSame('TOKEN', $ticket->getToken());
        self::assertCount(1, $transport->requests);
        array_map('unlink', glob($dir . '/*') ?: []);
        @rmdir($dir);
    }

    public function testExpiredTicketIsRenewed(): void
    {
        $storage = new MemoryTicketStorage();
        $transport = (new MockTransport())->on('loginCms', MockTransport::loginCms('NEW'));
        $cert = TestCertificate::create();
        $manager = new AuthManager(new Wsaa($cert, Environment::HOMOLOGACION, $transport), $storage);
        $key = sprintf('homologacion_wsfe_%s', $cert->getFingerprint());
        $storage->set($key, new AccessTicket('OLD', 'S', new \DateTimeImmutable('-13 hours'), new \DateTimeImmutable('-1 hour'), 'wsfe'));

        self::assertSame('NEW', $manager->getTicket('wsfe')->getToken());
    }

    public function testAlreadyAuthenticatedWithoutCacheGivesClearError(): void
    {
        $transport = (new MockTransport())->on('loginCms', MockTransport::fault('ns1:coe.alreadyAuthenticated', 'El CEE ya posee un TA valido para el acceso al WSN solicitado'));
        $manager = new AuthManager(new Wsaa(TestCertificate::create(), Environment::HOMOLOGACION, $transport), new MemoryTicketStorage(), Environment::HOMOLOGACION, 0);

        try {
            $manager->getTicket('wsfe');
            self::fail('Se esperaba WsaaException');
        } catch (WsaaException $e) {
            self::assertTrue($e->isAlreadyAuthenticated());
            self::assertStringContainsString('almacenamiento persistente', $e->getMessage());
        }
    }
}
