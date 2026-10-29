<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RoyaltyFusion\Afip\Calculator\InvoiceCalculator;
use RoyaltyFusion\Afip\Catalog\AlicuotaIva;
use RoyaltyFusion\Afip\Catalog\TipoComprobante;
use RoyaltyFusion\Afip\Catalog\TipoTributo;
use RoyaltyFusion\Afip\Environment;
use RoyaltyFusion\Afip\Model\Sale\BonoFiscalInvoice;
use RoyaltyFusion\Afip\Model\Sale\SaleDetail;
use RoyaltyFusion\Afip\Model\Sale\Tributo;
use RoyaltyFusion\Afip\See;
use RoyaltyFusion\Afip\Support\Date;
use RoyaltyFusion\Afip\Tests\Support\Factory;
use RoyaltyFusion\Afip\Tests\Support\MockTransport;
use RoyaltyFusion\Afip\Tests\Support\TestCertificate;
use RoyaltyFusion\Afip\Validator\InvoiceValidator;

final class WsbfeTest extends TestCase
{
    private static function bono(): BonoFiscalInvoice
    {
        $invoice = (new BonoFiscalInvoice())
            ->setZona(1)
            ->setTipoComprobante(TipoComprobante::FACTURA_A)
            ->setPuntoVenta(5)
            ->setFechaEmision(Date::today())
            ->setCompany(Factory::company())
            ->setClient(Factory::clientRi())
            ->addDetail((new SaleDetail())->setCodigoNcm('2101.11.10')->setDescripcion('Cafe')->setCantidad(10)
                ->setCodigoUnidadMedida(5)->setPrecioUnitario(150)->setBonificacion(50)->setAlicuotaIva(AlicuotaIva::IVA_21));

        return (new InvoiceCalculator())->calculate($invoice, true);
    }

    private static function wsbfe(string $op, string $content): string
    {
        return MockTransport::envelope(sprintf('<%1$sResponse xmlns="http://ar.gov.afip.dif.bfev1/"><%1$sResult>%2$s</%1$sResult></%1$sResponse>', $op, $content));
    }

    public function testRequestMatchesApprovedArcaExample(): void
    {
        $transport = (new MockTransport())->withLogin()
            ->on('BFEGetLast_CMP', self::wsbfe('BFEGetLast_CMP', '<BFEResult_LastCMP><Cbte_nro>2580</Cbte_nro></BFEResult_LastCMP><BFEErr><ErrCode>0</ErrCode><ErrMsg>OK</ErrMsg></BFEErr>'))
            ->on('BFEGetLast_ID', self::wsbfe('BFEGetLast_ID', '<BFEResultGet><Id>993456789012386</Id></BFEResultGet><BFEErr><ErrCode>0</ErrCode><ErrMsg>OK</ErrMsg></BFEErr>'))
            ->on('BFEAuthorize', self::wsbfe('BFEAuthorize', '<BFEResultAuth><Id>993456789012387</Id><Cuit>20111111112</Cuit><Cae>71253948358918</Cae><Fch_venc_Cae>20261108</Fch_venc_Cae>'
                . '<Fch_cbte>20261029</Fch_cbte><Resultado>A</Resultado><Reproceso>N</Reproceso><Obs> </Obs></BFEResultAuth><BFEErr><ErrCode>0</ErrCode><ErrMsg>OK</ErrMsg></BFEErr><BFEEvents><EventCode>0</EventCode><EventMsg /></BFEEvents>'));
        $see = (new See(Environment::HOMOLOGACION))->setCertificate(TestCertificate::create())->setTransport($transport);

        $invoice = self::bono();
        $result = $see->sendBfe($invoice);

        self::assertTrue($result->isSuccess(), $result->getErrorMessage());
        self::assertSame('71253948358918', $invoice->getCae());
        self::assertSame(2581, $invoice->getNumero());
        self::assertSame(993456789012387, $invoice->getId());

        $body = $transport->requestsFor('BFEAuthorize')[0]['body'];
        // Mismos valores que un bono aprobado por ARCA en homologación.
        self::assertStringContainsString('<Imp_total>1754.50</Imp_total><Imp_tot_conc>0.00</Imp_tot_conc><Imp_neto>1450.00</Imp_neto><Impto_liq>304.50</Impto_liq>', $body);
        self::assertStringContainsString('<Item><Pro_codigo_ncm>2101.11.10</Pro_codigo_ncm><Pro_codigo_sec></Pro_codigo_sec><Pro_ds>Cafe</Pro_ds><Pro_qty>10</Pro_qty><Pro_umed>5</Pro_umed>'
            . '<Pro_precio_uni>150</Pro_precio_uni><Imp_bonif>50.00</Imp_bonif><Imp_total>1754.50</Imp_total><Iva_id>5</Iva_id></Item>', $body);
        self::assertStringContainsString('<Zona>1</Zona>', $body);
        self::assertStringContainsString('<CondicionIVAReceptorId>1</CondicionIVAReceptorId>', $body);
    }

