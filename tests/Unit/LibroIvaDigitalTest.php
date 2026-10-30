<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RoyaltyFusion\Afip\Calculator\InvoiceCalculator;
use RoyaltyFusion\Afip\Catalog\AlicuotaIva;
use RoyaltyFusion\Afip\Catalog\TipoComprobante;
use RoyaltyFusion\Afip\Catalog\TipoDocumento;
use RoyaltyFusion\Afip\Catalog\TipoTributo;
use RoyaltyFusion\Afip\Exception\ValidationException;
use RoyaltyFusion\Afip\Libro\CodigoOperacion;
use RoyaltyFusion\Afip\Libro\LibroIvaDigital;
use RoyaltyFusion\Afip\Libro\RegistroFactory;
use RoyaltyFusion\Afip\Libro\RegistroIva;
use RoyaltyFusion\Afip\Model\Client;
use RoyaltyFusion\Afip\Model\Export\ExportInvoice;
use RoyaltyFusion\Afip\Model\Sale\Tributo;
use RoyaltyFusion\Afip\Tests\Support\Factory;

final class LibroIvaDigitalTest extends TestCase
{
    private static function fecha(string $fecha = '2026-10-15'): \DateTimeImmutable
    {
        return new \DateTimeImmutable($fecha);
    }

    private static function compraA(int $numero = 2424): RegistroIva
    {
        return (new RegistroIva(self::fecha('2026-10-20'), TipoComprobante::FACTURA_A, 5, $numero))
            ->setContraparte(TipoDocumento::CUIT, '30-50001091-2', 'BANCO DE LA NACION ARGENTINA')
            ->addAlicuota(AlicuotaIva::IVA_21, 1000.0, 210.0)
            ->addAlicuota(AlicuotaIva::IVA_10_5, 400.0, 42.0)
            ->setPercepcionIva(30.0)
            ->setPercepcionesIibb(25.0)
            ->setTotal(1707.0)
            ->setCodigoAutorizacion('69333775104382');
    }

    /**
     * @param array<int, array{0: int, 1: int}> $campos posición (1) y longitud de cada campo
     *
     * @return string[]
     */
    private static function cut(string $line, array $campos): array
    {
        return array_map(static fn (array $c): string => substr($line, $c[0] - 1, $c[1]), $campos);
    }

    public function testVentaFacturaAConTributos(): void
    {
        $invoice = Factory::facturaA()->setNumero(15)->setFechaEmision(self::fecha());
        $invoice->addDetail(Factory::detail('Servicio reducido', 1, 400, AlicuotaIva::IVA_10_5));
        $invoice->addTributo(new Tributo(TipoTributo::PERCEPCION_IIBB, 'Percepción IIBB CABA', 1000.0, 3.0, 30.0));
        (new InvoiceCalculator())->calculate($invoice);

        $libro = (new LibroIvaDigital())->setPeriodo(2026, 10)->addVenta($invoice);
        self::assertTrue($libro->validate()->isValid(), implode("\n", array_map('strval', $libro->validate()->getErrors())));

        $line = $libro->ventasComprobantes();
        self::assertSame(266, strlen($line));
        [$fecha, $tipo, $pv, $nro, $hasta, $docTipo, $docNro, $nombre, $total, , , , , $iibb, , , $moneda, $tc, $cant, $codigo, , $vto]
            = self::cut($line, [[1, 8], [9, 3], [12, 5], [17, 20], [37, 20], [57, 2], [59, 20], [79, 30], [109, 15], [124, 15], [139, 15], [154, 15], [169, 15], [184, 15], [199, 15], [214, 15], [229, 3], [232, 10], [242, 1], [243, 1], [244, 15], [259, 8]]);
        self::assertSame('20261015', $fecha);
        self::assertSame('001', $tipo);
        self::assertSame('00001', $pv);
        self::assertSame(str_pad('15', 20, '0', STR_PAD_LEFT), $nro);
        self::assertSame($nro, $hasta);
        self::assertSame('80', $docTipo);
        self::assertSame(str_pad('30500010912', 20, '0', STR_PAD_LEFT), $docNro);
        self::assertSame(str_pad('BANCO DE LA NACION ARGENTINA', 30), $nombre);
        self::assertSame(str_pad((string) (int) round($invoice->getImpTotal() * 100), 15, '0', STR_PAD_LEFT), $total);
        self::assertSame('000000000003000', $iibb);
        self::assertSame('PES', $moneda);
        self::assertSame('0001000000', $tc);
        self::assertSame('2', $cant);
        self::assertSame('0', $codigo);
        self::assertSame('20261015', $vto);

        $alicuotas = explode("\r\n", $libro->ventasAlicuotas());
        self::assertCount(2, $alicuotas);
        foreach ($alicuotas as $a) {
            self::assertSame(62, strlen($a));
            self::assertStringStartsWith('00100001' . str_pad('15', 20, '0', STR_PAD_LEFT), $a);
        }
        self::assertContains('0005', array_map(static fn (string $a): string => substr($a, 43, 4), $alicuotas));
        self::assertContains('0004', array_map(static fn (string $a): string => substr($a, 43, 4), $alicuotas));
    }

