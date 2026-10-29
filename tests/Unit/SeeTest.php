<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RoyaltyFusion\Afip\Environment;
use RoyaltyFusion\Afip\Exception\AfipException;
use RoyaltyFusion\Afip\Exception\TransportException;
use RoyaltyFusion\Afip\Exception\UncertainAuthorizationException;
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
            ->setCheckLastVoucherDate(false)
            ->setRecoveryPolicy(3, 0);
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

    public function testEnvironmentFromString(): void
    {
        self::assertSame(Environment::PRODUCCION, (new See('produccion'))->getEnvironment());
        self::assertSame(Environment::HOMOLOGACION, (new See('homologacion'))->getEnvironment());
        self::assertSame(Environment::PRODUCCION, (new See('prod'))->getEnvironment());
        $this->expectException(\InvalidArgumentException::class);
        new See('staging');
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

    public function testTimeoutWithoutVoucherIsUncertainAndKeepsNumber(): void
    {
        $this->transport
            ->on('FECompUltimoAutorizado', self::last(41))
            ->on('FECAESolicitar', new TransportException('timeout'))
            ->on('FECompConsultar', MockTransport::wsfe('FECompConsultar', '<Errors><Err><Code>602</Code><Msg>Sin Resultados</Msg></Err></Errors>'));
        $invoice = Factory::facturaA();

        try {
            $this->see->send($invoice);
            self::fail('Se esperaba UncertainAuthorizationException');
        } catch (UncertainAuthorizationException $e) {
            self::assertSame([42], $e->getNumeros());
            self::assertTrue($e->isNotFound());
            self::assertSame(42, $invoice->getNumero()); // se reenvía con el mismo número
            self::assertCount(3, $this->transport->requestsFor('FECompConsultar'));
        }
    }

    public function testRecoveryFailureIsUncertain(): void
    {
        $invoice = Factory::facturaA()->setNumero(42);
        $this->transport
            ->on('FECAESolicitar', new TransportException('timeout'))
            ->on('FECompConsultar', new TransportException('sin red'));

        try {
            $this->see->send($invoice);
            self::fail('Se esperaba UncertainAuthorizationException');
        } catch (UncertainAuthorizationException $e) {
            self::assertFalse($e->isNotFound());
        }
    }

    public function testRejectedInvoiceReleasesAutomaticNumber(): void
    {
        $this->transport->on('FECompUltimoAutorizado', self::last(41))
            ->on('FECAESolicitar', WsfeServiceTest::caeResponse(42, 'R', '<Observaciones><Obs><Code>10015</Code><Msg>DocNro invalido</Msg></Obs></Observaciones>'));
        $invoice = Factory::facturaA();
        $result = $this->see->send($invoice);

        self::assertFalse($result->isSuccess());
        self::assertNull($invoice->getNumero());
    }

    public function testPartiallyRejectedBatchIsNeverResent(): void
    {
        $partial = MockTransport::wsfe('FECAESolicitar',
            '<FeCabResp><Cuit>20111111112</Cuit><PtoVta>1</PtoVta><CbteTipo>6</CbteTipo><CantReg>3</CantReg><Resultado>P</Resultado></FeCabResp><FeDetResp>'
            . '<FECAEDetResponse><Concepto>1</Concepto><DocTipo>99</DocTipo><DocNro>0</DocNro><CbteDesde>11</CbteDesde><CbteHasta>11</CbteHasta><Resultado>A</Resultado><CAE>76000000000011</CAE><CAEFchVto>20261108</CAEFchVto></FECAEDetResponse>'
            . '<FECAEDetResponse><Concepto>1</Concepto><DocTipo>99</DocTipo><DocNro>0</DocNro><CbteDesde>12</CbteDesde><CbteHasta>12</CbteHasta><Resultado>R</Resultado><Observaciones><Obs><Code>10063</Code><Msg>x</Msg></Obs></Observaciones></FECAEDetResponse>'
            . '<FECAEDetResponse><Concepto>1</Concepto><DocTipo>99</DocTipo><DocNro>0</DocNro><CbteDesde>13</CbteDesde><CbteHasta>13</CbteHasta><Resultado>R</Resultado><Observaciones><Obs><Code>10016</Code><Msg>y</Msg></Obs></Observaciones></FECAEDetResponse>'
            . '</FeDetResp>');
        $this->transport->on('FECompUltimoAutorizado', self::last(10))->on('FECompUltimoAutorizado', self::last(11))->on('FECAESolicitar', $partial);
        $invoices = [Factory::facturaB(), Factory::facturaB(), Factory::facturaB()];

        $result = $this->see->sendBatch($invoices);

        self::assertSame('P', $result->getResultado());
        self::assertCount(1, $this->transport->requestsFor('FECAESolicitar'));
        self::assertSame(11, $invoices[0]->getNumero());
        self::assertSame('76000000000011', $invoices[0]->getCae());
        self::assertNull($invoices[1]->getNumero());
        self::assertNull($invoices[2]->getNumero());
    }

    public function testSalesPointsWithoutResultsAreNotChecked(): void
    {
        $transport = (new MockTransport())->withLogin()
            ->on('FEParamGetPtosVenta', MockTransport::wsfe('FEParamGetPtosVenta', '<Errors><Err><Code>602</Code><Msg>Sin Resultados</Msg></Err></Errors>'))
            ->on('FECAESolicitar', WsfeServiceTest::caeResponse(42));
        $see = (new See(Environment::HOMOLOGACION))->setCertificate(TestCertificate::create())->setTransport($transport);

        self::assertTrue($see->send(Factory::facturaA()->setNumero(42))->isSuccess());
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

    public function testInformCaea(): void
    {
        $invoice = Factory::facturaB()->setNumero(15)->setCaea('31234567890123')->setFechaHoraGeneracion(new \DateTimeImmutable());
        $this->transport->on('FECAEARegInformativo', MockTransport::wsfe('FECAEARegInformativo',
            '<FeCabResp><Cuit>20111111112</Cuit><PtoVta>1</PtoVta><CbteTipo>6</CbteTipo><FchProceso>20261029</FchProceso><CantReg>1</CantReg><Resultado>A</Resultado></FeCabResp>'
            . '<FeDetResp><FECAEADetResponse><Concepto>1</Concepto><DocTipo>99</DocTipo><DocNro>0</DocNro><CbteDesde>15</CbteDesde><CbteHasta>15</CbteHasta>'
            . '<CbteFch>20261029</CbteFch><Resultado>A</Resultado><CAEA>31234567890123</CAEA></FECAEADetResponse></FeDetResp>'));

        $result = $this->see->informCaea($invoice);
        self::assertTrue($result->isSuccess());
        self::assertSame('31234567890123', $result->getCae());
        $body = $this->transport->requestsFor('FECAEARegInformativo')[0]['body'];
        self::assertStringContainsString('<CAEA>31234567890123</CAEA><CbteFchHsGen>', $body);
    }

    public function testInformCaeaValidatesCaea(): void
    {
        $this->expectException(ValidationException::class);
        $this->see->informCaea(Factory::facturaB()->setNumero(15));
    }

    public function testCompanyCuitIsFilledFromConfiguration(): void
    {
        $this->transport->on('FECAESolicitar', WsfeServiceTest::caeResponse(42));
        $invoice = Factory::facturaA()->setNumero(42);
        $invoice->getCompany()->setCuit('');
        $this->see->send($invoice);
        self::assertSame('20111111112', $invoice->getCompany()->getCuit());
    }

    public function testQrUrl(): void
    {
        $invoice = Factory::facturaA()->setNumero(42)->setCae('76123456789012');
        $url = $this->see->getQrUrl($invoice);
        self::assertStringStartsWith('https://www.afip.gob.ar/fe/qr/?p=', $url);
    }
}
