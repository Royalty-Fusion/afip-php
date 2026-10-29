<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RoyaltyFusion\Afip\Auth\AuthManager;
use RoyaltyFusion\Afip\Auth\Wsaa;
use RoyaltyFusion\Afip\Catalog\Moneda;
use RoyaltyFusion\Afip\Catalog\TipoComprobante;
use RoyaltyFusion\Afip\Environment;
use RoyaltyFusion\Afip\Exception\ServiceException;
use RoyaltyFusion\Afip\Model\Sale\CbteAsociado;
use RoyaltyFusion\Afip\Service\WsfeService;
use RoyaltyFusion\Afip\Tests\Support\Factory;
use RoyaltyFusion\Afip\Tests\Support\MockTransport;
use RoyaltyFusion\Afip\Tests\Support\TestCertificate;

final class WsfeServiceTest extends TestCase
{
    private MockTransport $transport;
    private WsfeService $wsfe;

    protected function setUp(): void
    {
        $this->transport = (new MockTransport())->withLogin();
        $auth = new AuthManager(new Wsaa(TestCertificate::create(), Environment::HOMOLOGACION, $this->transport));
        $this->wsfe = new WsfeService($auth, '20111111112', Environment::HOMOLOGACION, $this->transport);
    }

    public static function caeResponse(int $numero, string $resultado = 'A', string $obs = ''): string
    {
        $cae = $resultado === 'A' ? '<CAE>76123456789012</CAE><CAEFchVto>20261108</CAEFchVto>' : '<CAE/><CAEFchVto/>';

        return MockTransport::wsfe('FECAESolicitar', sprintf(
            '<FeCabResp><Cuit>20111111112</Cuit><PtoVta>1</PtoVta><CbteTipo>1</CbteTipo><FchProceso>20261029101010</FchProceso>'
            . '<CantReg>1</CantReg><Resultado>%1$s</Resultado><Reproceso>N</Reproceso></FeCabResp>'
            . '<FeDetResp><FECAEDetResponse><Concepto>1</Concepto><DocTipo>80</DocTipo><DocNro>30500010912</DocNro>'
            . '<CbteDesde>%2$d</CbteDesde><CbteHasta>%2$d</CbteHasta><CbteFch>20261029</CbteFch><Resultado>%1$s</Resultado>%3$s%4$s'
            . '</FECAEDetResponse></FeDetResp>',
            $resultado,
            $numero,
            $obs,
            $cae
        ));
    }

    public function testBuildDetailFollowsWsdlOrder(): void
    {
        $invoice = Factory::facturaA()->setNumero(8)->setMoneda(Moneda::DOLAR, 1050.25)->setPagoMismaMoneda(true)
            ->addCbteAsociado(new CbteAsociado(TipoComprobante::FACTURA_A, 1, 3));
        $detail = $this->wsfe->buildDetail($invoice);

        $keys = array_keys(array_filter($detail, static fn ($v): bool => $v !== null));
        self::assertSame([
            'Concepto', 'DocTipo', 'DocNro', 'CbteDesde', 'CbteHasta', 'CbteFch', 'ImpTotal', 'ImpTotConc',
            'ImpNeto', 'ImpOpEx', 'ImpTrib', 'ImpIVA', 'MonId', 'MonCotiz', 'CanMisMonExt',
            'CondicionIVAReceptorId', 'CbtesAsoc', 'Iva',
        ], $keys);
        self::assertSame('2585.75', $detail['ImpTotal']);
        self::assertSame('1050.25', $detail['MonCotiz']);
        self::assertSame('S', $detail['CanMisMonExt']);
        self::assertSame(1, $detail['CondicionIVAReceptorId']);
        self::assertCount(2, $detail['Iva']['AlicIva']);
    }

    public function testBuildDetailFullOrder(): void
    {
        $invoice = Factory::facturaA()->setNumero(1)
            ->addTributo(new \RoyaltyFusion\Afip\Model\Sale\Tributo(7, 'Perc', 100, 3, 3))
            ->addOpcional(2, 'x')
            ->addComprador(new \RoyaltyFusion\Afip\Model\Sale\Comprador(80, '30500010912', 100))
            ->setPeriodoAsociado(new \RoyaltyFusion\Afip\Model\Sale\Periodo(new \DateTimeImmutable('-3 days'), new \DateTimeImmutable('-1 day')))
            ->addCbteAsociado(new CbteAsociado(1, 1, 1))
            ->setActividades([620100]);
        self::assertSame([
            'Concepto', 'DocTipo', 'DocNro', 'CbteDesde', 'CbteHasta', 'CbteFch', 'ImpTotal', 'ImpTotConc', 'ImpNeto',
            'ImpOpEx', 'ImpTrib', 'ImpIVA', 'FchServDesde', 'FchServHasta', 'FchVtoPago', 'MonId', 'MonCotiz',
            'CanMisMonExt', 'CondicionIVAReceptorId', 'CbtesAsoc', 'Tributos', 'Iva', 'Opcionales', 'Compradores',
            'PeriodoAsoc', 'Actividades',
        ], array_keys($this->wsfe->buildDetail($invoice)));
    }

