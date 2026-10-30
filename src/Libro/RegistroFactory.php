<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Libro;

use RoyaltyFusion\Afip\Catalog\TipoDocumento;
use RoyaltyFusion\Afip\Catalog\TipoTributo;
use RoyaltyFusion\Afip\Model\Export\ExportInvoice;
use RoyaltyFusion\Afip\Model\Sale\Invoice;
use RoyaltyFusion\Afip\Qr\QrGenerator;
use RoyaltyFusion\Afip\Support\Cuit;
use RoyaltyFusion\Afip\Support\Num;

/**
 * Arma registros del Libro IVA Digital a partir de los comprobantes del SDK.
 *
 * Tributos de WSFE en el libro de ventas:
 *
 * | Tributo                                   | Campo del libro                    |
 * |-------------------------------------------|------------------------------------|
 * | 6 Percepción de IVA, 13 a no categorizado | percepción a no categorizados      |
 * | 1 Impuestos nacionales                    | percepciones de impuestos nacionales |
 * | 5 IIBB, 7 Percepción de IIBB              | percepciones de Ingresos Brutos     |
 * | 3 Municipales, 8 Percepciones municipales | percepciones municipales           |
 * | 4 Impuestos internos                      | impuestos internos                 |
 * | 2, 9, 99 y otros                          | otros tributos                     |
 *
 * El registro que devuelve se puede ajustar antes de agregarlo al libro.
 */
final class RegistroFactory
{
    public static function fromSale(Invoice|ExportInvoice $invoice): RegistroIva
    {
        return $invoice instanceof Invoice ? self::fromInvoice($invoice) : self::fromExportInvoice($invoice);
    }

    public static function fromInvoice(Invoice $invoice): RegistroIva
    {
        $numero = $invoice->getNumero();
        if ($numero === null) {
            throw new \InvalidArgumentException('El comprobante no tiene número: solo se registran comprobantes autorizados.');
        }
        $r = new RegistroIva($invoice->getFechaEmision(), $invoice->getTipoComprobante(), $invoice->getPuntoVenta(), $numero);
        $r->setNumeroHasta($invoice->getNumeroHasta());

        $client = $invoice->getClient();
        if ($client === null) {
            $r->setContraparte(TipoDocumento::CONSUMIDOR_FINAL, '0', '');
        } else {
            $r->setContraparte($client->getTipoDoc(), $client->getNumDoc(), (string) $client->getRazonSocial());
        }

        $r->setTotal($invoice->getImpTotal())
            ->setNoGravado($invoice->getImpNoGravado())
            ->setExento($invoice->getImpExento())
            ->setMoneda($invoice->getMoneda(), $invoice->getCotizacion())
            ->setFechaVencimientoPago($invoice->getFechaVencimientoPago());

        $alicuotas = [];
        foreach ($invoice->getIvas() as $iva) {
            $alicuotas[$iva->getId()] ??= [0.0, 0.0];
            $alicuotas[$iva->getId()][0] += $iva->getBaseImp();
            $alicuotas[$iva->getId()][1] += $iva->getImporte();
        }
        foreach ($alicuotas as $id => [$neto, $importe]) {
            $r->addAlicuota($id, Num::round($neto), Num::round($importe));
        }

        self::applyTributos($r, $invoice);

        if ($invoice->getCae() !== null) {
            $r->setCodigoAutorizacion($invoice->getCae(), 'CAE');
        } elseif ($invoice->getCaea() !== null) {
            $r->setCodigoAutorizacion($invoice->getCaea(), 'CAEA');
        }

        return $r;
    }

