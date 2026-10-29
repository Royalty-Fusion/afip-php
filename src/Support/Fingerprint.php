<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Support;

use RoyaltyFusion\Afip\Model\Export\ExportInvoice;
use RoyaltyFusion\Afip\Model\Sale\Invoice;

/**
 * Huella (SHA-256) de los datos fiscales de un comprobante: emisor, tipo, punto de venta,
 * número, fecha, total, moneda, cotización y receptor. Sirve para guardar junto a un
 * estado incierto y verificar luego que se retoma el mismo comprobante.
 */
final class Fingerprint
{
    /**
     * @return array<string, scalar|null>
     */
    public static function data(Invoice|ExportInvoice $invoice, string $cuitEmisor = ''): array
    {
        $data = [
            'cuit' => Cuit::normalize($cuitEmisor !== '' ? $cuitEmisor : (string) $invoice->getCompany()?->getCuit()),
            'tipo' => $invoice->getTipoComprobante(),
            'ptoVta' => $invoice->getPuntoVenta(),
            'numero' => $invoice->getNumero(),
            'fecha' => $invoice->getFechaEmision()->format('Ymd'),
            'total' => Num::format($invoice->getImpTotal()),
            'moneda' => $invoice->getMoneda(),
            'cotizacion' => Num::formatCompact($invoice->getMoneda() === 'PES' ? 1.0 : $invoice->getCotizacion(), 6),
        ];
        if ($invoice instanceof Invoice) {
            $data['numeroHasta'] = $invoice->getNumeroHasta();
            $data['docTipo'] = $invoice->getClient()?->getTipoDoc();
            $data['docNro'] = $invoice->getClient()?->getNumDoc();
        } else {
            $data['cliente'] = $invoice->getCuitPaisCliente() ?? $invoice->getIdImpositivo();
        }

        return $data;
    }

    public static function of(Invoice|ExportInvoice $invoice, string $cuitEmisor = ''): string
    {
        return hash('sha256', (string) json_encode(self::data($invoice, $cuitEmisor)));
    }

    /**
     * @param array<int, Invoice|ExportInvoice> $invoices
     */
    public static function ofBatch(array $invoices, string $cuitEmisor = ''): string
    {
        if (count($invoices) === 1) {
            return self::of(reset($invoices), $cuitEmisor);
        }

        return hash('sha256', implode('|', array_map(static fn ($i): string => self::of($i, $cuitEmisor), $invoices)));
    }
}
