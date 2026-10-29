<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RoyaltyFusion\Afip\Exception\SoapFaultException;
use RoyaltyFusion\Afip\Soap\SoapClient;
use RoyaltyFusion\Afip\Tests\Support\MockTransport;

final class SoapClientTest extends TestCase
{
    public function testQualifiedEnvelopeKeepsOrderAndRepeatsLists(): void
    {
        $client = new SoapClient('https://x', 'http://ar.gov.afip.dif.FEV1/', new MockTransport(), true, 'http://ar.gov.afip.dif.FEV1/');
        $xml = $client->buildEnvelope('FECAESolicitar', [
            'Auth' => ['Token' => 'T', 'Sign' => 'S', 'Cuit' => '20111111112'],
            'Skip' => null,
            'Iva' => ['AlicIva' => [['Id' => 5, 'BaseImp' => '100.00'], ['Id' => 4, 'BaseImp' => '50.00']]],
        ]);

        $doc = new \DOMDocument();
        $doc->loadXML($xml);
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('fe', 'http://ar.gov.afip.dif.FEV1/');
        self::assertSame('T', $xpath->evaluate('string(//fe:FECAESolicitar/fe:Auth/fe:Token)'));
        self::assertSame(2.0, $xpath->evaluate('count(//fe:Iva/fe:AlicIva)'));
        self::assertSame(0.0, $xpath->evaluate('count(//fe:Skip)'));
        self::assertStringContainsString('<Auth><Token>T</Token><Sign>S</Sign><Cuit>20111111112</Cuit></Auth><Iva>', $xml);
    }

    public function testUnqualifiedEnvelopeForJaxWs(): void
    {
        $client = new SoapClient('https://x', 'http://a5.soap.ws.server.puc.sr/', new MockTransport(), false);
        $xml = $client->buildEnvelope('getPersona_v2', ['token' => 'T', 'idPersona' => '20111111112']);
        self::assertStringContainsString('<ns1:getPersona_v2 xmlns:ns1="http://a5.soap.ws.server.puc.sr/"><token>T</token>', $xml);
    }

    public function testParsesResponseAndSoapAction(): void
    {
        $transport = (new MockTransport())->on('FEDummy', MockTransport::wsfe('FEDummy', '<AppServer>OK</AppServer><DbServer>OK</DbServer><AuthServer>OK</AuthServer>'));
        $client = new SoapClient('https://x', 'http://ar.gov.afip.dif.FEV1/', $transport, true, 'http://ar.gov.afip.dif.FEV1/');
        $result = $client->call('FEDummy', []);

        self::assertSame('OK', $result['FEDummyResult']['AppServer']);
        self::assertSame('"http://ar.gov.afip.dif.FEV1/FEDummy"', $transport->requests[0]['headers']['SOAPAction']);
    }

    public function testRepeatedNodesBecomeLists(): void
    {
        $transport = (new MockTransport())->on('X', MockTransport::wsfe('X', '<Errors><Err><Code>1</Code><Msg>a</Msg></Err><Err><Code>2</Code><Msg>b</Msg></Err></Errors>'));
        $client = new SoapClient('https://x', 'http://ar.gov.afip.dif.FEV1/', $transport);
        $result = $client->call('X', []);
        self::assertCount(2, $result['XResult']['Errors']['Err']);
        self::assertSame('2', $result['XResult']['Errors']['Err'][1]['Code']);
    }

    public function testFaultThrowsException(): void
    {
        $transport = (new MockTransport())->on('loginCms', MockTransport::fault('ns1:coe.alreadyAuthenticated', 'El CEE ya posee un TA valido'));
        $client = new SoapClient('https://x', 'http://wsaa.view.sua.dvadac.desein.afip.gov', $transport);

        try {
            $client->call('loginCms', ['in0' => 'x']);
            self::fail('Se esperaba SoapFaultException');
        } catch (SoapFaultException $e) {
            self::assertSame('coe.alreadyAuthenticated', $e->getShortFaultCode());
            self::assertStringContainsString('TA valido', $e->getFaultString());
        }
    }

    public function testLoggerReceivesMessages(): void
    {
        $transport = (new MockTransport())->on('FEDummy', MockTransport::wsfe('FEDummy', '<AppServer>OK</AppServer>'));
        $client = new SoapClient('https://x', 'http://ar.gov.afip.dif.FEV1/', $transport);
        $log = [];
        $client->setLogger(static function (string $op, string $req, ?string $res) use (&$log): void {
            $log[] = [$op, $req, $res];
        });
        $client->call('FEDummy', []);
        self::assertSame('FEDummy', $log[0][0]);
        self::assertStringContainsString('AppServer', (string) $log[0][2]);
    }
}
