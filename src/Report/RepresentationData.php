<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Report;

use RoyaltyFusion\Afip\Catalog\AlicuotaIva;
use RoyaltyFusion\Afip\Catalog\Concepto;
use RoyaltyFusion\Afip\Catalog\CondicionIva;
use RoyaltyFusion\Afip\Catalog\Moneda;
use RoyaltyFusion\Afip\Catalog\TipoComprobante;
use RoyaltyFusion\Afip\Catalog\TipoDocumento;
use RoyaltyFusion\Afip\Catalog\TipoTributo;
use RoyaltyFusion\Afip\Model\Export\ExportInvoice;
use RoyaltyFusion\Afip\Model\Sale\Invoice;
use RoyaltyFusion\Afip\Qr\QrGenerator;
use RoyaltyFusion\Afip\Support\Cuit;
use RoyaltyFusion\Afip\Support\Num;

/**
 * Datos de la representación impresa listos para dibujar con cualquier motor (TCPDF,
 * Twig, etc.), sin depender de dompdf ni de una librería de QR.
 */
final class RepresentationData
{
    /** RG 5003 (Ley 27.618): Factura A emitida a un monotributista. */
    public const LEYENDA_RG5003 = 'El crédito fiscal discriminado en el presente comprobante, sólo podrá ser computado a efectos del Régimen de Sostenimiento e Inclusión Fiscal para Pequeños Contribuyentes de la Ley Nº 27.618';
    /** RG 5700: operaciones con consumidores finales. */
    public const LEYENDA_CONSUMIDOR_FINAL = 'A CONSUMIDOR FINAL';
    public const TITULO_TRANSPARENCIA = 'Régimen de Transparencia Fiscal al Consumidor (Ley 27.743)';

