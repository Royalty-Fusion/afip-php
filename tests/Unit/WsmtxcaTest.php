<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RoyaltyFusion\Afip\Calculator\InvoiceCalculator;
use RoyaltyFusion\Afip\Catalog\AlicuotaIva;
use RoyaltyFusion\Afip\Catalog\Concepto;
use RoyaltyFusion\Afip\Catalog\TipoComprobante;
use RoyaltyFusion\Afip\Environment;
use RoyaltyFusion\Afip\Exception\ValidationException;
use RoyaltyFusion\Afip\Model\Client;
use RoyaltyFusion\Afip\Model\Sale\DatoAdicional;
use RoyaltyFusion\Afip\Model\Sale\Invoice;
use RoyaltyFusion\Afip\Model\Sale\SaleDetail;
use RoyaltyFusion\Afip\See;
use RoyaltyFusion\Afip\Service\WsmtxcaService;
use RoyaltyFusion\Afip\Tests\Support\Factory;
use RoyaltyFusion\Afip\Tests\Support\MockTransport;
use RoyaltyFusion\Afip\Tests\Support\TestCertificate;
use RoyaltyFusion\Afip\Validator\InvoiceValidator;

final class WsmtxcaTest extends TestCase
{
    private const NS = WsmtxcaService::NAMESPACE;

    private MockTransport $transport;
    private See $see;

    protected function setUp(): void
    {
        $this->transport = (new MockTransport())->withLogin()
            ->on('consultarPuntosVentaCAERequest', MockTransport::java(self::NS, 'consultarPuntosVentaCAE', '<arrayPuntosVenta/>'));
        $this->see = (new See(Environment::HOMOLOGACION))
            ->setCertificate(TestCertificate::create())
            ->setTransport($this->transport)
            ->setCheckLastVoucherDate(false)
            ->setRecoveryPolicy(1, 0);
    }

    private static function facturaA(): Invoice
    {
        $invoice = Factory::facturaA()->setDetails([
            (new SaleDetail())->setCodigo('P0001')->setCodigoMtx('1234567890123')->setUnidadesMtx(123456)
                ->setDescripcion('Descripcion del producto P0001')->setCantidad(2)->setPrecioUnitario(100)
                ->setAlicuotaIva(AlicuotaIva::IVA_21),
        ]);

        return (new InvoiceCalculator())->calculate($invoice, true);
    }

    private static function approved(int $numero, string $resultado = 'A'): string
    {
        return MockTransport::java(self::NS, 'autorizarComprobante', '<resultado>' . $resultado . '</resultado><comprobanteResponse><cuit>20111111112</cuit>'
            . '<codigoTipoComprobante>1</codigoTipoComprobante><numeroPuntoVenta>1</numeroPuntoVenta><numeroComprobante>' . $numero . '</numeroComprobante>'
            . '<fechaEmision>2026-10-29</fechaEmision><CAE>71253948353413</CAE><fechaVencimientoCAE>2026-11-08</fechaVencimientoCAE></comprobanteResponse>'
            . ($resultado === 'O' ? '<arrayObservaciones><codigoDescripcion><codigo>17</codigo><descripcion>Observación</descripcion></codigoDescripcion></arrayObservaciones>' : ''));
    }

    public function testItemizedCalculationMatchesArcaExample(): void
    {
        $invoice = self::facturaA();
        $detail = $invoice->getDetails()[0];

        // Mismos importes que un comprobante real aprobado por ARCA en homologación.
        self::assertSame(42.0, $detail->getImporteIva());
        self::assertSame(242.0, $detail->getImporteItem());
        self::assertSame(200.0, $invoice->getImpNeto());
        self::assertSame(242.0, $invoice->getImpTotal());
        self::assertTrue((new InvoiceValidator())->validate($invoice, InvoiceValidator::MODE_MTXCA)->isValid());
    }

