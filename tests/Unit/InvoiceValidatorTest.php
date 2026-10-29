<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RoyaltyFusion\Afip\Calculator\InvoiceCalculator;
use RoyaltyFusion\Afip\Catalog\AlicuotaIva;
use RoyaltyFusion\Afip\Catalog\Concepto;
use RoyaltyFusion\Afip\Catalog\CondicionIva;
use RoyaltyFusion\Afip\Catalog\Moneda;
use RoyaltyFusion\Afip\Catalog\TipoComprobante;
use RoyaltyFusion\Afip\Catalog\TipoDocumento;
use RoyaltyFusion\Afip\Catalog\TipoOpcional;
use RoyaltyFusion\Afip\Catalog\TipoTributo;
use RoyaltyFusion\Afip\Model\Client;
use RoyaltyFusion\Afip\Model\Sale\CbteAsociado;
use RoyaltyFusion\Afip\Model\Sale\Comprador;
use RoyaltyFusion\Afip\Model\Sale\Invoice;
use RoyaltyFusion\Afip\Model\Sale\Iva;
use RoyaltyFusion\Afip\Model\Sale\Note;
use RoyaltyFusion\Afip\Model\Sale\Periodo;
use RoyaltyFusion\Afip\Model\Sale\Tributo;
use RoyaltyFusion\Afip\Support\Date;
use RoyaltyFusion\Afip\Tests\Support\Factory;
use RoyaltyFusion\Afip\Validator\InvoiceValidator;
use RoyaltyFusion\Afip\Validator\ValidationResult;
use RoyaltyFusion\Afip\Validator\ValidatorOptions;

