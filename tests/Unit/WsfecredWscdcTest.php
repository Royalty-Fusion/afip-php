<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RoyaltyFusion\Afip\Environment;
use RoyaltyFusion\Afip\See;
use RoyaltyFusion\Afip\Service\WsfecredService;
use RoyaltyFusion\Afip\Tests\Support\MockTransport;
use RoyaltyFusion\Afip\Tests\Support\TestCertificate;

final class WsfecredWscdcTest extends TestCase
{
    private const NS = WsfecredService::NAMESPACE;

    private function see(MockTransport $transport): See
    {
        return (new See(Environment::HOMOLOGACION))->setCertificate(TestCertificate::create())->setTransport($transport);
    }

    public function testObligationToReceiveFce(): void
    {
        $transport = (new MockTransport())->withLogin()->on('consultarMontoObligadoRecepcionRequest', MockTransport::java(self::NS, 'consultarMontoObligadoRecepcion',
            '<consultarMontoObligadoRecepcionReturn><obligado>S</obligado><montoDesde>3958316.00</montoDesde></consultarMontoObligadoRecepcionReturn>'));
        $result = $this->see($transport)->getWsfecred()->getObligation('30-50001091-2', new \DateTimeImmutable('2026-10-29'));

        self::assertTrue($result['obligado']);
        self::assertSame(3958316.0, $result['montoDesde']);
        $body = $transport->requestsFor('consultarMontoObligadoRecepcionRequest')[0]['body'];
        self::assertStringContainsString('<ns1:consultarMontoObligadoRecepcionRequest xmlns:ns1="' . self::NS . '"><authRequest><token>TOKEN</token><sign>SIGN</sign><cuitRepresentada>20111111112</cuitRepresentada></authRequest><cuitConsultada>30500010912</cuitConsultada><fechaEmision>2026-10-29</fechaEmision>', $body);
        self::assertSame('"' . self::NS . 'consultarMontoObligadoRecepcion"', $transport->requestsFor('consultarMontoObligadoRecepcionRequest')[0]['headers']['SOAPAction']);
    }

    public function testAcceptAndRejectFce(): void
    {
        $ok = '<operacionFECredReturn><resultado>A</resultado><idCtaCte><codCtaCte>2561</codCtaCte></idCtaCte></operacionFECredReturn>';
        $transport = (new MockTransport())->withLogin()
            ->on('aceptarFECredRequest', MockTransport::java(self::NS, 'aceptarFECred', $ok))
            ->on('rechazarFECredRequest', MockTransport::java(self::NS, 'rechazarFECred', '<operacionFECredReturn><resultado>R</resultado><arrayErrores><codigoDescripcion><codigo>1100</codigo><descripcion>La FCE ya fue aceptada</descripcion></codigoDescripcion></arrayErrores></operacionFECredReturn>'));
        $wsfecred = $this->see($transport)->getWsfecred();
        $id = WsfecredService::cuentaCorriente(2561, WsfecredService::comprobante('30500010912', 201, 1, 15));

        $accept = $wsfecred->accept($id, 121000.0, 'PES', 1.0, [
            'retenciones' => [['codTipo' => 1, 'importe' => 1500.0, 'porcentaje' => 1.5]],
            'formasCancelacion' => [['descripcion' => 'Transferencia', 'codigo' => 1]],
        ]);
        self::assertTrue($accept->isSuccess());
        $body = $transport->requestsFor('aceptarFECredRequest')[0]['body'];
        self::assertStringContainsString('<idCtaCte><codCtaCte>2561</codCtaCte><idFactura><CUITEmisor>30500010912</CUITEmisor><codTipoCmp>201</codTipoCmp><ptoVta>1</ptoVta><nroCmp>15</nroCmp></idFactura></idCtaCte>', $body);
        self::assertStringContainsString('<arrayFormasCancelacion><codigoDescripcion><codigo>1</codigo><descripcion>Transferencia</descripcion></codigoDescripcion></arrayFormasCancelacion>', $body);
        self::assertStringContainsString('<saldoAceptado>121000.00</saldoAceptado><codMoneda>PES</codMoneda><cotizacionMonedaUlt>1</cotizacionMonedaUlt>', $body);

        $reject = $wsfecred->reject($id, [['justificacion' => 'Mercadería no recibida', 'codMotivo' => 1, 'descMotivo' => 'Falta de entrega']]);
        self::assertFalse($reject->isSuccess());
        self::assertTrue($reject->hasCode(1100));
        self::assertStringContainsString('<motivoRechazo><codMotivo>1</codMotivo><descMotivo>Falta de entrega</descMotivo><justificacion>Mercadería no recibida</justificacion></motivoRechazo>', $transport->requestsFor('rechazarFECredRequest')[0]['body']);
    }

    public function testVerifySupplierVoucher(): void
    {
        $transport = (new MockTransport())->withLogin()->on('ComprobanteConstatar', MockTransport::envelope(
            '<ComprobanteConstatarResponse xmlns="http://servicios1.afip.gob.ar/wscdc/"><ComprobanteConstatarResult><CmpResp><CbteModo>CAE</CbteModo><CuitEmisor>30500010912</CuitEmisor>'
            . '<PtoVta>5</PtoVta><CbteTipo>1</CbteTipo><CbteNro>2424</CbteNro><CbteFch>20261020</CbteFch><ImpTotal>1754.5</ImpTotal><CodAutorizacion>69333775104382</CodAutorizacion></CmpResp>'
            . '<Resultado>A</Resultado><FchProceso>20261029152855</FchProceso><Events><Evt><Code>0</Code></Evt></Events></ComprobanteConstatarResult></ComprobanteConstatarResponse>'
        ));
        $result = $this->see($transport)->getWscdc()->verify('30500010912', 5, 1, 2424, new \DateTimeImmutable('2026-10-20'), 1754.5, '69333775104382', 'CAE', 80, '20111111112');

        self::assertTrue($result->isSuccess());
        $body = $transport->requestsFor('ComprobanteConstatar')[0]['body'];
        self::assertStringContainsString('<CmpReq><CbteModo>CAE</CbteModo><CuitEmisor>30500010912</CuitEmisor><PtoVta>5</PtoVta><CbteTipo>1</CbteTipo><CbteNro>2424</CbteNro><CbteFch>20261020</CbteFch><ImpTotal>1754.50</ImpTotal><CodAutorizacion>69333775104382</CodAutorizacion><DocTipoReceptor>80</DocTipoReceptor><DocNroReceptor>20111111112</DocNroReceptor></CmpReq>', $body);
    }

    public function testVerifyRejected(): void
    {
        $transport = (new MockTransport())->withLogin()->on('ComprobanteConstatar', MockTransport::envelope(
            '<ComprobanteConstatarResponse xmlns="http://servicios1.afip.gob.ar/wscdc/"><ComprobanteConstatarResult><Resultado>R</Resultado>'
            . '<Observaciones><Obs><Code>102</Code><Msg>El importe total no coincide</Msg></Obs></Observaciones></ComprobanteConstatarResult></ComprobanteConstatarResponse>'
        ));
        $result = $this->see($transport)->getWscdc()->verify('30500010912', 5, 1, 2424, new \DateTimeImmutable(), 10.0, '69333775104382');
        self::assertFalse($result->isSuccess());
        self::assertTrue($result->hasCode(102));
    }
}
