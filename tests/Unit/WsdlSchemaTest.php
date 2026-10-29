<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RoyaltyFusion\Afip\Calculator\InvoiceCalculator;
use RoyaltyFusion\Afip\Model\Client;
use RoyaltyFusion\Afip\Model\Export\ExportCbteAsociado;
use RoyaltyFusion\Afip\Model\Export\Permiso;
use RoyaltyFusion\Afip\Model\Sale\BonoFiscalInvoice;
use RoyaltyFusion\Afip\Model\Sale\CbteAsociado;
use RoyaltyFusion\Afip\Model\Sale\Comprador;
use RoyaltyFusion\Afip\Model\Sale\DatoAdicional;
use RoyaltyFusion\Afip\Model\Sale\FormaPago;
use RoyaltyFusion\Afip\Model\Sale\Opcional;
use RoyaltyFusion\Afip\Model\Sale\Periodo;
use RoyaltyFusion\Afip\Model\Sale\SaleDetail;
use RoyaltyFusion\Afip\Model\Sale\Tributo;
use RoyaltyFusion\Afip\Model\Sale\TurismoInvoice;
use RoyaltyFusion\Afip\See;
use RoyaltyFusion\Afip\Service\WsfecredService;
use RoyaltyFusion\Afip\Soap\SoapTransportInterface;
use RoyaltyFusion\Afip\Tests\Support\Factory;
use RoyaltyFusion\Afip\Tests\Support\MockTransport;
use RoyaltyFusion\Afip\Tests\Support\TestCertificate;

/**
 * Valida contra el esquema XSD de los WSDL oficiales de ARCA todos los requests que arma el
 * SDK (orden de elementos, obligatorios, tipos y restricciones).
 */
final class WsdlSchemaTest extends TestCase
{
    private const DIR = __DIR__ . '/../Fixtures/wsdl/';

    /** URL del servicio => archivo WSDL */
    private const SERVICES = [
        'LoginCms' => 'wsaa.wsdl',
        'wsfev1' => 'wsfev1.wsdl',
        'wsfexv1' => 'wsfexv1.wsdl',
        'wsct' => 'wsct.wsdl',
        'wsfecred' => 'wsfecred.wsdl',
        'personaServiceA5' => 'padron_a5.wsdl',
        'personaServiceA13' => 'padron_a13.wsdl',
        'WSCDC' => 'wscdc.wsdl',
        'wsmtxca' => 'wsmtxca.wsdl',
        'wsbfev1' => 'wsbfev1.wsdl',
    ];

    public function testEveryRequestMatchesTheOfficialSchema(): void
    {
        $requests = $this->generateRequests();
        self::assertGreaterThan(120, count($requests));

        $schemas = [];
        $failures = [];
        $covered = [];
        foreach ($requests as [$url, $body]) {
            $service = null;
            foreach (array_keys(self::SERVICES) as $key) {
                if (stripos($url, $key) !== false) {
                    $service = $key;
                }
            }
            self::assertNotNull($service, 'URL sin esquema: ' . $url);
            $schemas[$service] ??= self::schema($service);

            $doc = new \DOMDocument();
            $doc->loadXML($body);
            $soapBody = $doc->getElementsByTagNameNS('http://schemas.xmlsoap.org/soap/envelope/', 'Body')->item(0);
            $operation = null;
            foreach ($soapBody->childNodes as $child) {
                if ($child instanceof \DOMElement) {
                    $operation = $child;
                    break;
                }
            }
            if ($operation === null) {
                continue; // operaciones sin parámetros (dummy de servicios Java)
            }
            $covered[$service][] = $operation->localName;
            $single = new \DOMDocument();
            $single->appendChild($single->importNode($operation, true));
            $previous = libxml_use_internal_errors(true);
            $valid = $single->schemaValidateSource($schemas[$service]);
            $errors = array_map(static fn (\LibXMLError $e): string => trim($e->message), libxml_get_errors());
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            if (!$valid) {
                $failures[] = sprintf('%s %s: %s', $service, $operation->localName, implode(' | ', $errors));
            }
        }

        self::assertSame([], $failures, implode("\n", $failures));
        self::assertCount(count(self::SERVICES), $covered, 'Todos los servicios deben estar cubiertos.');
    }

