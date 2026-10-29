<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RoyaltyFusion\Afip\Catalog\Moneda;
use RoyaltyFusion\Afip\Qr\QrGenerator;
use RoyaltyFusion\Afip\Report\HtmlReport;
use RoyaltyFusion\Afip\Tests\Support\Factory;

final class QrAndReportTest extends TestCase
{
    public function testQrDataFollowsSpecification(): void
    {
        $invoice = Factory::facturaA()->setNumero(94)->setCae('70417054367476')->setMoneda(Moneda::DOLAR, 65.0)->setPagoMismaMoneda(false);
        $qr = new QrGenerator();
        $url = $qr->buildUrl($invoice, '20111111112');
        $data = QrGenerator::decode($url);

        self::assertSame([
            'ver' => 1,
            'fecha' => $invoice->getFechaEmision()->format('Y-m-d'),
            'cuit' => 20111111112,
            'ptoVta' => 1,
            'tipoCmp' => 1,
            'nroCmp' => 94,
            'importe' => 2585.75,
            'moneda' => 'DOL',
            'ctz' => 65.0,
            'tipoDocRec' => 80,
            'nroDocRec' => 30500010912,
            'tipoCodAut' => 'E',
            'codAut' => 70417054367476,
        ], $data);
    }

    public function testQrOmitsAnonymousReceptorAndSupportsCaea(): void
    {
        $invoice = Factory::facturaB()->setNumero(1)->setCaea('31234567890123');
        $data = (new QrGenerator())->buildData($invoice, '20111111112');
        self::assertArrayNotHasKey('nroDocRec', $data);
        self::assertSame('A', $data['tipoCodAut']);
    }

    public function testQrRequiresAuthorization(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new QrGenerator())->buildUrl(Factory::facturaA(), '20111111112');
    }

    public function testHtmlReportFacturaA(): void
    {
        $invoice = Factory::facturaA()->setNumero(42)->setCae('76123456789012')->setCaeVencimiento(new \DateTimeImmutable('2026-11-08'));
        $html = (new HtmlReport())->render($invoice, ['condicionVenta' => 'Cuenta corriente']);

        self::assertStringContainsString('COD. 01', $html);
        self::assertStringContainsString('00001', $html);
        self::assertStringContainsString('00000042', $html);
        self::assertStringContainsString('76123456789012', $html);
        self::assertStringContainsString('IVA 21%', $html);
        self::assertStringContainsString('2.585,75', $html);
        self::assertStringContainsString('30-50001091-2', $html);
        self::assertStringContainsString('Cuenta corriente', $html);
        self::assertStringNotContainsString('Transparencia Fiscal', $html);
    }

    public function testHtmlReportFacturaBShowsTransparencyRegime(): void
    {
        $invoice = Factory::facturaB()->setNumero(7)->setCae('76123456789012')->setCaeVencimiento(new \DateTimeImmutable('2026-11-08'));
        $html = (new HtmlReport())->render($invoice);

        self::assertStringContainsString('Régimen de Transparencia Fiscal al Consumidor (Ley 27.743)', $html);
        self::assertStringContainsString('IVA Contenido: $ 21,00', $html);
        self::assertStringContainsString('A CONSUMIDOR FINAL', $html);
        self::assertStringContainsString('COD. 06', $html);
    }

    public function testHtmlReportExport(): void
    {
        $invoice = ExportTest::invoice()->setNumero(10)->setCae('71234567890123');
        $html = (new HtmlReport())->render($invoice);
        self::assertStringContainsString('COD. 19', $html);
        self::assertStringContainsString('IMPORTADORA DEL PERU SAC', $html);
        self::assertStringContainsString('1.400,00', $html);
    }
}