    public function testSendBuildsRequestLikeArca(): void
    {
        $this->transport
            ->on('consultarUltimoComprobanteAutorizadoRequest', MockTransport::java(self::NS, 'consultarUltimoComprobanteAutorizado', '<numeroComprobante>1757</numeroComprobante>'))
            ->on('autorizarComprobanteRequest', self::approved(1758));
        $invoice = self::facturaA();
        $result = $this->see->sendMtxca($invoice);

        self::assertTrue($result->isSuccess());
        self::assertSame('71253948353413', $invoice->getCae());
        self::assertSame(1758, $invoice->getNumero());

        $body = $this->transport->requestsFor('autorizarComprobanteRequest')[0]['body'];
        self::assertStringContainsString('<ns1:autorizarComprobanteRequest xmlns:ns1="' . self::NS . '"><authRequest><token>TOKEN</token><sign>SIGN</sign><cuitRepresentada>20111111112</cuitRepresentada></authRequest>', $body);
        self::assertStringContainsString('<item><unidadesMtx>123456</unidadesMtx><codigoMtx>1234567890123</codigoMtx><codigo>P0001</codigo><descripcion>Descripcion del producto P0001</descripcion>'
            . '<cantidad>2</cantidad><codigoUnidadMedida>7</codigoUnidadMedida><precioUnitario>100</precioUnitario><importeBonificacion>0</importeBonificacion>'
            . '<codigoCondicionIVA>5</codigoCondicionIVA><importeIVA>42.00</importeIVA><importeItem>242.00</importeItem></item>', $body);
        self::assertStringContainsString('<importeGravado>200.00</importeGravado><importeNoGravado>0.00</importeNoGravado><importeExento>0.00</importeExento><importeSubtotal>200.00</importeSubtotal><importeTotal>242.00</importeTotal>', $body);
        self::assertStringContainsString('<condicionIVAReceptor>1</condicionIVAReceptor>', $body);
        self::assertStringContainsString('<arraySubtotalesIVA><subtotalIVA><codigo>5</codigo><importe>42.00</importe></subtotalIVA></arraySubtotalesIVA>', $body);
        self::assertSame('"' . self::NS . 'autorizarComprobante"', $this->transport->requestsFor('autorizarComprobanteRequest')[0]['headers']['SOAPAction']);
    }

    public function testClaseBSendsPricesWithVatAndNoItemVat(): void
    {
        $invoice = Factory::facturaB()->setNumero(5)->setDetails([
            (new SaleDetail())->setDescripcion('Producto')->setCantidad(3)->setPrecioUnitario(100)->setAlicuotaIva(AlicuotaIva::IVA_21)->setBonificacion(10),
        ]);
        (new InvoiceCalculator())->calculate($invoice, true);
        $this->transport->on('autorizarComprobanteRequest', self::approved(5));
        $this->see->sendMtxca($invoice);

        $body = $this->transport->requestsFor('autorizarComprobanteRequest')[0]['body'];
        // neto 290 → IVA 60.90 → item 350.90 = 3 × 121 − 12.10
        self::assertStringContainsString('<cantidad>3</cantidad><codigoUnidadMedida>7</codigoUnidadMedida><precioUnitario>121</precioUnitario><importeBonificacion>12.1</importeBonificacion><codigoCondicionIVA>5</codigoCondicionIVA><importeItem>350.90</importeItem>', $body);
        self::assertStringNotContainsString('<importeIVA>', $body);
    }

    public function testObservedResultIsApproved(): void
    {
        $this->transport->on('autorizarComprobanteRequest', self::approved(9, 'O'));
        $result = $this->see->sendMtxca(self::facturaA()->setNumero(9));
        self::assertTrue($result->isSuccess());
        self::assertTrue($result->hasCode(17));
    }

    public function testNumberingConflictIsRetried(): void
    {
        $this->transport
            ->on('consultarUltimoComprobanteAutorizadoRequest', MockTransport::java(self::NS, 'consultarUltimoComprobanteAutorizado', '<numeroComprobante>10</numeroComprobante>'))
            ->on('consultarUltimoComprobanteAutorizadoRequest', MockTransport::java(self::NS, 'consultarUltimoComprobanteAutorizado', '<numeroComprobante>11</numeroComprobante>'))
            ->on('consultarUltimoComprobanteAutorizadoRequest', MockTransport::java(self::NS, 'consultarUltimoComprobanteAutorizado', '<numeroComprobante>11</numeroComprobante>'))
            ->on('autorizarComprobanteRequest', MockTransport::java(self::NS, 'autorizarComprobante', '<resultado>R</resultado><arrayErrores><codigoDescripcion><codigo>102</codigo><descripcion>El número de comprobante informado debe ser mayor en 1 al último informado</descripcion></codigoDescripcion></arrayErrores>'))
            ->on('autorizarComprobanteRequest', self::approved(12));

        $invoice = self::facturaA();
        self::assertTrue($this->see->sendMtxca($invoice)->isSuccess());
        self::assertSame(12, $invoice->getNumero());
    }