    public function testAuthorizeApproved(): void
    {
        $this->transport->on('FECAESolicitar', self::caeResponse(8));
        $invoice = Factory::facturaA()->setNumero(8);
        $result = $this->wsfe->authorize($invoice);

        self::assertTrue($result->isSuccess());
        self::assertSame('76123456789012', $result->getCae());
        self::assertSame('2026-11-08', $result->getCaeVencimiento()->format('Y-m-d'));
        self::assertSame('76123456789012', $invoice->getCae());
        self::assertNotNull($result->getRequestXml());

        $body = $this->transport->requestsFor('FECAESolicitar')[0]['body'];
        self::assertStringContainsString('<Token>TOKEN</Token><Sign>SIGN</Sign><Cuit>20111111112</Cuit>', $body);
        self::assertStringContainsString('<CondicionIVAReceptorId>1</CondicionIVAReceptorId>', $body);
        self::assertStringContainsString('<FeCabReq><CantReg>1</CantReg><PtoVta>1</PtoVta><CbteTipo>1</CbteTipo></FeCabReq>', $body);
    }

    public function testAuthorizeRejectedWithObservations(): void
    {
        $this->transport->on('FECAESolicitar', self::caeResponse(8, 'R', '<Observaciones><Obs><Code>10015</Code><Msg>DocNro invalido</Msg></Obs></Observaciones>'));
        $invoice = Factory::facturaA()->setNumero(8);
        $result = $this->wsfe->authorize($invoice);

        self::assertFalse($result->isSuccess());
        self::assertTrue($result->hasCode(10015));
        self::assertStringContainsString('10015: DocNro invalido', $result->getErrorMessage());
        self::assertNull($invoice->getCae());
    }

    public function testLastVoucherAndErrors(): void
    {
        $this->transport->on('FECompUltimoAutorizado', MockTransport::wsfe('FECompUltimoAutorizado', '<PtoVta>1</PtoVta><CbteTipo>1</CbteTipo><CbteNro>41</CbteNro>'));
        self::assertSame(41, $this->wsfe->getLastVoucher(1, 1));

        $transport = (new MockTransport())->withLogin()->on('FECompUltimoAutorizado', MockTransport::wsfe('FECompUltimoAutorizado', '<PtoVta>9</PtoVta><CbteTipo>1</CbteTipo><CbteNro>0</CbteNro><Errors><Err><Code>10015</Code><Msg>Punto de venta no habilitado</Msg></Err></Errors>'));
        $wsfe = new WsfeService(new AuthManager(new Wsaa(TestCertificate::create(), Environment::HOMOLOGACION, $transport)), '20111111112', Environment::HOMOLOGACION, $transport);
        $this->expectException(ServiceException::class);
        $wsfe->getLastVoucher(9, 1);
    }

    public function testExpiredTokenIsRenewedAndRetried(): void
    {
        $this->transport
            ->on('FECompUltimoAutorizado', MockTransport::wsfe('FECompUltimoAutorizado', '<CbteNro>0</CbteNro><Errors><Err><Code>600</Code><Msg>ValidacionDeToken: token vencido</Msg></Err></Errors>'))
            ->on('FECompUltimoAutorizado', MockTransport::wsfe('FECompUltimoAutorizado', '<CbteNro>7</CbteNro>'));

        self::assertSame(7, $this->wsfe->getLastVoucher(1, 1));
        self::assertCount(2, $this->transport->requestsFor('loginCms'));
    }

    public function testGetVoucherReturnsNullWhenMissing(): void
    {
        $this->transport->on('FECompConsultar', MockTransport::wsfe('FECompConsultar', '<Errors><Err><Code>602</Code><Msg>Sin Resultados</Msg></Err></Errors>'));
        self::assertNull($this->wsfe->getVoucher(1, 1, 999));
    }

