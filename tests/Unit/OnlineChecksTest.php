<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RoyaltyFusion\Afip\Calculator\InvoiceCalculator;
use RoyaltyFusion\Afip\Catalog\Moneda;
use RoyaltyFusion\Afip\Catalog\TipoComprobante;
use RoyaltyFusion\Afip\Catalog\TipoOpcional;
use RoyaltyFusion\Afip\Environment;
use RoyaltyFusion\Afip\Exception\ValidationException;
use RoyaltyFusion\Afip\Model\Sale\CbteAsociado;
use RoyaltyFusion\Afip\Model\Sale\Note;
use RoyaltyFusion\Afip\See;
use RoyaltyFusion\Afip\Service\WsfecredService;
use RoyaltyFusion\Afip\Support\Date;
use RoyaltyFusion\Afip\Tests\Support\Factory;
use RoyaltyFusion\Afip\Tests\Support\MockTransport;
use RoyaltyFusion\Afip\Tests\Support\TestCertificate;
use RoyaltyFusion\Afip\Validator\InvoiceValidator;
use RoyaltyFusion\Afip\Validator\ValidatorOptions;

final class OnlineChecksTest extends TestCase
{
    private MockTransport $transport;
    private See $see;

    protected function setUp(): void
    {
        $this->transport = (new MockTransport())->withLogin()
            ->on('FEParamGetPtosVenta', MockTransport::wsfe('FEParamGetPtosVenta', '<ResultGet/>'));
        $this->see = (new See(Environment::HOMOLOGACION))
            ->setCertificate(TestCertificate::create())
            ->setTransport($this->transport)
            ->setCheckLastVoucherDate(false);
    }

    private function codes(callable $send): array
    {
        try {
            $send();
            self::fail('Se esperaba ValidationException');
        } catch (ValidationException $e) {
            self::assertSame([], $this->transport->requestsFor('FECAESolicitar'));

            return array_map(static fn ($err): string => $err->getCode(), $e->getResult()->getErrors());
        }
    }

    private function obligation(string $obligado, string $monto): void
    {
        $this->transport->on('consultarMontoObligadoRecepcionRequest', MockTransport::java(WsfecredService::NAMESPACE, 'consultarMontoObligadoRecepcion',
            '<consultarMontoObligadoRecepcionReturn><obligado>' . $obligado . '</obligado><montoDesde>' . $monto . '</montoDesde></consultarMontoObligadoRecepcionReturn>'));
    }

    public function testFceRequiredForObligatedReceiver(): void
    {
        $this->see->setFceCheck(true);
        $this->obligation('S', '2000.00');
        self::assertContains('FCE_OBLIGATORIA', $this->codes(fn () => $this->see->send(Factory::facturaA()->setNumero(1))));
    }

    public function testFceBelowThresholdIsCommonInvoice(): void
    {
        $this->see->setFceCheck(true);
        $this->obligation('S', '5000000.00');
        $this->transport->on('FECAESolicitar', WsfeServiceTest::caeResponse(1));
        self::assertTrue($this->see->send(Factory::facturaA()->setNumero(1))->isSuccess());
    }

    public function testFceRejectedForNonObligatedReceiver(): void
    {
        $this->see->setFceCheck(true);
        $this->obligation('N', '0');
        $fce = Factory::facturaA()->setNumero(1)->setTipoComprobante(TipoComprobante::FCE_FACTURA_A)
            ->setFechaVencimientoPago(Date::today()->modify('+30 days'))
            ->addOpcional(TipoOpcional::FCE_CBU_EMISOR, '2850590940090418135201')
            ->addOpcional(TipoOpcional::FCE_TRANSFERENCIA, 'SCA');
        self::assertContains('FCE_RECEPTOR_NO_OBLIGADO', $this->codes(fn () => $this->see->send($fce)));
    }

    public function testQuotationTolerance(): void
    {
        $this->see->setQuotationTolerance(5.0);
        $this->transport->on('FEParamGetCotizacion', MockTransport::wsfe('FEParamGetCotizacion', '<ResultGet><MonId>DOL</MonId><MonCotiz>1000</MonCotiz><FchCotiz>20261028</FchCotiz></ResultGet>'));
        $invoice = Factory::facturaA()->setNumero(1)->setMoneda(Moneda::DOLAR, 1200)->setPagoMismaMoneda(false);
        self::assertContains('COTIZACION_FUERA_DE_RANGO', $this->codes(fn () => $this->see->send($invoice)));
    }

    public function testAssociatedVoucherMustExist(): void
    {
        $this->transport->on('FECompConsultar', MockTransport::wsfe('FECompConsultar', '<Errors><Err><Code>602</Code><Msg>Sin Resultados</Msg></Err></Errors>'));
        $note = (new Note())->setTipoComprobante(TipoComprobante::NOTA_CREDITO_A)->setNumero(1)
            ->setCompany(Factory::company())->setClient(Factory::clientRi())
            ->addDetail(Factory::detail('Devolución', 1, 100))
            ->addCbteAsociado(new CbteAsociado(TipoComprobante::FACTURA_A, 1, 999));
        (new InvoiceCalculator())->calculate($note);
        self::assertContains('CBTE_ASOC_INEXISTENTE', $this->codes(fn () => $this->see->send($note)));
    }

    public function testPadronCheck(): void
    {
        $this->see->setPadronCheck(true);
        $this->transport->on('getPersona_v2', MockTransport::envelope('<ns2:getPersona_v2Response xmlns:ns2="http://a5.soap.ws.server.puc.sr/"><personaReturn><datosGenerales>'
            . '<estadoClave>ACTIVO</estadoClave><idPersona>30500010912</idPersona><razonSocial>X</razonSocial><tipoPersona>JURIDICA</tipoPersona></datosGenerales>'
            . '<datosMonotributo><impuesto><idImpuesto>20</idImpuesto></impuesto></datosMonotributo></personaReturn></ns2:getPersona_v2Response>'));
        self::assertContains('CONDICION_IVA_PADRON', $this->codes(fn () => $this->see->send(Factory::facturaA()->setNumero(1))));
    }

    public function testTotalZeroOption(): void
    {
        $invoice = Factory::facturaC()->setDetails([])->setImpNeto(0)->setImpTotal(0);
        self::assertTrue((new InvoiceValidator())->validate($invoice)->isValid());
        self::assertFalse((new InvoiceValidator(new ValidatorOptions(totalCeroEsError: true)))->validate($invoice)->isValid());
    }
}
