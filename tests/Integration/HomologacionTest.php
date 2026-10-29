<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RoyaltyFusion\Afip\Auth\Certificate;
use RoyaltyFusion\Afip\Calculator\InvoiceCalculator;
use RoyaltyFusion\Afip\Catalog\AlicuotaIva;
use RoyaltyFusion\Afip\Catalog\CondicionIva;
use RoyaltyFusion\Afip\Catalog\TipoComprobante;
use RoyaltyFusion\Afip\Catalog\TipoDocumento;
use RoyaltyFusion\Afip\Environment;
use RoyaltyFusion\Afip\Exception\TransportException;
use RoyaltyFusion\Afip\Exception\WsaaException;
use RoyaltyFusion\Afip\Model\Client;
use RoyaltyFusion\Afip\Model\Company;
use RoyaltyFusion\Afip\Model\Sale\CbteAsociado;
use RoyaltyFusion\Afip\Model\Sale\Invoice;
use RoyaltyFusion\Afip\Model\Sale\Note;
use RoyaltyFusion\Afip\Model\Sale\SaleDetail;
use RoyaltyFusion\Afip\See;
use RoyaltyFusion\Afip\Soap\CurlTransport;
use RoyaltyFusion\Afip\Soap\SoapTransportInterface;
use RoyaltyFusion\Afip\Support\Date;

/**
 * Pruebas reales contra ARCA homologación. Se ejecutan solo con certificado:
 *
 *   AFIP_HOMO_CERT=/ruta/cert.crt (o el PEM)   AFIP_HOMO_KEY=/ruta/clave.key (o el PEM)
 *   AFIP_HOMO_CUIT=20123456789                  AFIP_HOMO_PTOVTA=1
 *   [AFIP_HOMO_PASSPHRASE]  [AFIP_HOMO_RECEPTOR=30000000007]  [AFIP_HOMO_CACHE=/dir/persistente]
 *
 *   vendor/bin/phpunit --testsuite homologacion
 *
 * El certificado debe estar autorizado (WSASS) para wsfe y, para la prueba de padrón,
 * para ws_sr_constancia_inscripcion. Los tickets se guardan en AFIP_HOMO_CACHE para no
 * pedir uno nuevo en cada corrida (ARCA lo rechazaría durante 12 h).
 */
final class HomologacionTest extends TestCase
{
    private static ?Certificate $certificate = null;

    public static function setUpBeforeClass(): void
    {
        $cert = getenv('AFIP_HOMO_CERT');
        $key = getenv('AFIP_HOMO_KEY');
        if (!$cert || !$key || !getenv('AFIP_HOMO_CUIT') || !getenv('AFIP_HOMO_PTOVTA')) {
            return;
        }
        $passphrase = getenv('AFIP_HOMO_PASSPHRASE') ?: null;
        self::$certificate = str_contains($cert, '-----BEGIN')
            ? Certificate::fromStrings($cert, $key, $passphrase)
            : Certificate::fromFiles($cert, $key, $passphrase);
    }

    protected function setUp(): void
    {
        if (self::$certificate === null) {
            self::markTestSkipped('Pruebas de homologación desactivadas: defina AFIP_HOMO_CERT, AFIP_HOMO_KEY, AFIP_HOMO_CUIT y AFIP_HOMO_PTOVTA.');
        }
    }

    private static function see(?SoapTransportInterface $transport = null): See
    {
        $see = See::fromConfig([
            'certificate' => self::$certificate,
            'cuit' => (string) getenv('AFIP_HOMO_CUIT'),
            'environment' => Environment::HOMOLOGACION,
            'cache_dir' => getenv('AFIP_HOMO_CACHE') ?: sys_get_temp_dir() . '/afip-php-homologacion',
        ]);
        if ($transport !== null) {
            $see->setTransport($transport);
        }

        return $see;
    }

    private static function ptoVta(): int
    {
        return (int) getenv('AFIP_HOMO_PTOVTA');
    }

    private static function company(int $condicion): Company
    {
        return (new Company())->setCuit((string) getenv('AFIP_HOMO_CUIT'))->setRazonSocial('PRUEBA HOMOLOGACION')->setCondicionIva($condicion);
    }

    private static function client(string $clase): Client
    {
        if ($clase === 'A') {
            return (new Client())->setTipoDoc(TipoDocumento::CUIT)->setNumDoc(getenv('AFIP_HOMO_RECEPTOR') ?: '30000000007')
                ->setRazonSocial('RECEPTOR PRUEBA')->setCondicionIva(CondicionIva::RESPONSABLE_INSCRIPTO);
        }

        return Client::consumidorFinal();
    }

