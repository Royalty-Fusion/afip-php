<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RoyaltyFusion\Afip\Catalog\AlicuotaIva;
use RoyaltyFusion\Afip\Environment;
use RoyaltyFusion\Afip\Libro\LibroIvaDigital;
use RoyaltyFusion\Afip\Libro\MisComprobantesParser;
use RoyaltyFusion\Afip\Libro\RegistroFactory;
use RoyaltyFusion\Afip\See;
use RoyaltyFusion\Afip\Tests\Support\Factory;
use RoyaltyFusion\Afip\Tests\Support\MockTransport;
use RoyaltyFusion\Afip\Tests\Support\TestCertificate;

final class MisComprobantesTest extends TestCase
{
    private const CONSTATAR_OK = '<ComprobanteConstatarResponse xmlns="http://servicios1.afip.gob.ar/wscdc/"><ComprobanteConstatarResult>'
        . '<Resultado>A</Resultado><FchProceso>20261029152855</FchProceso></ComprobanteConstatarResult></ComprobanteConstatarResponse>';

    public function testFormatoConTotales(): void
    {
        $csv = "Mis Comprobantes Recibidos - CUIT 20111111112\n"
            . "Fecha;Tipo;Punto de Venta;Número Desde;Número Hasta;Cód. Autorización;Tipo Doc. Emisor;Nro. Doc. Emisor;Denominación Emisor;Tipo Cambio;Moneda;Imp. Neto Gravado;Imp. Neto No Gravado;Imp. Op. Exentas;Otros Tributos;IVA;Imp. Total\n"
            . "20/10/2026;1 - Factura A;5;2424;2424;69333775104382;CUIT;30500010912;BANCO DE LA NACION ARGENTINA;1;$;1.000,00;0,00;0,00;25,00;210,00;1.235,00\n"
            . "21/10/2026;3 - Nota de Crédito A;5;31;31;69333775104383;CUIT;30500010912;BANCO DE LA NACION ARGENTINA;1;$;-100,00;0;0;0;-21,00;-121,00\n"
            . "22/10/2026;11 - Factura C;2;55;55;71000000000001;CUIT;20111111112;PROVEEDOR MONOTRIBUTO;1;$;0;0;0;0;0;3.500,00\n"
            . ";;;;\n";

        $parser = new MisComprobantesParser();
        $compras = $parser->parse($csv);
        self::assertCount(3, $compras);
        self::assertSame([], $parser->getWarnings());

        $a = $compras[0];
        self::assertSame('2026-10-20', $a->getFecha()->format('Y-m-d'));
        self::assertSame(1, $a->getTipoComprobante());
        self::assertSame(5, $a->getPuntoVenta());
        self::assertSame(2424, $a->getNumero());
        self::assertSame(80, $a->getDocTipo());
        self::assertSame('30500010912', $a->getDocNro());
        self::assertSame('BANCO DE LA NACION ARGENTINA', $a->getNombre());
        self::assertSame('69333775104382', $a->getCodigoAutorizacion());
        self::assertSame(1235.0, $a->getTotal());
        self::assertSame(25.0, $a->getOtrosTributos());
        self::assertCount(1, $a->getAlicuotas());
        self::assertSame(AlicuotaIva::IVA_21, $a->getAlicuotas()[0]->getId(), 'La alícuota se deduce de IVA / neto');

        self::assertSame(121.0, $compras[1]->getTotal(), 'Las notas de crédito se registran en positivo');
        self::assertSame([], $compras[2]->getAlicuotas());

        $libro = (new LibroIvaDigital())->setPeriodo(2026, 10)->addCompras($compras);
        self::assertTrue($libro->validate()->isValid(), implode("\n", array_map('strval', $libro->validate()->getErrors())));
        self::assertCount(3, explode("\r\n", $libro->comprasComprobantes()));
    }

    public function testFormatoPorAlicuotaConComas(): void
    {
        $csv = "\xEF\xBB\xBF\"Fecha de Emisión\",\"Tipo de Comprobante\",\"Punto de Venta\",\"Número Desde\",\"Cód. Autorización\",\"Tipo Doc. Emisor\",\"Nro. Doc. Emisor\",\"Denominación Emisor\",\"Nro. Doc. Receptor\",\"Moneda\",\"Tipo Cambio\",\"Neto Grav. IVA 10,5%\",\"IVA 10,5%\",\"Neto Grav. IVA 21%\",\"IVA 21%\",\"Total IVA\",\"Imp. Total\"\n"
            . "2026-10-03,6 - Factura B,7,99,75000000000009,80,30500010912,\"PEREZ, JUAN\",20111111112,USD,1050.5,400.00,42.00,1000.00,210.00,252.00,1652.00\n";

        $r = (new MisComprobantesParser())->parse($csv)[0];
        self::assertSame('PEREZ, JUAN', $r->getNombre());
        self::assertSame('30500010912', $r->getDocNro(), 'Se ignora el documento del receptor');
        self::assertSame('DOL', $r->getMoneda());
        self::assertSame(1050.5, $r->getCotizacion());
        self::assertSame([[AlicuotaIva::IVA_10_5, 400.0, 42.0], [AlicuotaIva::IVA_21, 1000.0, 210.0]], array_map(
            static fn ($i): array => [$i->getId(), $i->getBaseImp(), $i->getImporte()],
            $r->getAlicuotas()
        ));
    }