    public function testFirstVoucherWhenNoneRegistered(): void
    {
        $this->transport
            ->on('consultarUltimoComprobanteAutorizadoRequest', MockTransport::java(self::NS, 'consultarUltimoComprobanteAutorizado', '<arrayErrores><codigoDescripcion><codigo>1502</codigo><descripcion>no se registran comprobantes</descripcion></codigoDescripcion></arrayErrores>'))
            ->on('autorizarComprobanteRequest', self::approved(1));
        $invoice = self::facturaA();
        $this->see->sendMtxca($invoice);
        self::assertSame(1, $invoice->getNumero());
    }

    public function testMtxcaValidations(): void
    {
        $v = new InvoiceValidator();
        $codes = static fn (Invoice $i): array => array_map(static fn ($e): string => $e->getCode(), $v->validate($i, InvoiceValidator::MODE_MTXCA)->getErrors());

        $sinCalculo = Factory::facturaA(); // calculado sin detalle por ítem
        self::assertContains('ITEM_IMPORTES', $codes($sinCalculo));

        $sinItems = self::facturaA()->setDetails([]);
        self::assertContains('ITEMS_REQUERIDOS', $codes($sinItems));

        $c = Factory::facturaC();
        (new InvoiceCalculator())->calculate($c, true);
        self::assertContains('MTXCA_TIPO_NO_SOPORTADO', $codes($c));

        $mal = self::facturaA();
        $mal->getIvas()[0]->setImporte(40.0);
        self::assertContains('SUBTOTAL_IVA_ITEMS', $codes($mal));

        $opc = self::facturaA()->addOpcional(2, 'x');
        self::assertContains('MTXCA_OPCIONALES', $codes($opc));

        $fce = self::facturaA()->setTipoComprobante(TipoComprobante::FCE_FACTURA_A)->setFechaVencimientoPago(new \DateTimeImmutable('+30 days'));
        self::assertContains('FCE_CBU_REQUERIDO', $codes($fce));
        $fce->addDatoAdicional(new DatoAdicional(21, '2850590940090418135201'));
        self::assertNotContains('FCE_CBU_REQUERIDO', $codes($fce));
    }

    public function testInvalidMtxcaIsNotSent(): void
    {
        $this->expectException(ValidationException::class);
        try {
            $this->see->sendMtxca(Factory::facturaA());
        } finally {
            self::assertSame([], $this->transport->requestsFor('autorizarComprobanteRequest'));
        }
    }

    public function testExemptAndServicesItems(): void
    {
        $invoice = (new Invoice())->setTipoComprobante(TipoComprobante::FACTURA_A)->setNumero(3)
            ->setConcepto(Concepto::SERVICIOS)->setPeriodoServicio(new \DateTimeImmutable('today'), new \DateTimeImmutable('today'), new \DateTimeImmutable('+10 days'))
            ->setCompany(Factory::company())->setClient(Factory::clientRi())
            ->addDetail((new SaleDetail())->setDescripcion('Servicio')->setPrecioUnitario(1000)->setAlicuotaIva(AlicuotaIva::IVA_21))
            ->addDetail((new SaleDetail())->setDescripcion('Exento')->setPrecioUnitario(50)->setExento());
        (new InvoiceCalculator())->calculate($invoice, true);
        self::assertTrue((new InvoiceValidator())->validate($invoice, InvoiceValidator::MODE_MTXCA)->isValid());

        $wsfe = (new \ReflectionClass(WsmtxcaService::class))->newInstanceWithoutConstructor();
        $data = $wsfe->buildComprobante($invoice);
        self::assertSame('1260.00', $data['importeTotal']);
        self::assertSame('1050.00', $data['importeSubtotal']);
        self::assertSame(2, $data['arrayItems']['item'][1]['codigoCondicionIVA']);
        self::assertNull($data['arrayItems']['item'][1]['importeIVA']);
    }

    public function testDummyHasEmptyBody(): void
    {
        $this->transport->on('', MockTransport::java(self::NS, 'dummy', '<appserver>OK</appserver><authserver>OK</authserver><dbserver>OK</dbserver>'));
        self::assertSame('OK', $this->see->getWsmtxca()->dummy()['appserver']);
        self::assertStringContainsString('<soapenv:Body/>', $this->transport->requests[1]['body'] ?? $this->transport->requests[0]['body']);
    }
}
