<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RoyaltyFusion\Afip\Calculator\InvoiceCalculator;
use RoyaltyFusion\Afip\Catalog\AlicuotaIva;
use RoyaltyFusion\Afip\Catalog\CondicionIva;
use RoyaltyFusion\Afip\Catalog\TipoDocumento;
use RoyaltyFusion\Afip\Environment;
use RoyaltyFusion\Afip\Model\Client;
use RoyaltyFusion\Afip\Model\Sale\FormaPago;
use RoyaltyFusion\Afip\Model\Sale\SaleDetail;
use RoyaltyFusion\Afip\Model\Sale\Tributo;
use RoyaltyFusion\Afip\Model\Sale\TurismoInvoice;
use RoyaltyFusion\Afip\See;
use RoyaltyFusion\Afip\Service\WsctService;
use RoyaltyFusion\Afip\Support\Date;
use RoyaltyFusion\Afip\Tests\Support\Factory;
use RoyaltyFusion\Afip\Tests\Support\MockTransport;
use RoyaltyFusion\Afip\Tests\Support\TestCertificate;
use RoyaltyFusion\Afip\Validator\InvoiceValidator;

final class WsctTest extends TestCase
{
    private const NS = WsctService::NAMESPACE;

    private static function factura(): TurismoInvoice
    {
        $invoice = (new TurismoInvoice())
            ->setPuntoVenta(4000)
            ->setFechaEmision(Date::today())
            ->setCompany(Factory::company())
            ->setClient((new Client())->setTipoDoc(TipoDocumento::PASAPORTE)->setNumDoc('AB123456')->setRazonSocial('John Smith')->setCondicionIva(CondicionIva::CLIENTE_EXTERIOR))
            ->setCodigoPais(203)
            ->setDomicilioReceptor('Rua N.76 km 34.5 Alagoas')
            ->setCodigoRelacionEmisorReceptor(3)
            ->addDetail((new SaleDetail())->setTipoItem(0)->setCodigoTurismo(1)->setCodigo('T0001')->setDescripcion('Alojamiento sin desayuno')
                ->setPrecioUnitario(100)->setAlicuotaIva(AlicuotaIva::IVA_21))
            ->addTributo(new Tributo(99, 'Impuesto Municipal', 100, 1, 1))
            ->addFormaPago(new FormaPago(68, 99, '999999'));
        $invoice->setImporteReintegro(-21.0);

        return (new InvoiceCalculator())->calculate($invoice, true);
    }

    private static function ret(string $op, string $content): string
    {
        return MockTransport::java(self::NS, $op, sprintf('<%1$sReturn>%2$s</%1$sReturn>', $op, $content));
    }

    public function testAmountsIncludeRefund(): void
    {
        $invoice = self::factura();
        self::assertSame(101.0, $invoice->getImpTotal()); // 100 + 21 IVA + 1 tributo − 21 reintegro
        $result = (new InvoiceValidator())->validate($invoice, InvoiceValidator::MODE_CT);
        self::assertTrue($result->isValid(), implode("\n", array_map('strval', $result->getErrors())));
    }

    public function testSendTurismo(): void
    {
        $transport = (new MockTransport())->withLogin()
            ->on('consultarPuntosVentaRequest', self::ret('consultarPuntosVenta', '<arrayPuntosVenta><puntoVenta><numeroPuntoVenta>4000</numeroPuntoVenta><bloqueado>N</bloqueado></puntoVenta></arrayPuntosVenta>'))
            ->on('consultarUltimoComprobanteAutorizadoRequest', self::ret('consultarUltimoComprobanteAutorizado', '<numeroComprobante>7</numeroComprobante><fechaEmision>2026-10-01</fechaEmision>'))
            ->on('consultarComprobanteTipoPVentaNroRequest', MockTransport::java(self::NS, 'consultarComprobanteTipoPVentaNro', '<consultarComprobanteReturn><comprobante><fechaEmision>2026-10-01</fechaEmision></comprobante></consultarComprobanteReturn>'))
            ->on('autorizarComprobanteRequest', self::ret('autorizarComprobante', '<comprobanteResponse><cuit>20111111112</cuit><codigoTipoComprobante>195</codigoTipoComprobante>'
                . '<numeroPuntoVenta>4000</numeroPuntoVenta><numeroComprobante>8</numeroComprobante><fechaEmision>2026-10-29</fechaEmision><CAE>71253948353499</CAE>'
                . '<fechaVencimientoCAE>2026-11-08</fechaVencimientoCAE></comprobanteResponse><resultado>A</resultado>'));
        $see = (new See(Environment::HOMOLOGACION))->setCertificate(TestCertificate::create())->setTransport($transport);

        $invoice = self::factura();
        $result = $see->sendTurismo($invoice);

        self::assertTrue($result->isSuccess(), $result->getErrorMessage());
        self::assertSame('71253948353499', $invoice->getCae());
        self::assertSame(8, $invoice->getNumero());

        $body = $transport->requestsFor('autorizarComprobanteRequest')[0]['body'];
        self::assertStringContainsString('<codigoTipoDocumento>94</codigoTipoDocumento><numeroDocumento>AB123456</numeroDocumento><idImpositivo>9</idImpositivo><codigoPais>203</codigoPais>', $body);
        self::assertStringContainsString('<importeGravado>100.00</importeGravado><importeNoGravado>0.00</importeNoGravado><importeExento>0.00</importeExento><importeOtrosTributos>1.00</importeOtrosTributos><importeReintegro>-21.00</importeReintegro><importeTotal>101.00</importeTotal>', $body);
        self::assertStringContainsString('<item><tipo>0</tipo><codigoTurismo>1</codigoTurismo><codigo>T0001</codigo><descripcion>Alojamiento sin desayuno</descripcion><codigoAlicuotaIVA>5</codigoAlicuotaIVA><importeIVA>21.00</importeIVA><importeItem>121.00</importeItem></item>', $body);
        self::assertStringContainsString('<arrayFormasPago><formaPago><codigo>68</codigo><tipoTarjeta>99</tipoTarjeta><numeroTarjeta>999999</numeroTarjeta></formaPago></arrayFormasPago>', $body);
    }