    public function testRejectedReleasesNumbers(): void
    {
        $transport = (new MockTransport())->withLogin()
            ->on('BFEGetLast_CMP', self::wsbfe('BFEGetLast_CMP', '<BFEResult_LastCMP><Cbte_nro>67</Cbte_nro></BFEResult_LastCMP><BFEErr><ErrCode>0</ErrCode><ErrMsg>OK</ErrMsg></BFEErr>'))
            ->on('BFEGetLast_ID', self::wsbfe('BFEGetLast_ID', '<BFEResultGet><Id>1</Id></BFEResultGet><BFEErr><ErrCode>0</ErrCode><ErrMsg>OK</ErrMsg></BFEErr>'))
            ->on('BFEAuthorize', self::wsbfe('BFEAuthorize', '<BFEResultAuth><Id>0</Id><Cuit>0</Cuit></BFEResultAuth><BFEErr><ErrCode>4954</ErrCode><ErrMsg>Es obligatorio informar opcional por RG con ID 27</ErrMsg></BFEErr>'));
        $see = (new See(Environment::HOMOLOGACION))->setCertificate(TestCertificate::create())->setTransport($transport);

        $invoice = self::bono();
        $result = $see->sendBfe($invoice);
        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('4954', $result->getErrorMessage());
        self::assertNull($invoice->getNumero());
        self::assertNull($invoice->getId());
    }

    public function testBfeValidations(): void
    {
        $v = new InvoiceValidator();
        $codes = static fn ($i): array => array_map(static fn ($e): string => $e->getCode(), $v->validate($i, InvoiceValidator::MODE_BFE)->getErrors());

        self::assertSame([], $codes(self::bono()));

        $sinNcm = self::bono();
        $sinNcm->getDetails()[0]->setCodigoNcm(null);
        self::assertContains('BFE_NCM', $codes($sinNcm));

        $servicios = self::bono()->setConcepto(2)->setPeriodoServicio(Date::today(), Date::today(), Date::today());
        self::assertContains('BFE_CONCEPTO', $codes($servicios));

        $tributo = self::bono()->addTributo(new Tributo(TipoTributo::OTRO, 'Otro', 0, 0, 10));
        (new InvoiceCalculator())->calculate($tributo, true);
        self::assertContains('BFE_TRIBUTO', $codes($tributo));

        $fce = self::bono()->setTipoComprobante(TipoComprobante::FCE_FACTURA_A);
        self::assertContains('FCE_TRANSFERENCIA_REQUERIDA', $codes($fce));
        self::assertContains('FCE_VTO_PAGO_REQUERIDO', $codes($fce));
    }

    public function testPerceptionsAreMappedToBfeFields(): void
    {
        $invoice = self::bono()->setId(1)->setNumero(1)
            ->addTributo(new Tributo(TipoTributo::PERCEPCION_IIBB, 'Perc. IIBB', 1450, 3, 43.5))
            ->addTributo(new Tributo(TipoTributo::IMPUESTOS_INTERNOS, 'Internos', 1450, 1, 14.5));
        (new InvoiceCalculator())->calculate($invoice, true);
        $cmp = (new \ReflectionClass(\RoyaltyFusion\Afip\Service\WsbfeService::class))->newInstanceWithoutConstructor()->buildRequest($invoice);
        self::assertSame('43.50', $cmp['Imp_iibb']);
        self::assertSame('14.50', $cmp['Imp_internos']);
        self::assertSame('1812.50', $cmp['Imp_total']);
    }
}