    public function testEmitidosYAvisos(): void
    {
        $csv = "Fecha;Tipo;Punto de Venta;Número Desde;Número Hasta;Cód. Autorización;Tipo Doc. Receptor;Nro. Doc. Receptor;Denominación Receptor;Tipo Cambio;Moneda;Imp. Neto Gravado;Imp. Neto No Gravado;Imp. Op. Exentas;IVA;Imp. Total\n"
            . "01/10/2026;6;1;100;150;71000000000002;99;0;;1;$;10.000,00;0;0;1.650,00;11.650,00\n"
            . "fecha mal;6;1;151;151;;99;0;;1;$;0;0;0;0;0\n";

        $parser = new MisComprobantesParser();
        $ventas = $parser->parse($csv, MisComprobantesParser::EMITIDOS);
        self::assertCount(1, $ventas);
        self::assertSame(150, $ventas[0]->getNumeroHasta());
        self::assertSame(99, $ventas[0]->getDocTipo());
        self::assertSame([], $ventas[0]->getAlicuotas(), '16,5% no es una alícuota: no se adivina');
        self::assertCount(2, $parser->getWarnings());
        self::assertStringContainsString('complete las alícuotas a mano', $parser->getWarnings()[0]);
        self::assertStringContainsString('Línea 3 omitida', $parser->getWarnings()[1]);
    }

    public function testAliasYEncabezadoFaltante(): void
    {
        $csv = "Fecha;Tipo;Punto de Venta;Número Desde;Importe a pagar\n15/10/2026;1;1;1;121\n";
        $parser = (new MisComprobantesParser())->setAlias('total', 'Importe a pagar');
        self::assertSame(121.0, $parser->parse($csv)[0]->getTotal());

        $this->expectException(\InvalidArgumentException::class);
        (new MisComprobantesParser())->parse("Fecha;Tipo;Punto de Venta;Número Desde;Importe a pagar\n");
    }

    public function testNumeros(): void
    {
        self::assertSame(1234.56, MisComprobantesParser::number('1.234,56'));
        self::assertSame(1234.56, MisComprobantesParser::number('1,234.56'));
        self::assertSame(1234.56, MisComprobantesParser::number('$ 1234.56'));
        self::assertSame(-50.0, MisComprobantesParser::number('-50,00'));
        self::assertSame(1234567.0, MisComprobantesParser::number('1.234.567'));
        self::assertSame(0.0, MisComprobantesParser::number(''));
    }

    public function testConstatarCompraYQr(): void
    {
        $transport = (new MockTransport())->withLogin()->on('ComprobanteConstatar', MockTransport::envelope(self::CONSTATAR_OK));
        $see = (new See(Environment::HOMOLOGACION))->setCertificate(TestCertificate::create())->setCuit('20111111112')->setTransport($transport);

        $csv = "Fecha;Tipo;Punto de Venta;Número Desde;Cód. Autorización;Tipo Doc. Emisor;Nro. Doc. Emisor;Denominación Emisor;Imp. Total\n"
            . "20/10/2026;1;5;2424;69333775104382;80;30500010912;BANCO;1754,50\n";
        $compra = (new MisComprobantesParser())->parse($csv)[0];
        self::assertTrue($see->verifyReceived($compra)->isSuccess());
        self::assertStringContainsString(
            '<CuitEmisor>30500010912</CuitEmisor><PtoVta>5</PtoVta><CbteTipo>1</CbteTipo><CbteNro>2424</CbteNro><CbteFch>20261020</CbteFch><ImpTotal>1754.50</ImpTotal><CodAutorizacion>69333775104382</CodAutorizacion><DocTipoReceptor>80</DocTipoReceptor><DocNroReceptor>20111111112</DocNroReceptor>',
            $transport->requestsFor('ComprobanteConstatar')[0]['body']
        );

        $invoice = Factory::facturaA()->setNumero(77)->setCae('70417054367476');
        $url = $see->getQrUrl($invoice);
        self::assertSame('70417054367476', RegistroFactory::fromQr($url)->getCodigoAutorizacion());
        self::assertTrue($see->verifyQr($url)->isSuccess());
        $body = $transport->requestsFor('ComprobanteConstatar')[1]['body'];
        self::assertStringContainsString('<CuitEmisor>20111111112</CuitEmisor><PtoVta>1</PtoVta><CbteTipo>1</CbteTipo><CbteNro>77</CbteNro>', $body);
        self::assertStringContainsString('<DocTipoReceptor>80</DocTipoReceptor><DocNroReceptor>30500010912</DocNroReceptor>', $body);

        $this->expectException(\InvalidArgumentException::class);
        $see->verifyReceived($compra->setCodigoAutorizacion(null));
    }
}