    /**
     * @return array<string, mixed> ver README ("Representación impresa")
     */
    public static function build(Invoice|ExportInvoice $invoice, string $cuitEmisor, ?QrGenerator $qr = null): array
    {
        $tipo = $invoice->getTipoComprobante();
        $clase = TipoComprobante::clase($tipo);
        $qr ??= new QrGenerator();
        $moneda = $invoice->getMoneda();
        $company = $invoice->getCompany();
        $cuitEmisor = Cuit::normalize($cuitEmisor !== '' ? $cuitEmisor : (string) $company?->getCuit());

        $caea = $invoice instanceof Invoice && $invoice->getCae() === null ? $invoice->getCaea() : null;
        $codigo = $invoice->getCae() ?? $caea;
        $qrUrl = $codigo !== null ? $qr->buildUrl($invoice, $cuitEmisor) : null;

        $data = [
            'letra' => $clase ?? '',
            'codigo' => TipoComprobante::printCode($tipo),
            'tipo' => $tipo,
            'tipoDescripcion' => TipoComprobante::description($tipo),
            'puntoVenta' => sprintf('%05d', $invoice->getPuntoVenta()),
            'numero' => sprintf('%08d', (int) $invoice->getNumero()),
            'numeroCompleto' => $invoice->getNumeroCompleto(),
            'fechaEmision' => $invoice->getFechaEmision()->format('Y-m-d'),
            'tipoAutorizacion' => $codigo === null ? null : ($caea !== null ? 'CAEA' : 'CAE'),
            'cae' => $codigo,
            'caeVencimiento' => $invoice->getCaeVencimiento()?->format('Y-m-d'),
            'qrUrl' => $qrUrl,
            'qrData' => $qrUrl !== null ? QrGenerator::decode($qrUrl) : null,
            'emisor' => [
                'cuit' => $cuitEmisor,
                'cuitFormateado' => Cuit::format($cuitEmisor),
                'razonSocial' => $company?->getRazonSocial(),
                'nombreFantasia' => $company?->getNombreFantasia(),
                'condicionIva' => $company?->getCondicionIva(),
                'condicionIvaDescripcion' => $company !== null ? CondicionIva::description($company->getCondicionIva()) : null,
                'ingresosBrutos' => $company?->getIngresosBrutos(),
                'inicioActividades' => $company?->getInicioActividades()?->format('Y-m-d'),
                'domicilio' => $company?->getAddress() !== null ? (string) $company->getAddress() : null,
            ],
            'moneda' => $moneda,
            'monedaDescripcion' => Moneda::description($moneda),
            'cotizacion' => $moneda === Moneda::PESOS ? 1.0 : $invoice->getCotizacion(),
            'pagoMismaMoneda' => $invoice->getPagoMismaMoneda(),
            'importes' => ['total' => Num::round($invoice->getImpTotal())],
            'leyendas' => [],
            'transparenciaFiscal' => null,
        ];

        if ($invoice instanceof ExportInvoice) {
            $data['receptor'] = [
                'razonSocial' => $invoice->getCliente(),
                'domicilio' => $invoice->getDomicilioCliente(),
                'cuitPais' => $invoice->getCuitPaisCliente(),
                'idImpositivo' => $invoice->getIdImpositivo(),
                'paisDestino' => $invoice->getPaisDestino(),
            ];
            $data['leyendas'] = array_values(array_filter([$invoice->getObservacionesComerciales(), $invoice->getObservaciones()]));

            return $data;
        }

        $client = $invoice->getClient();
        $condicion = $client?->getCondicionIva();
        $data['receptor'] = [
            'docTipo' => $client?->getTipoDoc(),
            'docTipoDescripcion' => $client !== null ? TipoDocumento::description($client->getTipoDoc()) : null,
            'docNro' => $client?->getNumDoc(),
            'docNroFormateado' => $client === null ? null : (TipoDocumento::isCuitLike($client->getTipoDoc()) ? Cuit::format($client->getNumDoc()) : $client->getNumDoc()),
            'razonSocial' => $client?->getRazonSocial(),
            'condicionIva' => $condicion,
            'condicionIvaDescripcion' => $condicion !== null ? CondicionIva::description($condicion) : null,
            'domicilio' => $client?->getAddress() !== null ? (string) $client->getAddress() : null,
        ];
        $data['servicio'] = Concepto::includesServices($invoice->getConcepto()) ? [
            'desde' => $invoice->getFechaServicioDesde()?->format('Y-m-d'),
            'hasta' => $invoice->getFechaServicioHasta()?->format('Y-m-d'),
            'vencimientoPago' => $invoice->getFechaVencimientoPago()?->format('Y-m-d'),
        ] : null;
        $data['vencimientoPago'] = $invoice->getFechaVencimientoPago()?->format('Y-m-d');

        $ivas = [];
        foreach ($invoice->getIvas() as $iva) {
            $ivas[] = [
                'id' => $iva->getId(),
                'alicuota' => AlicuotaIva::description($iva->getId()),
                'baseImponible' => $iva->getBaseImp(),
                'importe' => $iva->getImporte(),
            ];
        }
        $data['importes'] = [
            'neto' => $invoice->getImpNeto(),
            'noGravado' => $invoice->getImpNoGravado(),
            'exento' => $invoice->getImpExento(),
            'iva' => $invoice->getImpIva(),
            'ivas' => $ivas,
            'tributos' => $invoice->getImpTributos(),
            'total' => $invoice->getImpTotal(),
        ];
        $data['discriminaIva'] = in_array($clase, ['A', 'M'], true);
        $data['cbtesAsociados'] = array_map(static fn ($a): array => [
            'tipo' => $a->getTipo(),
            'tipoDescripcion' => TipoComprobante::description($a->getTipo()),
            'numeroCompleto' => sprintf('%05d-%08d', $a->getPuntoVenta(), $a->getNumero()),
            'fecha' => $a->getFecha()?->format('Y-m-d'),
        ], $invoice->getCbtesAsociados());

        $leyendas = [];
        if (in_array($clase, ['A', 'M'], true) && $condicion !== null && CondicionIva::isMonotributo($condicion)) {
            $leyendas[] = self::LEYENDA_RG5003;
        }
        if ($clase === 'B' && $condicion === CondicionIva::CONSUMIDOR_FINAL) {
            $leyendas[] = self::LEYENDA_CONSUMIDOR_FINAL;
        }
        if ($clase === 'B') {
            $otros = 0.0;
            foreach ($invoice->getTributos() as $tributo) {
                if (TipoTributo::isNationalIndirect($tributo->getId())) {
                    $otros += $tributo->getImporte();
                }
            }
            $data['transparenciaFiscal'] = [
                'titulo' => self::TITULO_TRANSPARENCIA,
                'ivaContenido' => Num::round($invoice->getImpIva()),
                'otrosImpuestosNacionalesIndirectos' => Num::round($otros),
            ];
        }
        $data['leyendas'] = array_values(array_merge($leyendas, $invoice->getLeyendas(), array_filter([$invoice->getObservaciones()])));

        return $data;
    }
}