    public function testConsumidorFinalYFacturaC(): void
    {
        $b = (new RegistroIva(self::fecha(), TipoComprobante::FACTURA_B, 2, 100))
            ->setNumeroHasta(180)
            ->addAlicuota(AlicuotaIva::IVA_21, 10000.0, 2100.0)
            ->setTotal(12100.0);
        $c = (new RegistroIva(self::fecha('2026-10-16'), TipoComprobante::FACTURA_C, 3, 7))
            ->setContraparte(TipoDocumento::DNI, '30111222', 'JUAN PEREZ')
            ->setTotal(5000.0);

        $libro = (new LibroIvaDigital())->addVenta($b)->addVenta($c);
        $lines = explode("\r\n", $libro->ventasComprobantes());
        self::assertCount(2, $lines);
        self::assertSame(str_pad('180', 20, '0', STR_PAD_LEFT), substr($lines[0], 36, 20));
        self::assertSame('99' . str_repeat('0', 20) . str_pad('CONSUMIDOR FINAL', 30), substr($lines[0], 56, 52));
        self::assertSame('1', $lines[0][241]);
        self::assertSame('0', $lines[1][241], 'Un comprobante C no informa alícuotas');
        self::assertCount(1, explode("\r\n", $libro->ventasAlicuotas()));
    }

    public function testExentoLlevaAlicuotaCeroYCodigoE(): void
    {
        $r = (new RegistroIva(self::fecha(), TipoComprobante::FACTURA_A, 1, 9))
            ->setContraparte(TipoDocumento::CUIT, '30500010912', 'BANCO')
            ->setExento(800.0)
            ->setTotal(800.0);
        $libro = (new LibroIvaDigital())->addVenta($r);

        $line = $libro->ventasComprobantes();
        self::assertSame('1', $line[241]);
        self::assertSame(CodigoOperacion::EXENTO, $line[242]);
        self::assertSame('00100001' . str_pad('9', 20, '0', STR_PAD_LEFT) . str_repeat('0', 15) . '0003' . str_repeat('0', 15), $libro->ventasAlicuotas());
    }

    public function testExportacion(): void
    {
        $e = (new ExportInvoice())->setPuntoVenta(4)->setNumero(12)->setFechaEmision(self::fecha())
            ->setCliente('ACME INC')->setCuitPaisCliente('50000000016')->setMoneda('DOL', 1050.5)->setCae('70000000000001')->setImpTotal(1500.0);

        $r = RegistroFactory::fromSale($e);
        self::assertSame(1500.0, $r->getExento());
        $libro = (new LibroIvaDigital())->addVenta($r);
        self::assertTrue($libro->validate()->isValid(), implode("\n", array_map('strval', $libro->validate()->getErrors())));
        $line = $libro->ventasComprobantes();
        self::assertSame('019', substr($line, 8, 3));
        self::assertSame('DOL1050500000', substr($line, 228, 13));
        self::assertSame('X', $line[242]);
        self::assertSame('00000000', substr($line, 258, 8));
    }