    private static function schema(string $service): string
    {
        $doc = new \DOMDocument();
        $doc->load(self::DIR . self::SERVICES[$service]);
        $xpath = new \DOMXPath($doc);
        $schemaNode = $xpath->query('//*[local-name()="schema" and namespace-uri()="http://www.w3.org/2001/XMLSchema"]')->item(0);
        $out = new \DOMDocument();
        $node = $out->importNode($schemaNode, true);
        $out->appendChild($node);
        foreach ([$doc->documentElement, $schemaNode] as $scope) {
            foreach ($xpath->query('namespace::*', $scope) as $ns) {
                $prefix = $ns->localName;
                if ($prefix !== 'xmlns' && $prefix !== 'xml' && !$node->hasAttribute('xmlns:' . $prefix)) {
                    $node->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:' . $prefix, $ns->nodeValue);
                }
            }
        }
        $xml = (string) $out->saveXML();

        // WSDL anteriores a la RG 5616: se agregan los campos nuevos según el manual vigente.
        return match ($service) {
            'wsmtxca' => (string) preg_replace(
                '#(<xsd:element[^>]*name="fechaHoraGen"[^>]*/>)#',
                '$1<xsd:element minOccurs="0" maxOccurs="1" name="cancelaEnMismaMonedaExtranjera" type="xsd:string"/><xsd:element minOccurs="0" maxOccurs="1" name="condicionIVAReceptor" type="xsd:short"/>',
                $xml,
                1
            ),
            'wsbfev1' => (string) preg_replace(
                '#(<s:element minOccurs="0" maxOccurs="1" name="Fecha_vto_pago" type="s:string" ?/>)#',
                '$1<s:element minOccurs="0" maxOccurs="1" name="CanMisMonExt" type="s:string"/><s:element minOccurs="0" maxOccurs="1" name="CondicionIVAReceptorId" type="s:int"/>',
                $xml,
                1
            ),
            default => $xml,
        };
    }

    /**
     * Ejecuta todas las operaciones del SDK con un transporte que solo registra los requests.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function generateRequests(): array
    {
        $transport = new class () implements SoapTransportInterface {
            /** @var array<int, array{0: string, 1: string}> */
            public array $requests = [];