    public function testGetVoucher(): void
    {
        $this->transport->on('FECompConsultar', MockTransport::wsfe('FECompConsultar', '<ResultGet><Concepto>1</Concepto><DocTipo>80</DocTipo><DocNro>30500010912</DocNro>'
            . '<CbteDesde>5</CbteDesde><CbteHasta>5</CbteHasta><CbteFch>20261028</CbteFch><ImpTotal>121</ImpTotal><MonId>PES</MonId><MonCotiz>1</MonCotiz>'
            . '<CondicionIVAReceptorId>1</CondicionIVAReceptorId><Resultado>A</Resultado><CodAutorizacion>76123456789012</CodAutorizacion><EmisionTipo>CAE</EmisionTipo>'
            . '<FchVto>20261107</FchVto><FchProceso>20261028101010</FchProceso><PtoVta>1</PtoVta><CbteTipo>1</CbteTipo></ResultGet>'));
        $voucher = $this->wsfe->getVoucher(1, 1, 5);

        self::assertNotNull($voucher);
        self::assertSame(121.0, $voucher->getImpTotal());
        self::assertSame('76123456789012', $voucher->getCodigoAutorizacion());
        self::assertSame(1, $voucher->getCondicionIvaReceptor());
        self::assertSame('2026-10-28', $voucher->getFecha()->format('Y-m-d'));
    }

    public function testParamsAreNormalizedToLists(): void
    {
        $this->transport->on('FEParamGetPtosVenta', MockTransport::wsfe('FEParamGetPtosVenta', '<ResultGet><PtoVenta><Nro>1</Nro><EmisionTipo>CAE</EmisionTipo><Bloqueado>N</Bloqueado><FchBaja>NULL</FchBaja></PtoVenta></ResultGet>'));
        $points = $this->wsfe->getSalesPoints();
        self::assertCount(1, $points);
        self::assertSame('1', $points[0]['Nro']);

        $this->transport->on('FEParamGetCondicionIvaReceptor', MockTransport::wsfe('FEParamGetCondicionIvaReceptor', '<ResultGet><CondicionIvaReceptor><Id>1</Id><Desc>IVA Responsable Inscripto</Desc><Cmp_Clase>A/M/C</Cmp_Clase></CondicionIvaReceptor><CondicionIvaReceptor><Id>6</Id><Desc>Responsable Monotributo</Desc><Cmp_Clase>A/M/C</Cmp_Clase></CondicionIvaReceptor></ResultGet>'));
        self::assertCount(2, $this->wsfe->getReceptorVatConditions('A'));
        self::assertStringContainsString('<ClaseCmp>A</ClaseCmp>', $this->transport->requestsFor('FEParamGetCondicionIvaReceptor')[0]['body']);
    }

    public function testQuotation(): void
    {
        $this->transport->on('FEParamGetCotizacion', MockTransport::wsfe('FEParamGetCotizacion', '<ResultGet><MonId>DOL</MonId><MonCotiz>1050.5</MonCotiz><FchCotiz>20261028</FchCotiz></ResultGet>'));
        $q = $this->wsfe->getQuotation('DOL');
        self::assertSame(1050.5, $q['cotizacion']);
    }

    public function testCaeaRequest(): void
    {
        $this->transport->on('FECAEASolicitar', MockTransport::wsfe('FECAEASolicitar', '<ResultGet><CAEA>31234567890123</CAEA><Periodo>202611</Periodo><Orden>1</Orden>'
            . '<FchVigDesde>20261101</FchVigDesde><FchVigHasta>20261115</FchVigHasta><FchTopeInf>20261123</FchTopeInf><FchProceso>20261027</FchProceso></ResultGet>'));
        $caea = $this->wsfe->requestCaea(202611, 1);
        self::assertSame('31234567890123', $caea->getCaea());
        self::assertSame('2026-11-23', $caea->getFechaTopeInformar()->format('Y-m-d'));
    }

    public function testCaeaDetailAppendsCaeaFields(): void
    {
        $invoice = Factory::facturaB()->setNumero(3)->setCaea('31234567890123')->setFechaHoraGeneracion(new \DateTimeImmutable('2026-11-02 10:11:12'));
        $detail = $this->wsfe->buildDetail($invoice, true);
        $keys = array_keys($detail);
        self::assertSame(['CAEA', 'CbteFchHsGen'], array_slice($keys, -2));
        self::assertSame('20261102101112', $detail['CbteFchHsGen']);
    }
}