    public function testCompras(): void
    {
        $libro = (new LibroIvaDigital('20111111112'))->setPeriodo(2026, 10)->addCompra(self::compraA());
        self::assertTrue($libro->validate()->isValid(), implode("\n", array_map('strval', $libro->validate()->getErrors())));

        $line = $libro->comprasComprobantes();
        self::assertSame(325, strlen($line));
        self::assertSame(str_repeat(' ', 16), substr($line, 36, 16), 'Sin despacho de importación');
        self::assertSame('000000000003000', substr($line, 149, 15), 'Percepción de IVA');
        self::assertSame('000000000002500', substr($line, 179, 15), 'Percepción de IIBB');
        self::assertSame('2', $line[237]);
        self::assertSame('000000000025200', substr($line, 239, 15), 'Crédito fiscal = IVA de las alícuotas');
        self::assertSame(str_repeat('0', 11) . str_repeat(' ', 30) . str_repeat('0', 15), substr($line, 269, 56));

        $alicuotas = explode("\r\n", $libro->comprasAlicuotas());
        self::assertCount(2, $alicuotas);
        self::assertSame(84, strlen($alicuotas[0]));
        self::assertSame('80' . str_pad('30500010912', 20, '0', STR_PAD_LEFT), substr($alicuotas[0], 28, 22));
    }

    public function testDespachoDeImportacionVaEnArchivosAparte(): void
    {
        $d = (new RegistroIva(self::fecha('2026-10-05'), LibroIvaDigital::DESPACHO_IMPORTACION))
            ->setDespachoImportacion('26001IC04000123A')
            ->setContraparte(TipoDocumento::CUIT, '33693450239', 'DIRECCION GENERAL DE ADUANAS')
            ->addAlicuota(AlicuotaIva::IVA_21, 5000.0, 1050.0)
            ->setTotal(6050.0);
        $libro = (new LibroIvaDigital())->addCompra($d)->addCompra(self::compraA());

        self::assertCount(1, explode("\r\n", $libro->comprasComprobantes()));
        $impo = $libro->importacionesComprobantes();
        self::assertSame(325, strlen($impo));
        self::assertSame('26001IC04000123A', substr($impo, 36, 16));
        self::assertSame('X', $impo[238]);
        self::assertSame('26001IC04000123A' . '000000000500000' . '0005' . '000000000105000', $libro->importacionesAlicuotas());
        self::assertSame(2, count(explode("\r\n", $libro->comprasAlicuotas())));

        $files = $libro->files();
        self::assertSame(['LIBRO_IVA_DIGITAL_COMPRAS_CBTE.txt', 'LIBRO_IVA_DIGITAL_COMPRAS_ALICUOTAS.txt', 'LIBRO_IVA_DIGITAL_IMPORTACIONES_CBTE.txt', 'LIBRO_IVA_DIGITAL_IMPORTACIONES_ALICUOTAS.txt'], array_keys($files));
    }

    public function testCompraCSinDesglose(): void
    {
        $c = (new RegistroIva(self::fecha(), TipoComprobante::FACTURA_C, 2, 55))
            ->setContraparte(TipoDocumento::CUIT, '20111111112', 'PROVEEDOR MONOTRIBUTO')
            ->setTotal(3500.0);
        $libro = (new LibroIvaDigital())->addCompra($c);
        self::assertTrue($libro->validate()->isValid());
        $line = $libro->comprasComprobantes();
        self::assertSame('0', $line[237]);
        self::assertSame(str_repeat('0', 15), substr($line, 239, 15));
        self::assertSame('', $libro->comprasAlicuotas());
    }

