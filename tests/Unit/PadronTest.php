<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RoyaltyFusion\Afip\Catalog\CondicionIva;
use RoyaltyFusion\Afip\Environment;
use RoyaltyFusion\Afip\Exception\PadronException;
use RoyaltyFusion\Afip\See;
use RoyaltyFusion\Afip\Tests\Support\MockTransport;
use RoyaltyFusion\Afip\Tests\Support\TestCertificate;

final class PadronTest extends TestCase
{
    private const A5 = '<ns2:getPersona_v2Response xmlns:ns2="http://a5.soap.ws.server.puc.sr/"><personaReturn>'
        . '<datosGenerales><apellido></apellido><domicilioFiscal><codPostal>1036</codPostal><descripcionProvincia>CIUDAD AUTONOMA BUENOS AIRES</descripcionProvincia>'
        . '<direccion>BARTOLOME MITRE 326</direccion><idProvincia>0</idProvincia><localidad>CIUDAD AUTONOMA BUENOS AIRES</localidad></domicilioFiscal>'
        . '<estadoClave>ACTIVO</estadoClave><idPersona>30500010912</idPersona><razonSocial>BANCO DE LA NACION ARGENTINA</razonSocial>'
        . '<tipoClave>CUIT</tipoClave><tipoPersona>JURIDICA</tipoPersona></datosGenerales>'
        . '<datosRegimenGeneral><actividad><descripcionActividad>SERVICIOS DE LA BANCA</descripcionActividad><idActividad>641930</idActividad><orden>1</orden></actividad>'
        . '<impuesto><descripcionImpuesto>GANANCIAS SOCIEDADES</descripcionImpuesto><idImpuesto>10</idImpuesto></impuesto>'
        . '<impuesto><descripcionImpuesto>IVA</descripcionImpuesto><idImpuesto>30</idImpuesto></impuesto></datosRegimenGeneral>'
        . '<metadata><fechaHora>2026-10-29T10:00:00-03:00</fechaHora><servidor>aws</servidor></metadata></personaReturn></ns2:getPersona_v2Response>';

    private function see(MockTransport $transport): See
    {
        return (new See(Environment::HOMOLOGACION))->setCertificate(TestCertificate::create())->setTransport($transport);
    }

    public function testConstanciaInscripcion(): void
    {
        $transport = (new MockTransport())->withLogin()->on('getPersona_v2', MockTransport::envelope(self::A5));
        $persona = $this->see($transport)->getPadronA5()->getPersona('30-50001091-2');

        self::assertNotNull($persona);
        self::assertSame('BANCO DE LA NACION ARGENTINA', $persona->getNombreCompleto());
        self::assertSame(CondicionIva::RESPONSABLE_INSCRIPTO, $persona->getCondicionIva());
        self::assertTrue($persona->isActiva());
        self::assertSame('BARTOLOME MITRE 326', $persona->getDomicilioFiscal()->getDireccion());
        self::assertSame(641930, $persona->getActividades()[0]['id']);

        $client = $persona->toClient();
        self::assertSame(80, $client->getTipoDoc());
        self::assertSame(CondicionIva::RESPONSABLE_INSCRIPTO, $client->getCondicionIva());

        $body = $transport->requestsFor('getPersona_v2')[0]['body'];
        self::assertStringContainsString('<cuitRepresentada>20111111112</cuitRepresentada><idPersona>30500010912</idPersona>', $body);
    }

    public function testMonotributista(): void
    {
        $xml = str_replace(
            '<datosRegimenGeneral>',
            '<datosMonotributo><categoriaMonotributo><descripcionCategoria>A LOCACIONES DE SERVICIOS</descripcionCategoria><idCategoria>1</idCategoria></categoriaMonotributo><impuesto><descripcionImpuesto>MONOTRIBUTO</descripcionImpuesto><idImpuesto>20</idImpuesto></impuesto></datosMonotributo><datosRegimenGeneral>',
            str_replace('<impuesto><descripcionImpuesto>IVA</descripcionImpuesto><idImpuesto>30</idImpuesto></impuesto>', '', self::A5)
        );
        $transport = (new MockTransport())->withLogin()->on('getPersona_v2', MockTransport::envelope($xml));
        $persona = $this->see($transport)->getPadronA5()->getPersona('20267565393');

        self::assertTrue($persona->isMonotributo());
        self::assertSame(CondicionIva::MONOTRIBUTO, $persona->getCondicionIva());
        self::assertSame('A LOCACIONES DE SERVICIOS', $persona->getCategoriaMonotributo());
    }

    public function testNotFoundReturnsNull(): void
    {
        $transport = (new MockTransport())->withLogin()->on('getPersona_v2', MockTransport::fault('soap:Server', 'No existe persona con ese Id'));
        self::assertNull($this->see($transport)->getPadronA5()->getPersona('20267565393'));
    }

    public function testOtherFaultsThrow(): void
    {
        $transport = (new MockTransport())->withLogin()->on('getPersona_v2', MockTransport::fault('soap:Server', 'Error interno'));
        $this->expectException(PadronException::class);
        $this->see($transport)->getPadronA5()->getPersona('20267565393');
    }

    public function testA13CuitsByDocumento(): void
    {
        $transport = (new MockTransport())->withLogin()->on('getIdPersonaListByDocumento', MockTransport::envelope(
            '<ns2:getIdPersonaListByDocumentoResponse xmlns:ns2="http://a13.soap.ws.server.puc.sr/"><idPersonaListReturn><idPersona>20267565393</idPersona></idPersonaListReturn></ns2:getIdPersonaListByDocumentoResponse>'
        ));
        self::assertSame(['20267565393'], $this->see($transport)->getPadronA13()->getCuitsByDocumento('26.756.539'));
    }
}