    private static function invoice(int $tipo, Invoice $invoice = new Invoice()): Invoice
    {
        $clase = (string) TipoComprobante::clase($tipo);
        $invoice->setTipoComprobante($tipo)
            ->setPuntoVenta(self::ptoVta())
            ->setFechaEmision(Date::today())
            ->setCompany(self::company($clase === 'C' ? CondicionIva::MONOTRIBUTO : CondicionIva::RESPONSABLE_INSCRIPTO))
            ->setClient(self::client($clase))
            ->addDetail((new SaleDetail())->setDescripcion('Prueba SDK')->setCantidad(1)->setPrecioUnitario(100)
                ->setAlicuotaIva($clase === 'C' ? null : AlicuotaIva::IVA_21));

        return (new InvoiceCalculator())->calculate($invoice);
    }

    public function testDummyAndLastVoucher(): void
    {
        $see = self::see();
        $dummy = $see->getWsfe()->dummy();
        self::assertSame('OK', $dummy['AppServer']);
        self::assertSame('OK', $dummy['DbServer']);
        self::assertSame('OK', $dummy['AuthServer']);
        self::assertGreaterThanOrEqual(0, $see->getWsfe()->getLastVoucher(self::ptoVta(), TipoComprobante::FACTURA_B));
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function clases(): array
    {
        return [
            'A' => [TipoComprobante::FACTURA_A, TipoComprobante::NOTA_CREDITO_A],
            'B' => [TipoComprobante::FACTURA_B, TipoComprobante::NOTA_CREDITO_B],
            'C' => [TipoComprobante::FACTURA_C, TipoComprobante::NOTA_CREDITO_C],
        ];
    }

    #[DataProvider('clases')]
    public function testInvoiceCreditNoteAndQuery(int $tipoFactura, int $tipoNota): void
    {
        $see = self::see();
        $factura = self::invoice($tipoFactura);
        $result = $see->send($factura);
        if (!$result->isSuccess() && $result->hasCode(10005)) {
            self::markTestSkipped('El CUIT de homologación no puede emitir esta clase: ' . $result->getErrorMessage());
        }
        self::assertTrue($result->isSuccess(), $result->getErrorMessage());
        self::assertMatchesRegularExpression('/^\d{14}$/', (string) $factura->getCae());

        $nota = self::invoice($tipoNota, new Note());
        $nota->addCbteAsociado(new CbteAsociado($tipoFactura, $factura->getPuntoVenta(), (int) $factura->getNumero(), (string) getenv('AFIP_HOMO_CUIT'), $factura->getFechaEmision()));
        $resultNota = $see->send($nota);
        self::assertTrue($resultNota->isSuccess(), $resultNota->getErrorMessage());

        $consulta = $see->getWsfe()->getVoucher($tipoFactura, $factura->getPuntoVenta(), (int) $factura->getNumero());
        self::assertNotNull($consulta);
        self::assertSame($factura->getCae(), $consulta->getCodigoAutorizacion());
        self::assertEqualsWithDelta($factura->getImpTotal(), $consulta->getImpTotal(), 0.001);

        $reconciled = $see->reconcile($nota);
        self::assertNotNull($reconciled);
        self::assertSame($nota->getCae(), $reconciled->getCae());
    }

    public function testRecoveryAfterSimulatedTimeout(): void
    {
        // El request llega a ARCA, pero se simula que la respuesta se perdió.
        $transport = new class () implements SoapTransportInterface {
            private CurlTransport $real;

            public function __construct()
            {
                $this->real = new CurlTransport();
            }

            public function send(string $url, string $body, array $headers): string
            {
                $response = $this->real->send($url, $body, $headers);
                if (str_contains($body, 'FECAESolicitar')) {
                    throw new TransportException('Timeout simulado después de enviar');
                }

                return $response;
            }
        };
        $see = self::see($transport);
        $invoice = self::invoice(TipoComprobante::FACTURA_B);

        $result = $see->send($invoice);

        self::assertTrue($result->isRecovered());
        self::assertTrue($result->isSuccess());
        self::assertMatchesRegularExpression('/^\d{14}$/', (string) $invoice->getCae());
    }

    public function testPadronA5(): void
    {
        try {
            $persona = self::see()->getPadronA5()->getPersona((string) getenv('AFIP_HOMO_CUIT'));
        } catch (WsaaException $e) {
            self::markTestSkipped('El certificado no está autorizado para ws_sr_constancia_inscripcion: ' . $e->getMessage());
        }
        self::assertNotNull($persona);
        self::assertNotSame('', $persona->getNombreCompleto());
    }
}