    public function testValidaciones(): void
    {
        $libro = (new LibroIvaDigital())->setPeriodo(2026, 10)
            ->addVenta((new RegistroIva(self::fecha(), TipoComprobante::FACTURA_A, 1, 1))
                ->setContraparte(TipoDocumento::DNI, '30111222', 'X')
                ->addAlicuota(AlicuotaIva::IVA_21, 100.0, 21.0)
                ->addAlicuota(AlicuotaIva::IVA_21, 100.0, 21.0)
                ->setTotal(500.0))
            ->addVenta((new RegistroIva(self::fecha('2026-09-30'), TipoComprobante::FACTURA_B, 1, 1))->setTotal(0.0))
            ->addVenta((new RegistroIva(self::fecha(), TipoComprobante::FACTURA_C, 3, 4))->addAlicuota(5, 10, 2.1)->setTotal(12.1))
            ->addCompra((new RegistroIva(self::fecha('2026-11-02'), TipoComprobante::FACTURA_B, 1, 3))->setTotal(121.0)->addAlicuota(5, 100, 21))
            ->addCompra(self::compraA()->setCodigoOperacion('Q'))
            ->addCompra(self::compraA()->setCreditoFiscalComputable(999.0));

        $result = $libro->validate();
        foreach ([
            'LIBRO_COMPRADOR_CUIT', 'LIBRO_ALICUOTA_REPETIDA', 'LIBRO_TOTAL_NO_CUADRA', 'LIBRO_FECHA_ANTERIOR',
            'LIBRO_ALICUOTAS_NO_CORRESPONDEN', 'LIBRO_FECHA_POSTERIOR', 'LIBRO_VENDEDOR_SIN_DOC', 'LIBRO_VENDEDOR_SIN_NOMBRE',
            'LIBRO_CODIGO_OPERACION', 'LIBRO_CREDITO_EXCEDE', 'LIBRO_DUPLICADO',
        ] as $code) {
            self::assertTrue($result->hasCode($code), $code . ' no detectado');
        }

        $this->expectException(ValidationException::class);
        $libro->files();
    }

    public function testFormatos(): void
    {
        self::assertSame('000000000123456', LibroIvaDigital::amount(1234.56));
        self::assertSame('-00000000123456', LibroIvaDigital::amount(-1234.56));
        self::assertSame('000000000000000', LibroIvaDigital::amount(-0.001));

        $texto = LibroIvaDigital::text('PEÑA & CÍA S.R.L. — COMPAÑÍA DE SERVICIOS', 30);
        self::assertSame(30, strlen($texto));
        self::assertSame("PE\xD1A & C\xCDA", substr($texto, 0, 10), 'Se codifica en ISO-8859-1');
    }

    public function testWriteTo(): void
    {
        $dir = sys_get_temp_dir() . '/afip-libro-' . bin2hex(random_bytes(4));
        $paths = (new LibroIvaDigital())->setPeriodo(2026, 10)->addCompra(self::compraA())->writeTo($dir);
        self::assertSame([$dir . '/LIBRO_IVA_DIGITAL_202610_COMPRAS_CBTE.txt', $dir . '/LIBRO_IVA_DIGITAL_202610_COMPRAS_ALICUOTAS.txt'], $paths);
        array_map('unlink', $paths);
        rmdir($dir);
    }

    public function testFacturaDesdeClienteSinIdentificar(): void
    {
        $invoice = Factory::facturaA()->setTipoComprobante(TipoComprobante::FACTURA_B)->setNumero(3)
            ->setClient((new Client())->setTipoDoc(TipoDocumento::CONSUMIDOR_FINAL)->setNumDoc('0'));
        (new InvoiceCalculator())->calculate($invoice);
        $r = RegistroFactory::fromInvoice($invoice);
        self::assertSame(99, $r->getDocTipo());
        self::assertSame('0', $r->getDocNro());
        self::assertTrue((new LibroIvaDigital())->addVenta($r)->validate()->isValid());
    }
}
