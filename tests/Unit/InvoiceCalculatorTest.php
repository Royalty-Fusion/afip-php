<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RoyaltyFusion\Afip\Calculator\InvoiceCalculator;
use RoyaltyFusion\Afip\Catalog\AlicuotaIva;
use RoyaltyFusion\Afip\Catalog\TipoComprobante;
use RoyaltyFusion\Afip\Catalog\TipoTributo;
use RoyaltyFusion\Afip\Model\Sale\Invoice;
use RoyaltyFusion\Afip\Model\Sale\SaleDetail;
use RoyaltyFusion\Afip\Model\Sale\Tributo;
use RoyaltyFusion\Afip\Tests\Support\Factory;

final class InvoiceCalculatorTest extends TestCase
{
    public function testFacturaAWithSeveralRates(): void
    {
        $invoice = Factory::facturaA();

        self::assertSame(2150.0, $invoice->getImpNeto());
        self::assertSame(435.75, $invoice->getImpIva()); // 2000*21% + 150*10.5%
        self::assertSame(2585.75, $invoice->getImpTotal());
        self::assertCount(2, $invoice->getIvas());
        self::assertSame(AlicuotaIva::IVA_10_5, $invoice->getIvas()[0]->getId());
        self::assertSame(15.75, $invoice->getIvas()[0]->getImporte());
    }

    public function testPriceIncludingVatKeepsFinalTotal(): void
    {
        $invoice = (new Invoice())->setTipoComprobante(TipoComprobante::FACTURA_B)
            ->addDetail(Factory::detail('Item', 1, 100)->setPrecioIncluyeIva(true));
        (new InvoiceCalculator())->calculate($invoice);

        self::assertSame(100.0, $invoice->getImpTotal());
        self::assertSame(82.64, $invoice->getImpNeto());
        self::assertSame(17.36, $invoice->getImpIva());
    }

    public function testExentoNoGravadoTributosAndDiscounts(): void
    {
        $invoice = (new Invoice())->setTipoComprobante(TipoComprobante::FACTURA_A)
            ->addDetail(Factory::detail('Gravado', 10, 10)->setBonificacionPorcentaje(10)) // 90
            ->addDetail((new SaleDetail())->setDescripcion('Exento')->setPrecioUnitario(50)->setExento())
            ->addDetail((new SaleDetail())->setDescripcion('No gravado')->setPrecioUnitario(25)->setNoGravado())
            ->addTributo(new Tributo(TipoTributo::PERCEPCION_IIBB, 'Percepción IIBB CABA', 90, 3, 2.7));
        (new InvoiceCalculator())->calculate($invoice);

        self::assertSame(90.0, $invoice->getImpNeto());
        self::assertSame(18.9, $invoice->getImpIva());
        self::assertSame(50.0, $invoice->getImpExento());
        self::assertSame(25.0, $invoice->getImpNoGravado());
        self::assertSame(2.7, $invoice->getImpTributos());
        self::assertSame(186.6, $invoice->getImpTotal());
    }

    public function testClaseCHasNoVat(): void
    {
        $invoice = Factory::facturaC();
        self::assertSame(5000.0, $invoice->getImpNeto());
        self::assertSame(0.0, $invoice->getImpIva());
        self::assertSame([], $invoice->getIvas());
        self::assertSame(5000.0, $invoice->getImpTotal());
    }

    public function testGravadoWithoutRateFails(): void
    {
        $invoice = (new Invoice())->setTipoComprobante(TipoComprobante::FACTURA_A)
            ->addDetail((new SaleDetail())->setDescripcion('X')->setPrecioUnitario(1));
        $this->expectException(\InvalidArgumentException::class);
        (new InvoiceCalculator())->calculate($invoice);
    }

    public function testManyItemsStayConsistentWithAfipRules(): void
    {
        $invoice = (new Invoice())->setTipoComprobante(TipoComprobante::FACTURA_B);
        for ($i = 1; $i <= 57; $i++) {
            $invoice->addDetail(Factory::detail('Item ' . $i, 1 + $i % 3, 10.33 * $i)->setPrecioIncluyeIva($i % 2 === 0));
        }
        (new InvoiceCalculator())->calculate($invoice);

        foreach ($invoice->getIvas() as $iva) {
            $expected = round($iva->getBaseImp() * AlicuotaIva::rate($iva->getId()) / 100, 2);
            self::assertEqualsWithDelta($expected, $iva->getImporte(), 0.01);
        }
        self::assertEqualsWithDelta(
            $invoice->getImpNeto() + $invoice->getImpIva(),
            $invoice->getImpTotal(),
            0.001
        );
    }
}