final class InvoiceValidatorTest extends TestCase
{
    private InvoiceValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new InvoiceValidator();
    }

    private static function assertValid(ValidationResult $result): void
    {
        self::assertTrue($result->isValid(), implode("\n", array_map('strval', $result->getErrors())));
    }

    private static function assertHasError(ValidationResult $result, string $code): void
    {
        $codes = array_map(static fn ($e): string => $e->getCode(), $result->getErrors());
        self::assertContains($code, $codes, 'Errores: ' . implode(', ', $codes));
    }

    public function testValidInvoices(): void
    {
        self::assertValid($this->validator->validate(Factory::facturaA()));
        self::assertValid($this->validator->validate(Factory::facturaB()));
        self::assertValid($this->validator->validate(Factory::facturaC()));
    }

    public function testInvalidVoucherType(): void
    {
        $invoice = Factory::facturaA()->setTipoComprobante(19);
        self::assertHasError($this->validator->validate($invoice), 'CBTE_TIPO_INVALIDO');
    }

    public function testClaseARequiresCuit(): void
    {
        $invoice = Factory::facturaA()->setClient((new Client())->setTipoDoc(TipoDocumento::DNI)->setNumDoc('12345678')->setCondicionIva(CondicionIva::RESPONSABLE_INSCRIPTO));
        $result = $this->validator->validate($invoice);
        self::assertHasError($result, 'CLASE_A_REQUIERE_CUIT');
        self::assertHasError($result, 'CONDICION_IVA_REQUIERE_CUIT');
    }

    public function testInvalidCuitCheckDigit(): void
    {
        $invoice = Factory::facturaA();
        $invoice->getClient()->setNumDoc('30500010911');
        self::assertHasError($this->validator->validate($invoice), 'DOC_NRO_INVALIDO');
    }

    public function testCondicionIvaReceptorRequiredAndCoherentWithClass(): void
    {
        $invoice = Factory::facturaB();
        $invoice->getClient()->setCondicionIva(null);
        self::assertHasError($this->validator->validate($invoice), 'CONDICION_IVA_REQUERIDA');

        $invoice->getClient()->setCondicionIva(CondicionIva::RESPONSABLE_INSCRIPTO);
        self::assertHasError($this->validator->validate($invoice), 'CONDICION_IVA_CLASE');

        $invoice->getClient()->setCondicionIva(99);
        self::assertHasError($this->validator->validate($invoice), 'CONDICION_IVA_INVALIDA');

        $optional = new InvoiceValidator(new ValidatorOptions(requiereCondicionIvaReceptor: false));
        $invoice->getClient()->setCondicionIva(null);
        self::assertValid($optional->validate($invoice));
    }

    public function testConsumidorFinalThreshold(): void
    {
        $invoice = Factory::facturaB();
        $invoice->setDetails([Factory::detail('Auto', 1, 12_100_000)->setPrecioIncluyeIva(true)]);
        (new InvoiceCalculator())->calculate($invoice);
        self::assertHasError($this->validator->validate($invoice), 'CONSUMIDOR_FINAL_SIN_IDENTIFICAR');

        $invoice->setClient((new Client())->setTipoDoc(TipoDocumento::DNI)->setNumDoc('20123456')->setCondicionIva(CondicionIva::CONSUMIDOR_FINAL));
        self::assertValid($this->validator->validate($invoice));
    }

    public function testDocTipo99RequiresZero(): void
    {
        $invoice = Factory::facturaB();
        $invoice->getClient()->setNumDoc('123');
        self::assertHasError($this->validator->validate($invoice), 'DOC_NRO_INVALIDO');
    }

    public function testTotalsMustAddUp(): void
    {
        $invoice = Factory::facturaA()->setImpTotal(9999);
        self::assertHasError($this->validator->validate($invoice), 'TOTAL_NO_CUADRA');
    }

    public function testVatLinesMustMatch(): void
    {
        $invoice = Factory::facturaA()->setDetails([]);
        $invoice->setIvas([new Iva(AlicuotaIva::IVA_21, 100, 20), new Iva(AlicuotaIva::IVA_21, 50, 10.5)]);
        $invoice->setImpNeto(150)->setImpIva(30.5)->setImpTotal(180.5);
        $result = $this->validator->validate($invoice);
        self::assertHasError($result, 'IVA_MAL_CALCULADO');
        self::assertHasError($result, 'ALICUOTA_DUPLICADA');

        $invoice->setIvas([]);
        self::assertHasError($this->validator->validate($invoice), 'IVA_REQUERIDO');

        $invoice->setIvas([new Iva(AlicuotaIva::IVA_21, 100, 21)]);
        $result = $this->validator->validate($invoice);
        self::assertHasError($result, 'IVA_BASE_NO_CUADRA');
        self::assertHasError($result, 'IVA_TOTAL_NO_CUADRA');
    }

    public function testClaseCCannotHaveVat(): void
    {
        $invoice = Factory::facturaC();
        $invoice->setIvas([new Iva(AlicuotaIva::IVA_21, 5000, 1050)])->setImpIva(1050)->setImpTotal(6050);
        self::assertHasError($this->validator->validate($invoice), 'IVA_NO_CORRESPONDE');
    }

    public function testMonotributoCannotIssueA(): void
    {
        $invoice = Factory::facturaA()->setCompany(Factory::company(CondicionIva::MONOTRIBUTO));
        self::assertHasError($this->validator->validate($invoice), 'CLASE_NO_EMITIBLE');
    }

    public function testServicesRequireDates(): void
    {
        $invoice = Factory::facturaC()->setFechaServicioDesde(null)->setFechaVencimientoPago(null);
        $result = $this->validator->validate($invoice);
        self::assertHasError($result, 'FECHAS_SERVICIO_REQUERIDAS');
        self::assertHasError($result, 'FECHA_VTO_PAGO_REQUERIDA');

        $invoice = Factory::facturaC()->setPeriodoServicio(Date::today(), Date::today()->modify('-5 days'), Date::today()->modify('-1 day'));
        $result = $this->validator->validate($invoice);
        self::assertHasError($result, 'FECHAS_SERVICIO_INVERTIDAS');
        self::assertHasError($result, 'FECHA_VTO_PAGO_ANTERIOR');

        $invoice = Factory::facturaA()->setFechaServicioDesde(Date::today());
        self::assertHasError($this->validator->validate($invoice), 'FECHAS_SERVICIO_NO_CORRESPONDEN');
    }

    public function testDateWindow(): void
    {
        $invoice = Factory::facturaA()->setFechaEmision(Date::today()->modify('-6 days'));
        self::assertHasError($this->validator->validate($invoice), 'FECHA_FUERA_DE_RANGO');

        $invoice = Factory::facturaC()->setFechaEmision(Date::today()->modify('-9 days'));
        $invoice->setPeriodoServicio(Date::today()->modify('-20 days'), Date::today()->modify('-10 days'), Date::today());
        self::assertValid($this->validator->validate($invoice));
    }

    public function testNotesRequireAssociatedVoucherOfSameClass(): void
    {
        $note = (new Note())->setTipoComprobante(TipoComprobante::NOTA_CREDITO_A)
            ->setCompany(Factory::company())
            ->setClient(Factory::clientRi())
            ->addDetail(Factory::detail('Devolución', 1, 100));
        (new InvoiceCalculator())->calculate($note);
        self::assertHasError($this->validator->validate($note), 'CBTE_ASOC_REQUERIDO');

        $note->addCbteAsociado(new CbteAsociado(TipoComprobante::FACTURA_B, 1, 10));
        self::assertHasError($this->validator->validate($note), 'CBTE_ASOC_CLASE');

        $note->setCbtesAsociados([new CbteAsociado(TipoComprobante::FACTURA_A, 1, 10, '20111111112', Date::today())]);
        self::assertValid($this->validator->validate($note));

        $note->setPeriodoAsociado(new Periodo(Date::today()->modify('-30 days'), Date::today()));
        self::assertHasError($this->validator->validate($note), 'CBTE_ASOC_Y_PERIODO');

        $note->setCbtesAsociados([]);
        self::assertValid($this->validator->validate($note));
    }

    public function testNoteSetAfectado(): void
    {
        $factura = Factory::facturaA()->setNumero(15);
        $note = (new Note())->setTipoComprobante(TipoComprobante::NOTA_CREDITO_A)->setAfectado($factura);
        $asoc = $note->getCbtesAsociados()[0];
        self::assertSame(1, $asoc->getTipo());
        self::assertSame(15, $asoc->getNumero());
        self::assertSame('20111111112', $asoc->getCuit());
    }

    public function testFceRules(): void
    {
        $invoice = Factory::facturaA()->setTipoComprobante(TipoComprobante::FCE_FACTURA_A);
        $result = $this->validator->validate($invoice);
        self::assertHasError($result, 'FCE_VTO_PAGO_REQUERIDO');
        self::assertHasError($result, 'FCE_CBU_REQUERIDO');
        self::assertHasError($result, 'FCE_TRANSFERENCIA_REQUERIDA');

        $invoice->setFechaVencimientoPago(Date::today()->modify('+30 days'))
            ->addOpcional(TipoOpcional::FCE_CBU_EMISOR, '2850590940090418135201')
            ->addOpcional(TipoOpcional::FCE_TRANSFERENCIA, TipoOpcional::TRANSFERENCIA_SCA);
        self::assertValid($this->validator->validate($invoice));

        $invoice->setOpcionales([])->addOpcional(TipoOpcional::FCE_CBU_EMISOR, '2850590940090418135202')->addOpcional(27, 'XXX');
        $result = $this->validator->validate($invoice);
        self::assertHasError($result, 'FCE_CBU_INVALIDO');
        self::assertHasError($result, 'FCE_TRANSFERENCIA_INVALIDA');

        $note = Factory::facturaA()->setTipoComprobante(TipoComprobante::FCE_NOTA_CREDITO_A)
            ->addCbteAsociado(new CbteAsociado(TipoComprobante::FCE_FACTURA_A, 1, 5));
        $result = $this->validator->validate($note);
        self::assertHasError($result, 'FCE_ANULACION_REQUERIDA');
        self::assertHasError($result, 'FCE_CBTE_ASOC_CUIT');
        self::assertHasError($result, 'FCE_CBTE_ASOC_FECHA');

        $plain = Factory::facturaA()->addOpcional(TipoOpcional::FCE_ANULACION, 'S');
        self::assertHasError($this->validator->validate($plain), 'OPCIONAL_SOLO_FCE');
    }

    public function testForeignCurrency(): void
    {
        $invoice = Factory::facturaA()->setMoneda(Moneda::DOLAR, 0);
        self::assertHasError($this->validator->validate($invoice), 'COTIZACION_INVALIDA');

        $invoice->setCotizacion(1050.5)->setPagoMismaMoneda(true);
        self::assertValid($this->validator->validate($invoice));

        $invoice = Factory::facturaA()->setCotizacion(2);
        self::assertHasError($this->validator->validate($invoice), 'COTIZACION_INVALIDA');

        $invoice = Factory::facturaA()->setPagoMismaMoneda(false);
        $result = $this->validator->validate($invoice);
        self::assertValid($result);
        self::assertTrue($result->hasCode('CAN_MIS_MON_EXT_NO_CORRESPONDE'));

        $invoice = Factory::facturaA()->setMoneda('X$');
        self::assertHasError($this->validator->validate($invoice), 'MONEDA_INVALIDA');
    }

    public function testTributosMustMatch(): void
    {
        $invoice = Factory::facturaA()->addTributo(new Tributo(TipoTributo::PERCEPCION_IIBB, 'Percepción IIBB', 2150, 3, 64.5));
        self::assertHasError($this->validator->validate($invoice), 'TRIBUTOS_NO_CUADRAN');
        (new InvoiceCalculator())->calculate($invoice);
        self::assertValid($this->validator->validate($invoice));

        $invoice->addTributo(new Tributo(77, '', 0, 0, 0));
        $result = $this->validator->validate($invoice);
        self::assertHasError($result, 'TRIBUTO_INVALIDO');
        self::assertHasError($result, 'TRIBUTO_DESC_REQUERIDA');
    }

    public function testCompradoresMustSum100(): void
    {
        $invoice = Factory::facturaA()
            ->addComprador(new Comprador(TipoDocumento::CUIT, '30500010912', 50))
            ->addComprador(new Comprador(TipoDocumento::CUIT, '20267565393', 40));
        self::assertHasError($this->validator->validate($invoice), 'COMPRADORES_PORCENTAJE_TOTAL');
    }

    public function testItemsMustMatchAmounts(): void
    {
        $invoice = Factory::facturaA()->addDetail(Factory::detail('Extra', 1, 10));
        $result = $this->validator->validate($invoice);
        self::assertValid($result);
        self::assertTrue($result->hasCode('ITEMS_NO_CUADRAN'));

        $strict = new InvoiceValidator(new ValidatorOptions(itemsEstrictos: true));
        self::assertHasError($strict->validate($invoice), 'ITEMS_NO_CUADRAN');
    }

    public function testLargeAmountsAreNotFlaggedAsDecimals(): void
    {
        foreach ([1_234_567.89, 12_345_678.91, 987_654_321.07, 45_999_999.99] as $price) {
            $invoice = Factory::facturaA()->setDetails([Factory::detail('Item', 1, $price)]);
            (new InvoiceCalculator())->calculate($invoice);
            self::assertValid($this->validator->validate($invoice));
        }
    }

    public function testMoreThanTwoDecimals(): void
    {
        $invoice = Factory::facturaC()->setDetails([])->setImpNeto(10.123)->setImpTotal(10.123);
        self::assertHasError($this->validator->validate($invoice), 'IMPORTE_DECIMALES');
    }

    public function testRangeOnlyForConsumidorFinal(): void
    {
        $invoice = Factory::facturaA()->setNumero(1)->setNumeroHasta(5);
        self::assertHasError($this->validator->validate($invoice), 'RANGO_NO_PERMITIDO');

        $invoice = Factory::facturaB()->setNumero(1)->setNumeroHasta(5);
        self::assertValid($this->validator->validate($invoice));
    }

    public function testBatchMustBeHomogeneousAndConsecutive(): void
    {
        $a = Factory::facturaB()->setNumero(10);
        $b = Factory::facturaB()->setNumero(12);
        self::assertHasError($this->validator->validateBatch([$a, $b]), 'LOTE_NO_CORRELATIVO');

        $c = Factory::facturaA()->setNumero(11);
        self::assertHasError($this->validator->validateBatch([$a, $c]), 'LOTE_HETEROGENEO');
    }

    public function testCaeaMode(): void
    {
        $invoice = Factory::facturaB()->setNumero(5)->setFechaEmision(Date::today()->modify('-20 days'));
        $result = $this->validator->validate($invoice, InvoiceValidator::MODE_CAEA);
        self::assertHasError($result, 'CAEA_INVALIDO');
        self::assertFalse($result->hasCode('FECHA_FUERA_DE_RANGO'));

        $invoice->setCaea('21234567890123')->setFechaHoraGeneracion(new \DateTimeImmutable());
        self::assertValid($this->validator->validate($invoice, InvoiceValidator::MODE_CAEA));
    }

    public function testMissingClient(): void
    {
        $invoice = (new Invoice())->setTipoComprobante(TipoComprobante::FACTURA_B)->setConcepto(Concepto::PRODUCTOS);
        self::assertHasError($this->validator->validate($invoice), 'RECEPTOR_REQUERIDO');
    }
}