    public function testRejectionIsReported(): void
    {
        $transport = (new MockTransport())->withLogin()
            ->on('consultarPuntosVentaRequest', self::ret('consultarPuntosVenta', ''))
            ->on('autorizarComprobanteRequest', self::ret('autorizarComprobante', '<arrayErrores><codigoDescripcion><codigo>346</codigo><descripcion>Reintegro inválido</descripcion></codigoDescripcion></arrayErrores><resultado>R</resultado>'));
        $see = (new See(Environment::HOMOLOGACION))->setCertificate(TestCertificate::create())->setTransport($transport);
        $result = $see->sendTurismo(self::factura()->setNumero(3));
        self::assertFalse($result->isSuccess());
        self::assertTrue($result->hasCode(346));
    }

    public function testGetVoucherUsesRealReturnNode(): void
    {
        $transport = (new MockTransport())->withLogin()->on('consultarComprobanteTipoPVentaNroRequest', MockTransport::java(self::NS, 'consultarComprobanteTipoPVentaNro',
            '<consultarComprobanteReturn><comprobante><codigoTipoComprobante>195</codigoTipoComprobante><numeroPuntoVenta>40</numeroPuntoVenta><numeroComprobante>8</numeroComprobante>'
            . '<fechaEmision>2026-10-29</fechaEmision><codigoAutorizacion>71253948353499</codigoAutorizacion><importeTotal>101.00</importeTotal></comprobante></consultarComprobanteReturn>'));
        $see = (new See(Environment::HOMOLOGACION))->setCertificate(TestCertificate::create())->setTransport($transport);
        $voucher = $see->getWsct()->getVoucher(195, 40, 8);
        self::assertNotNull($voucher);
        self::assertSame('71253948353499', $voucher['codigoAutorizacion']);
    }

    public function testDiscountItem(): void
    {
        $invoice = self::factura()->addDetail(SaleDetail::descuento('Descuento promoción', 10, AlicuotaIva::IVA_21));
        $invoice->setImporteReintegro(-18.9);
        (new InvoiceCalculator())->calculate($invoice, true);
        self::assertSame(90.0, $invoice->getImpNeto());
        $result = (new InvoiceValidator())->validate($invoice, InvoiceValidator::MODE_CT);
        self::assertTrue($result->isValid(), implode("\n", array_map('strval', $result->getErrors())));
        $data = (new \ReflectionClass(WsctService::class))->newInstanceWithoutConstructor()->buildComprobante($invoice->setNumero(1));
        self::assertSame(99, $data['arrayItems']['item'][1]['tipo']);
        self::assertSame('-12.10', $data['arrayItems']['item'][1]['importeItem']);
    }

    public function testTurismoValidations(): void
    {
        $v = new InvoiceValidator();
        $codes = static fn ($i): array => array_map(static fn ($e): string => $e->getCode(), $v->validate($i, InvoiceValidator::MODE_CT)->getErrors());

        $positive = self::factura()->setImporteReintegro(5.0);
        (new InvoiceCalculator())->calculate($positive, true);
        self::assertContains('CT_REINTEGRO', $codes($positive));

        self::assertContains('CT_RELACION', $codes(self::factura()->setCodigoRelacionEmisorReceptor(0)));
        self::assertContains('PTO_VTA_INVALIDO', $codes(self::factura()->setPuntoVenta(12000)));
        self::assertContains('CT_TARJETA', $codes(self::factura()->setFormasPago([new FormaPago(68, 1, '1234')])));
        self::assertContains('CT_MODELO', $codes(Factory::facturaA()));
        self::assertContains('TOTAL_NO_CUADRA', $codes(self::factura()->setImpTotal(122)));
    }
}
