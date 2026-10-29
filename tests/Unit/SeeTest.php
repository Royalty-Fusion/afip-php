<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RoyaltyFusion\Afip\Environment;
use RoyaltyFusion\Afip\Exception\AfipException;
use RoyaltyFusion\Afip\Exception\TransportException;
use RoyaltyFusion\Afip\Exception\ValidationException;
use RoyaltyFusion\Afip\See;
use RoyaltyFusion\Afip\Tests\Support\Factory;
use RoyaltyFusion\Afip\Tests\Support\MockTransport;
use RoyaltyFusion\Afip\Tests\Support\TestCertificate;

final class SeeTest extends TestCase
{
    private MockTransport $transport;
    private See $see;

    protected function setUp(): void
    {
        $this->transport = (new MockTransport())->withLogin()
            ->on('FEParamGetPtosVenta', MockTransport::wsfe('FEParamGetPtosVenta', '<ResultGet><PtoVenta><Nro>1</Nro><EmisionTipo>CAE</EmisionTipo><Bloqueado>N</Bloqueado><FchBaja>NULL</FchBaja></PtoVenta></ResultGet>'));
        $this->see = (new See(Environment::HOMOLOGACION))
            ->setCertificate(TestCertificate::create())
            ->setTransport($this->transport)
            ->setCheckLastVoucherDate(false);
    }

    private static function last(int $nro): string
    {
        return MockTransport::wsfe('FECompUltimoAutorizado', sprintf('<PtoVta>1</PtoVta><CbteTipo>1</CbteTipo><CbteNro>%d</CbteNro>', $nro));
    }

    private static function voucher(int $nro, float $total, string $fecha, string $docNro = '30500010912'): string
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

    public function testCuitIsTakenFromCertificate(): void
    {
        self::assertSame('20111111112', $this->see->getCuit());
    }

    public function testSendAssignsNextNumberAndAuthorizes(): void
    {
        $this->transport->on('FECompUltimoAutorizado', self::last(41))->on('FECAESolicitar', WsfeServiceTest::caeResponse(42));
        $invoice = Factory::facturaA();
        $result = $this->see->send($invoice);

        self::assertTrue($result->isSuccess());
        self::assertSame(42, $invoice->getNumero());
        self::assertSame('76123456789012', $invoice->getCae());
        self::assertStringContainsString('<CbteDesde>42</CbteDesde>', $this->transport->requestsFor('FECAESolicitar')[0]['body']);
    }

    public function testInvalidInvoiceIsNeverSent(): void
    {
        $invoice = Factory::facturaA()->setImpTotal(1);
        try {
            $this->see->send($invoice);
            self::fail('Se esperaba ValidationException');
        } catch (ValidationException $e) {
            self::assertTrue($e->getResult()->hasCode('TOTAL_NO_CUADRA'));
        }
        self::assertSame([], $this->transport->requests);
    }

    public function testRecoversAfterTimeoutWhenArcaAuthorized(): void
    {
        $invoice = Factory::facturaA()->setNumero(42);
        $this->transport
            ->on('FECAESolicitar', new TransportException('timeout'))
            ->on('FECompConsultar', self::voucher(42, $invoice->getImpTotal(), $invoice->getFechaEmision()->format('Ymd')));

        $result = $this->see->send($invoice);

        self::assertTrue($result->isSuccess());
        self::assertTrue($result->isRecovered());
        self::assertSame('76000000000099', $invoice->getCae());
    }

    public function testTimeoutWithoutVoucherIsRethrown(): void
    {
        $invoice = Factory::facturaA()->setNumero(42);
        $this->transport
            ->on('FECAESolicitar', new TransportException('timeout'))
            ->on('FECompConsultar', MockTransport::wsfe('FECompConsultar', '<Errors><Err><Code>602</Code><Msg>Sin Resultados</Msg></Err></Errors>'));

        $this->expectException(TransportException::class);
        $this->see->send($invoice);
    }

    public function testTimeoutWithDifferentVoucherIsDetected(): void
    {
        $invoice = Factory::facturaA()->setNumero(42);
        $this->transport
            ->on('FECAESolicitar', new TransportException('timeout'))
            ->on('FECompConsultar', self::voucher(42, 1.0, $invoice->getFechaEmision()->format('Ymd')));

        $this->expectException(AfipException::class);
        $this->expectExceptionMessage('ya fue autorizado por ARCA para otro comprobante');
        $this->see->send($invoice);
    }

    public function testRenumbersWhenAnotherProcessTookTheNumber(): void
    {
        $this->transport
            ->on('FECompUltimoAutorizado', self::last(41))
            ->on('FECompUltimoAutorizado', self::last(42))
            ->on('FECompUltimoAutorizado', self::last(42))
            ->on('FECAESolicitar', WsfeServiceTest::caeResponse(42, 'R', '<Observaciones><Obs><Code>10016</Code><Msg>El numero no es el proximo a autorizar</Msg></Obs></Observaciones>'))
            ->on('FECAESolicitar', WsfeServiceTest::caeResponse(43));

        $invoice = Factory::facturaA();
        $result = $this->see->send($invoice);

        self::assertTrue($result->isSuccess());
        self::assertSame(43, $invoice->getNumero());
        self::assertCount(2, $this->transport->requestsFor('FECAESolicitar'));
    }

    public function testRejectsDateBeforeLastVoucher(): void
    {
        $this->see->setCheckLastVoucherDate(true);
        $invoice = Factory::facturaA();
        $this->transport
            ->on('FECompUltimoAutorizado', self::last(41))
            ->on('FECompConsultar', self::voucher(41, 10, $invoice->getFechaEmision()->modify('+1 day')->format('Ymd')));

        try {
            $this->see->send($invoice);
            self::fail('Se esperaba ValidationException');
        } catch (ValidationException $e) {
            self::assertTrue($e->getResult()->hasCode('FECHA_ANTERIOR_ULTIMO'));
        }
        self::assertSame([], $this->transport->requestsFor('FECAESolicitar'));
    }

    public function testCacheDirectoryEnablesLocksAndPersistentTickets(): void
    {
        $dir = sys_get_temp_dir() . '/afip-see-' . bin2hex(random_bytes(4));
        $this->see->setCacheDirectory($dir);
        $this->transport->on('FECompUltimoAutorizado', self::last(1))->on('FECAESolicitar', WsfeServiceTest::caeResponse(2));

        self::assertTrue($this->see->send(Factory::facturaA())->isSuccess());
        self::assertNotEmpty(glob($dir . '/tickets/TA-*.json'));
        self::assertNotEmpty(glob($dir . '/locks/*.lock'));
    }

    public function testRejectsDisabledSalesPoint(): void
    {
        $invoice = Factory::facturaA()->setPuntoVenta(5);
        try {
            $this->see->send($invoice);
            self::fail('Se esperaba ValidationException');
        } catch (ValidationException $e) {
            self::assertTrue($e->getResult()->hasCode('PTO_VTA_NO_HABILITADO'));
        }
        self::assertSame([], $this->transport->requestsFor('FECAESolicitar'));
    }

    public function testQrUrl(): void
    {
        $invoice = Factory::facturaA()->setNumero(42)->setCae('76123456789012');
        $url = $this->see->getQrUrl($invoice);
        self::assertStringStartsWith('https://www.afip.gob.ar/fe/qr/?p=', $url);
    }
}
