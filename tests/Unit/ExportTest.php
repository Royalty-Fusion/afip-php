<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RoyaltyFusion\Afip\Catalog\Exportacion;
use RoyaltyFusion\Afip\Catalog\TipoComprobante;
use RoyaltyFusion\Afip\Environment;
use RoyaltyFusion\Afip\Model\Export\ExportInvoice;
use RoyaltyFusion\Afip\Model\Export\ExportItem;
use RoyaltyFusion\Afip\Model\Export\Permiso;
use RoyaltyFusion\Afip\See;
use RoyaltyFusion\Afip\Support\Date;
use RoyaltyFusion\Afip\Tests\Support\Factory;
use RoyaltyFusion\Afip\Tests\Support\MockTransport;
use RoyaltyFusion\Afip\Tests\Support\TestCertificate;
use RoyaltyFusion\Afip\Validator\ExportInvoiceValidator;

final class ExportTest extends TestCase
{
    public static function invoice(): ExportInvoice
    {
        return (new ExportInvoice())
            ->setTipoComprobante(TipoComprobante::FACTURA_EXPORTACION)
            ->setPuntoVenta(3)
            ->setFechaEmision(Date::today())
            ->setTipoExportacion(Exportacion::EXPORTACION_BIENES)
            ->setPermisoExistente('N')
            ->setPaisDestino(222)
            ->setCompany(Factory::company())
            ->setCliente('IMPORTADORA DEL PERU SAC')
            ->setCuitPaisCliente('50000000016')
            ->setDomicilioCliente('Av. Arequipa 123, Lima')
            ->setIdImpositivo('20123456789')
            ->setMoneda('DOL', 1050.5)
            ->setPagoMismaMoneda(true)
            ->setIncoterms('FOB')
            ->setIdioma(Exportacion::IDIOMA_ESPANOL)
            ->addItem((new ExportItem())->setCodigo('V1')->setDescripcion('Vino Malbec')->setCantidad(120)->setUnidad(7)->setPrecioUnitario(12.5))
            ->addItem((new ExportItem())->setDescripcion('Descuento')->setCantidad(0)->setUnidad(Exportacion::UMED_BONIFICACION)->setPrecioUnitario(0)->setTotal(100));
    }

    public function testValidExport(): void
    {
        $invoice = self::invoice();
        $result = (new ExportInvoiceValidator())->validate($invoice);
        self::assertTrue($result->isValid(), implode("\n", array_map('strval', $result->getErrors())));
        self::assertSame(1400.0, $invoice->getImpTotal());
    }

    public function testExportValidationErrors(): void
    {
        $invoice = self::invoice()->setPermisoExistente('S')->setCliente('')->setCuitPaisCliente(null)->setIdImpositivo(null)->setIncoterms('XYZ')->setImpTotal(5);
        $result = (new ExportInvoiceValidator())->validate($invoice);
        $codes = array_map(static fn ($e): string => $e->getCode(), $result->getErrors());
        foreach (['PERMISOS_REQUERIDOS', 'CLIENTE_REQUERIDO', 'CLIENTE_ID_REQUERIDO', 'INCOTERMS_INVALIDO', 'TOTAL_NO_CUADRA'] as $code) {
            self::assertContains($code, $codes);
        }

        $services = self::invoice()->setTipoExportacion(Exportacion::SERVICIOS);
        $codes = array_map(static fn ($e): string => $e->getCode(), (new ExportInvoiceValidator())->validate($services)->getErrors());
        self::assertContains('PERMISO_EXISTENTE_NO_CORRESPONDE', $codes);
        self::assertContains('FECHA_PAGO_REQUERIDA', $codes);

        $note = self::invoice()->setTipoComprobante(TipoComprobante::NOTA_CREDITO_EXPORTACION)->setPermisoExistente(null);
        $codes = array_map(static fn ($e): string => $e->getCode(), (new ExportInvoiceValidator())->validate($note)->getErrors());
        self::assertContains('CBTE_ASOC_REQUERIDO', $codes);
        self::assertContains('CAN_MIS_MON_EXT_NO_CORRESPONDE', $codes);
    }

    public function testSendExport(): void
    {
        $transport = (new MockTransport())->withLogin()
            ->on('FEXGetLast_CMP', MockTransport::wsfex('FEXGetLast_CMP', '<FEXResult_LastCMP><Cbte_nro>9</Cbte_nro><Cbte_fecha>20261020</Cbte_fecha></FEXResult_LastCMP><FEXErr><ErrCode>0</ErrCode><ErrMsg>OK</ErrMsg></FEXErr>'))
            ->on('FEXGetLast_ID', MockTransport::wsfex('FEXGetLast_ID', '<FEXResultGet><Id>99</Id></FEXResultGet><FEXErr><ErrCode>0</ErrCode><ErrMsg>OK</ErrMsg></FEXErr>'))
            ->on('FEXAuthorize', MockTransport::wsfex('FEXAuthorize', '<FEXResultAuth><Id>100</Id><Cuit>20111111112</Cuit><Cbte_tipo>19</Cbte_tipo><Punto_vta>3</Punto_vta>'
                . '<Cbte_nro>10</Cbte_nro><Cae>71234567890123</Cae><Fch_venc_Cae>20261108</Fch_venc_Cae><Fch_cbte>20261029</Fch_cbte><Resultado>A</Resultado>'
                . '<Reproceso>N</Reproceso><Motivos_Obs></Motivos_Obs></FEXResultAuth><FEXErr><ErrCode>0</ErrCode><ErrMsg>OK</ErrMsg></FEXErr><FEXEvents><EventCode>0</EventCode><EventMsg>Ok</EventMsg></FEXEvents>'));
        $see = (new See(Environment::HOMOLOGACION))->setCertificate(TestCertificate::create())->setTransport($transport);

        $invoice = self::invoice();
        $result = $see->sendExport($invoice);

        self::assertTrue($result->isSuccess(), $result->getErrorMessage());
        self::assertSame('71234567890123', $invoice->getCae());
        self::assertSame(10, $invoice->getNumero());
        self::assertSame(100, $invoice->getId());

        $body = $transport->requestsFor('FEXAuthorize')[0]['body'];
        self::assertStringContainsString('<Moneda_ctz>1050.5</Moneda_ctz><CanMisMonExt>S</CanMisMonExt>', $body);
        self::assertStringContainsString('<Pro_umed>99</Pro_umed>', $body);
        self::assertStringContainsString('<Imp_total>1400.00</Imp_total>', $body);
        $last = $transport->requestsFor('FEXGetLast_CMP')[0]['body'];
        self::assertStringContainsString('<Auth><Token>TOKEN</Token><Sign>SIGN</Sign><Cuit>20111111112</Cuit><Pto_venta>3</Pto_venta><Cbte_Tipo>19</Cbte_Tipo></Auth>', $last);
    }

    public function testRejectedExport(): void
    {
        $transport = (new MockTransport())->withLogin()
            ->on('FEXAuthorize', MockTransport::wsfex('FEXAuthorize', '<FEXErr><ErrCode>1560</ErrCode><ErrMsg>Permiso inexistente</ErrMsg></FEXErr>'));
        $see = (new See(Environment::HOMOLOGACION))->setCertificate(TestCertificate::create())->setTransport($transport);
        $invoice = self::invoice()->setId(5)->setNumero(5)->setPermisoExistente('S')->addPermiso(new Permiso('16001EC01000001A', 222));

        $result = $see->sendExport($invoice);
        self::assertFalse($result->isSuccess());
        self::assertStringContainsString('1560: Permiso inexistente', $result->getErrorMessage());
    }
}