            public function send(string $url, string $body, array $headers): string
            {
                $this->requests[] = [$url, $body];

                return MockTransport::operation($body) === 'loginCms' ? MockTransport::loginCms() : MockTransport::envelope('<x/>');
            }
        };
        $see = (new See())->setCertificate(TestCertificate::create())->setTransport($transport)
            ->setVerifySalesPoint(false)->setCheckLastVoucherDate(false)->setAutoRecover(false)->setCheckAssociatedVouchers(false);
        $try = static function (callable $f): void {
            try {
                $f();
            } catch (\Throwable) {
                // Las respuestas simuladas son vacías: solo interesan los requests.
            }
        };
        $calc = new InvoiceCalculator();
        $hoy = new \DateTimeImmutable();

        // WSFEv1: comprobante con todos los nodos opcionales
        $inv = Factory::facturaA()->setNumero(5)->setMoneda('DOL', 1000)->setPagoMismaMoneda(false)
            ->setConcepto(3)->setPeriodoServicio($hoy->modify('-3 days'), $hoy, $hoy->modify('+5 days'))
            ->addTributo(new Tributo(7, 'Perc IIBB', 100, 3, 3))
            ->addComprador(new Comprador(80, '30500010912', 100))
            ->addCbteAsociado(new CbteAsociado(1, 1, 3, '20111111112', $hoy->modify('-2 days')))
            ->setActividades([620100]);
        $inv2 = (clone $inv)->addOpcional(2, '1234')->setCbtesAsociados([])->setPeriodoAsociado(new Periodo($hoy->modify('-30 days'), $hoy->modify('-1 day')));
        $calc->calculate($inv);
        $calc->calculate($inv2);
        $wsfe = $see->getWsfe();
        $try(fn () => $wsfe->authorize([$inv, (clone $inv)->setNumero(6)]));
        $try(fn () => $wsfe->authorize($inv2));
        $try(fn () => $wsfe->informCaeaVouchers((clone $inv)->setCaea('31234567890123')->setFechaHoraGeneracion($hoy)));
        foreach (['getLastVoucher' => [1, 1], 'getVoucher' => [1, 1, 5], 'getMaxRecordsPerRequest' => [], 'requestCaea' => [202611, 1], 'getCaea' => [202611, 1],
            'informCaeaWithoutMovement' => ['31234567890123', 1], 'getCaeaWithoutMovement' => ['31234567890123', 1], 'getQuotation' => ['DOL', $hoy],
            'getVoucherTypes' => [], 'getConceptTypes' => [], 'getDocumentTypes' => [], 'getVatTypes' => [], 'getCurrencies' => [], 'getOptionalTypes' => [], 'getTaxTypes' => [],
            'getSalesPoints' => [], 'getCountries' => [], 'getActivities' => [], 'getReceptorVatConditions' => ['A'], 'dummy' => []] as $m => $args) {
            $try(fn () => $wsfe->$m(...$args));
        }

        // WSFEXv1
        $exp = ExportTest::invoice()->setId(10)->setNumero(4)
            ->addCbteAsociado(new ExportCbteAsociado(19, 3, 1, '20111111112'))
            ->addOpcional(new Opcional('1', 'x'))->setFechaPago($hoy)->setActividades([1]);
        $exp2 = (clone $exp)->setCuitPaisCliente(null)->setPermisoExistente('S')->addPermiso(new Permiso('16001EC01000001A', 222));
        $wsfex = $see->getWsfex();
        $try(fn () => $wsfex->authorize($exp));
        $try(fn () => $wsfex->authorize($exp2));
        foreach (['getLastId' => [], 'getLastVoucher' => [1, 19], 'getVoucher' => [19, 1, 1], 'checkPermit' => ['16001EC01000001A', 222], 'getVoucherTypes' => [],
            'getExportTypes' => [], 'getIncoterms' => [], 'getLanguages' => [], 'getUnits' => [], 'getCountries' => [], 'getCountryCuits' => [], 'getCurrencies' => [],
            'getCurrenciesWithQuotation' => [$hoy], 'getSalesPoints' => [], 'getOptionalTypes' => [], 'getActivities' => [], 'getQuotation' => ['DOL'], 'dummy' => []] as $m => $args) {
            $try(fn () => $wsfex->$m(...$args));
        }

        // WSMTXCA
        $mtx = Factory::facturaA()->setNumero(9)->setMoneda('DOL', 1000)->setPagoMismaMoneda(true)
            ->addTributo(new Tributo(7, 'Perc IIBB', 100, 3, 3))
            ->addCbteAsociado(new CbteAsociado(1, 1, 3, '20111111112', $hoy->modify('-2 days')))
            ->addComprador(new Comprador(80, '30500010912', 100))
            ->addDatoAdicional(new DatoAdicional(21, '2850590940090418135201'))
            ->addDetail((new SaleDetail())->setDescripcion('Exento')->setPrecioUnitario(10)->setExento()->setCodigoMtx('7790001')->setUnidadesMtx(1));
        $calc->calculate($mtx, true);
        $wsm = $see->getWsmtxca();
        $try(fn () => $wsm->authorize($mtx));
        $try(fn () => $wsm->authorize((clone $mtx)->setCbtesAsociados([])->setPeriodoAsociado(new Periodo($hoy->modify('-9 days'), $hoy->modify('-1 day')))));
        $try(fn () => $wsm->informCaeaVoucher((clone $mtx)->setCaea('31234567890123')->setFechaHoraGeneracion($hoy)));
        foreach (['getLastVoucher' => [1, 1], 'getVoucher' => [1, 1, 5], 'requestCaea' => [202611, 1], 'getCaea' => ['31234567890123'],
            'getCaeaBetween' => [$hoy->modify('-30 days'), $hoy], 'informCaeaNotUsed' => ['31234567890123'], 'getVoucherTypes' => [], 'getDocumentTypes' => [],
            'getVatRates' => [], 'getItemVatConditions' => [], 'getCurrencies' => [], 'getUnits' => [], 'getTaxTypes' => [], 'getAdditionalDataTypes' => [],
            'getQuotation' => ['DOL'], 'getSalesPoints' => ['CAE'], 'dummy' => []] as $m => $args) {
            $try(fn () => $wsm->$m(...$args));
        }
        $try(fn () => $wsm->informCaeaNotUsed('31234567890123', 3));
        $try(fn () => $wsm->getSalesPoints('CAEA'));
        $try(fn () => $wsm->getSalesPoints('TODOS'));

        // WSBFEv1
        $bfe = (new BonoFiscalInvoice())->setId(1)->setNumero(1)->setZona(1)->setTipoComprobante(201)->setCompany(Factory::company())->setClient(Factory::clientRi())
            ->setFechaVencimientoPago($hoy->modify('+30 days'))->addOpcional(2101, '2850590940090418135201')->addOpcional(27, 'SCA')
            ->setMoneda('DOL', 1000)->setPagoMismaMoneda(true)
            ->addCbteAsociado(new CbteAsociado(91, 1, 1, '20111111112', $hoy))
            ->addDetail((new SaleDetail())->setCodigoNcm('8471.30.12')->setDescripcion('Notebook')->setCantidad(2)->setPrecioUnitario(100)->setAlicuotaIva(5));
        $calc->calculate($bfe, true);
        $wsb = $see->getWsbfe();
        $try(fn () => $wsb->authorize($bfe));
        foreach (['getLastId' => [], 'getLastVoucher' => [1, 1], 'getVoucher' => [1, 1, 1], 'getVoucherTypes' => [], 'getDocumentTypes' => [], 'getVatTypes' => [],
            'getOptionalTypes' => [], 'getZones' => [], 'getUnits' => [], 'getCurrencies' => [], 'getNcmCodes' => [], 'dummy' => []] as $m => $args) {
            $try(fn () => $wsb->$m(...$args));
        }

        // WSCT
        $ct = (new TurismoInvoice())->setNumero(1)->setPuntoVenta(40)->setCompany(Factory::company())
            ->setClient((new Client())->setTipoDoc(94)->setNumDoc('AB123456')->setCondicionIva(9))
            ->setCodigoPais(203)->setDomicilioReceptor('Calle 1')->setCodigoRelacionEmisorReceptor(3)->setMoneda('DOL', 1000)->setPagoMismaMoneda(false)
            ->addDetail((new SaleDetail())->setCodigoTurismo(1)->setCodigo('T001')->setDescripcion('Alojamiento')->setPrecioUnitario(100)->setAlicuotaIva(5))
            ->addTributo(new Tributo(99, 'Tasa municipal', 100, 1, 1))
            ->addCbteAsociado(new CbteAsociado(195, 40, 1))
            ->addDatoAdicional(new DatoAdicional(1, 'a', 'b'))
            ->addFormaPago(new FormaPago(68, 99, '999999'))
            ->addFormaPago(new FormaPago(1, null, null, 'BOFAUS3N', 1, '123456789012'));
        $ct->setImporteReintegro(-21);
        $calc->calculate($ct, true);
        $wsc = $see->getWsct();
        $try(fn () => $wsc->authorize($ct));
        foreach (['getLastVoucher' => [40, 195], 'getVoucher' => [195, 40, 1], 'getSalesPoints' => [], 'getVoucherTypes' => [], 'getDocumentTypes' => [],
            'getVatTypes' => [], 'getVatConditions' => [], 'getCurrencies' => [], 'getCountries' => [], 'getCountryCuits' => [], 'getTaxTypes' => [],
            'getAdditionalDataTypes' => [], 'getPaymentMethods' => [], 'getCardTypes' => [68], 'getAccountTypes' => [], 'getItemTypes' => [],
            'getTourismItemCodes' => [], 'getIssuerReceiverRelations' => [], 'getQuotation' => ['DOL'], 'dummy' => []] as $m => $args) {
            $try(fn () => $wsc->$m(...$args));
        }

        // WSFECRED
        $fc = $see->getWsfecred();
        $factura = WsfecredService::comprobante('30500010912', 201, 1, 1);
        $porCodigo = WsfecredService::cuentaCorriente(5);
        $porFactura = WsfecredService::cuentaCorriente(null, $factura);
        $motivo = [['codMotivo' => 1, 'descMotivo' => 'Falta de entrega', 'justificacion' => 'No recibida']];
        $rango = ['tipo' => 'Emision', 'desde' => $hoy->modify('-30 days'), 'hasta' => $hoy];
        $try(fn () => $fc->getObligation('30500010912'));
        $try(fn () => $fc->getReceiverStatus('30500010912'));
        $try(fn () => $fc->getVouchers('Receptor', ['cuitContraparte' => '30500010912', 'tipo' => 201, 'estado' => 'Aceptado', 'fecha' => $rango, 'codCtaCte' => 5, 'estadoCtaCte' => 'Aceptada', 'pagina' => 1]));
        $try(fn () => $fc->getAccounts('Emisor', ['cuitContraparte' => '30500010912', 'fecha' => $rango, 'estadoCtaCte' => 'Modificable', 'pagina' => 1, 'opcionTransferencia' => 'SCA']));
        $try(fn () => $fc->getAccount($porFactura));
        $try(fn () => $fc->accept($porCodigo, 100, 'PES', 1, [
            'notas' => [['idNota' => WsfecredService::comprobante('30500010912', 203, 1, 2), 'acepta' => true]],
            'formasCancelacion' => [['codigo' => 1, 'descripcion' => 'Transferencia']],
            'retenciones' => [['codTipo' => 1, 'importe' => 1.5, 'porcentaje' => 1.5, 'descMotivo' => 'Retención IIBB']],
            'ajustes' => [['codigo' => 1, 'importe' => 1]],
            'tipoCancelacion' => 'TOT', 'importeCancelado' => 100, 'importeTotalRetPesos' => 1.5, 'importeEmbargoPesos' => 0,
            'cbuComprador' => '2850590940090418135201',
        ]));
        $try(fn () => $fc->reject($porFactura, $motivo));
        $try(fn () => $fc->rejectNote(WsfecredService::comprobante('30500010912', 203, 1, 2), $motivo));
        $try(fn () => $fc->informTotalCancellation($porCodigo, [['codigo' => 1, 'descripcion' => 'Transferencia']], 100));
        $try(fn () => $fc->changeTransferOption($porCodigo, 'ADC'));
        foreach (['getRetentionTypes', 'getRejectionReasons', 'getCancellationMethods', 'getAdjustmentTypes'] as $m) {
            $try(fn () => $fc->$m());
        }

        // WSCDC
        $cdc = $see->getWscdc();
        $try(fn () => $cdc->verify('30500010912', 1, 1, 1, $hoy, 10, '71253948353413', 'CAE', 80, '20111111112', [['Id' => '1', 'Valor' => 'x']]));
        foreach (['getModes', 'getVoucherTypes', 'getDocumentTypes', 'getOptionalTypes', 'dummy'] as $m) {
            $try(fn () => $cdc->$m());
        }

        // Padrón
        $try(fn () => $see->getPadronA5()->getPersona('30500010912'));
        $try(fn () => $see->getPadronA5()->getPersonas(['30500010912', '20267565393']));
        $try(fn () => $see->getPadronA13()->getPersona('30500010912'));
        $try(fn () => $see->getPadronA13()->getCuitsByDocumento('26756539'));

        return $transport->requests;
    }
}
