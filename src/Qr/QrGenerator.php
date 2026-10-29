<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Qr;

use RoyaltyFusion\Afip\Catalog\Moneda;
use RoyaltyFusion\Afip\Catalog\TipoDocumento;
use RoyaltyFusion\Afip\Model\Export\ExportInvoice;
use RoyaltyFusion\Afip\Model\Sale\Invoice;
use RoyaltyFusion\Afip\Support\Cuit;
use RoyaltyFusion\Afip\Support\Num;

/**
 * Código QR obligatorio en la representación impresa (RG 4291, Anexo III).
 *
 * El QR codifica la URL https://www.afip.gob.ar/fe/qr/?p={JSON en base64} con los datos
 * del comprobante.
 */
final class QrGenerator
{
    public const DEFAULT_URL = 'https://www.afip.gob.ar/fe/qr/';
    public const VERSION = 1;
    public const TIPO_CAE = 'E';
    public const TIPO_CAEA = 'A';

    public function __construct(private readonly string $baseUrl = self::DEFAULT_URL)
    {
    }

    /**
     * Datos del QR según la especificación de ARCA.
     *
     * @return array<string, int|float|string>
     */
    public function buildData(Invoice|ExportInvoice $invoice, string $cuitEmisor, ?string $codigoAutorizacion = null, string $tipoCodigo = self::TIPO_CAE): array
    {
        $codigo = $codigoAutorizacion ?? $invoice->getCae();
        if (($codigo === null || $codigo === '') && $invoice instanceof Invoice && $invoice->getCaea() !== null) {
            $codigo = $invoice->getCaea();
            $tipoCodigo = self::TIPO_CAEA;
        }
        if ($codigo === null || $codigo === '') {
            throw new \InvalidArgumentException('El comprobante no tiene CAE/CAEA: no se puede generar el QR.');
        }

        $moneda = $invoice->getMoneda();
        $data = [
            'ver' => self::VERSION,
            'fecha' => $invoice->getFechaEmision()->format('Y-m-d'),
            'cuit' => (int) Cuit::normalize($cuitEmisor),
            'ptoVta' => $invoice->getPuntoVenta(),
            'tipoCmp' => $invoice->getTipoComprobante(),
            'nroCmp' => (int) $invoice->getNumero(),
            'importe' => (float) Num::format($invoice->getImpTotal()),
            'moneda' => $moneda,
            'ctz' => (float) Num::formatCompact($moneda === Moneda::PESOS ? 1.0 : $invoice->getCotizacion(), 6),
        ];

        if ($invoice instanceof Invoice && $invoice->getClient() !== null) {
            $client = $invoice->getClient();
            if ((int) $client->getNumDoc() > 0) {
                $data['tipoDocRec'] = $client->getTipoDoc();
                $data['nroDocRec'] = (int) $client->getNumDoc();
            }
        } elseif ($invoice instanceof ExportInvoice && $invoice->getCuitPaisCliente()) {
            $data['tipoDocRec'] = TipoDocumento::CUIT;
            $data['nroDocRec'] = (int) Cuit::normalize($invoice->getCuitPaisCliente());
        }

        $data['tipoCodAut'] = $tipoCodigo;
        $data['codAut'] = (int) $codigo;

        return $data;
    }

    /**
     * URL completa a codificar en el QR.
     */
    public function buildUrl(Invoice|ExportInvoice $invoice, string $cuitEmisor, ?string $codigoAutorizacion = null, string $tipoCodigo = self::TIPO_CAE): string
    {
        $json = json_encode(
            $this->buildData($invoice, $cuitEmisor, $codigoAutorizacion, $tipoCodigo),
            JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );

        return $this->baseUrl . '?p=' . base64_encode((string) $json);
    }

    /**
     * Decodifica una URL de QR (útil para verificar).
     *
     * @return array<string, mixed>
     */
    public static function decode(string $url): array
    {
        $query = (string) parse_url($url, PHP_URL_QUERY);
        parse_str($query, $params);
        $p = $params['p'] ?? '';
        $data = json_decode((string) base64_decode(is_string($p) ? $p : '', true), true);

        return is_array($data) ? $data : [];
    }

    /**
     * Imagen del QR como data URI (PNG/SVG). Requiere chillerlan/php-qrcode.
     */
    public function toDataUri(string $url): string
    {
        if (!class_exists(\chillerlan\QRCode\QRCode::class)) {
            throw new \RuntimeException('Para generar la imagen del QR instale chillerlan/php-qrcode: composer require chillerlan/php-qrcode');
        }

        return (new \chillerlan\QRCode\QRCode())->render($url);
    }

    public static function canRenderImage(): bool
    {
        return class_exists(\chillerlan\QRCode\QRCode::class);
    }
}