    /**
     * Exportación: el total se registra como exento, con código de operación X
     * (exportación al exterior). Cambie el código a Z si es a zona franca.
     */
    public static function fromExportInvoice(ExportInvoice $invoice): RegistroIva
    {
        $numero = $invoice->getNumero();
        if ($numero === null) {
            throw new \InvalidArgumentException('El comprobante no tiene número: solo se registran comprobantes autorizados.');
        }
        $r = new RegistroIva($invoice->getFechaEmision(), $invoice->getTipoComprobante(), $invoice->getPuntoVenta(), $numero);
        $cuitPais = $invoice->getCuitPaisCliente();
        if ($cuitPais !== null && $cuitPais !== '') {
            $r->setContraparte(TipoDocumento::CUIT, $cuitPais, $invoice->getCliente());
        } else {
            $r->setContraparte(TipoDocumento::OTRO, '0', $invoice->getCliente());
        }

        $r->setTotal($invoice->getImpTotal())
            ->setExento($invoice->getImpTotal())
            ->setMoneda($invoice->getMoneda(), $invoice->getCotizacion())
            ->setCodigoOperacion(CodigoOperacion::EXTERIOR);
        if ($invoice->getCae() !== null) {
            $r->setCodigoAutorizacion($invoice->getCae());
        }

        return $r;
    }

    /**
     * Registro de compra a partir del QR impreso en un comprobante recibido. Trae lo
     * necesario para constatarlo con WSCDC; falta la denominación y el desglose.
     */
    public static function fromQr(string $url): RegistroIva
    {
        $data = QrGenerator::decode($url);
        foreach (['fecha', 'cuit', 'ptoVta', 'tipoCmp', 'nroCmp', 'importe', 'codAut'] as $key) {
            if (!isset($data[$key])) {
                throw new \InvalidArgumentException(sprintf('El QR no es un comprobante de ARCA válido: falta "%s".', $key));
            }
        }
        $fecha = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $data['fecha']);
        if ($fecha === false) {
            throw new \InvalidArgumentException(sprintf('Fecha inválida en el QR: %s.', (string) $data['fecha']));
        }

        $r = new RegistroIva($fecha, (int) $data['tipoCmp'], (int) $data['ptoVta'], (int) $data['nroCmp']);
        $r->setContraparte(TipoDocumento::CUIT, Cuit::normalize((string) $data['cuit']), '')
            ->setTotal((float) $data['importe'])
            ->setMoneda((string) ($data['moneda'] ?? 'PES'), (float) ($data['ctz'] ?? 1))
            ->setCodigoAutorizacion((string) $data['codAut'], ($data['tipoCodAut'] ?? 'E') === QrGenerator::TIPO_CAEA ? 'CAEA' : 'CAE');

        return $r;
    }

    private static function applyTributos(RegistroIva $r, Invoice $invoice): void
    {
        $sum = [];
        foreach ($invoice->getTributos() as $tributo) {
            $campo = match ($tributo->getId()) {
                TipoTributo::PERCEPCION_IVA => 'iva',
                TipoTributo::PERCEPCION_IVA_NO_CATEGORIZADO => 'noCategorizados',
                TipoTributo::IMPUESTOS_NACIONALES => 'nacionales',
                TipoTributo::IIBB, TipoTributo::PERCEPCION_IIBB => 'iibb',
                TipoTributo::IMPUESTOS_MUNICIPALES, TipoTributo::PERCEPCION_MUNICIPAL => 'municipales',
                TipoTributo::IMPUESTOS_INTERNOS => 'internos',
                default => 'otros',
            };
            $sum[$campo] = ($sum[$campo] ?? 0.0) + $tributo->getImporte();
        }

        $r->setPercepcionIva(Num::round($sum['iva'] ?? 0.0))
            ->setPercepcionNoCategorizados(Num::round($sum['noCategorizados'] ?? 0.0))
            ->setPercepcionesNacionales(Num::round($sum['nacionales'] ?? 0.0))
            ->setPercepcionesIibb(Num::round($sum['iibb'] ?? 0.0))
            ->setPercepcionesMunicipales(Num::round($sum['municipales'] ?? 0.0))
            ->setImpuestosInternos(Num::round($sum['internos'] ?? 0.0))
            ->setOtrosTributos(Num::round($sum['otros'] ?? 0.0));
    }
}
