<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Report;

use RoyaltyFusion\Afip\Catalog\AlicuotaIva;
use RoyaltyFusion\Afip\Catalog\CondicionIva;
use RoyaltyFusion\Afip\Catalog\Concepto;
use RoyaltyFusion\Afip\Catalog\Exportacion;
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
 * Representación impresa en HTML de un comprobante autorizado (RG 4291 / RG 1415).
 *
 * Parámetros admitidos en $params:
 *  - logo:           ruta o data URI del logo del emisor
 *  - copia:          "ORIGINAL" (por defecto), "DUPLICADO", "TRIPLICADO"
 *  - condicionVenta: texto (ej. "Contado", "Cuenta corriente")
 *  - remito:         número de remito
 *  - footer:         texto libre al pie
 *  - transparenciaFiscal: bool, fuerza o desactiva el bloque de la Ley 27.743
 *                    (por defecto se muestra en comprobantes B)
 *  - template:       ruta a una plantilla propia
 */
final class HtmlReport
{
    public function __construct(private readonly ?QrGenerator $qr = new QrGenerator())
    {
    }

    /**
     * @param array<string, mixed> $params
     */
    public function render(Invoice|ExportInvoice $invoice, array $params = []): string
    {
        $company = $invoice->getCompany();
        if ($company === null) {
            throw new \InvalidArgumentException('El comprobante debe tener los datos del emisor (setCompany) para imprimirse.');
        }
        if (!\RoyaltyFusion\Afip\Support\Cuit::isValid($company->getCuit())) {
            throw new \InvalidArgumentException('El emisor debe tener un CUIT válido para generar el QR y la representación impresa.');
        }

        $data = $invoice instanceof Invoice ? $this->invoiceData($invoice, $params) : $this->exportData($invoice);
        $data['company'] = $company;
        $data['invoice'] = $invoice;
        $data['params'] = $params;
        $data['copia'] = (string) ($params['copia'] ?? 'ORIGINAL');
        $data['logo'] = isset($params['logo']) ? $this->imageSource((string) $params['logo']) : null;
        $data['letra'] = TipoComprobante::clase($invoice->getTipoComprobante()) ?? '';
        $data['codigo'] = TipoComprobante::printCode($invoice->getTipoComprobante());
        $data['tipoNombre'] = $this->title($invoice->getTipoComprobante());
        $data['simbolo'] = Moneda::symbol($invoice->getMoneda());
        $data['qrUrl'] = null;
        $data['qrImage'] = null;
        if ($this->qr !== null && ($invoice->getCae() !== null || ($invoice instanceof Invoice && $invoice->getCaea() !== null))) {
            $data['qrUrl'] = $this->qr->buildUrl($invoice, $company->getCuit());
            if (QrGenerator::canRenderImage()) {
                $data['qrImage'] = $this->qr->toDataUri($data['qrUrl']);
            }
        }

        $template = (string) ($params['template'] ?? __DIR__ . '/templates/invoice.html.php');

        return self::renderTemplate($template, $data);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function invoiceData(Invoice $invoice, array $params): array
    {
        $tipo = $invoice->getTipoComprobante();
        $clase = TipoComprobante::clase($tipo);
        $discrimina = in_array($clase, ['A', 'M'], true);
        $client = $invoice->getClient();

        $rows = [];
        foreach ($invoice->getDetails() as $detail) {
            $rate = $detail->getAlicuotaIva() !== null ? AlicuotaIva::rate($detail->getAlicuotaIva()) : 0.0;
            $subtotal = $detail->getSubtotal();
            $net = $detail->isPrecioIncluyeIva() ? $subtotal / (1 + $rate / 100) : $subtotal;
            $gross = $detail->isPrecioIncluyeIva() ? $subtotal : $subtotal * (1 + $rate / 100);
            $unit = $detail->getPrecioUnitario();
            if ($discrimina && $detail->isPrecioIncluyeIva()) {
                $unit /= (1 + $rate / 100);
            } elseif (!$discrimina && !$detail->isPrecioIncluyeIva() && !TipoComprobante::sinIva($tipo)) {
                $unit *= (1 + $rate / 100);
            }
            $rows[] = [
                'codigo' => $detail->getCodigo(),
                'descripcion' => $detail->getDescripcion(),
                'cantidad' => $detail->getCantidad(),
                'unidad' => $detail->getUnidad(),
                'precio' => $unit,
                'bonificacion' => $detail->getBonificacionPorcentaje(),
                'subtotal' => $discrimina || TipoComprobante::sinIva($tipo) ? Num::round($net) : Num::round($gross),
                'alicuota' => $detail->getTratamientoIva() === 'gravado' && $detail->getAlicuotaIva() !== null
                    ? AlicuotaIva::description($detail->getAlicuotaIva())
                    : ($detail->getTratamientoIva() === 'exento' ? 'Exento' : 'No Gravado'),
                'subtotalConIva' => Num::round($gross),
            ];
        }

        $ivas = [];
        foreach (array_keys(AlicuotaIva::all()) as $id) {
            $ivas[$id] = 0.0;
        }
        foreach ($invoice->getIvas() as $iva) {
            $ivas[$iva->getId()] += $iva->getImporte();
        }

        $otrosNacionales = 0.0;
        foreach ($invoice->getTributos() as $tributo) {
            if (TipoTributo::isNationalIndirect($tributo->getId())) {
                $otrosNacionales += $tributo->getImporte();
            }
        }

        $transparencia = $params['transparenciaFiscal'] ?? ($clase === 'B');

        return [
            'type' => 'invoice',
            'discrimina' => $discrimina,
            'rows' => $rows,
            'ivas' => array_reverse($ivas, true),
            'client' => $client,
            'clientDoc' => $client === null ? '' : TipoDocumento::description($client->getTipoDoc()),
            'clientDocNro' => $client === null ? '' : (TipoDocumento::isCuitLike($client->getTipoDoc()) ? Cuit::format($client->getNumDoc()) : $client->getNumDoc()),
            'clientCondicion' => $client?->getCondicionIva() !== null ? CondicionIva::description((int) $client->getCondicionIva()) : '',
            'servicios' => Concepto::includesServices($invoice->getConcepto()),
            'transparencia' => (bool) $transparencia,
            'ivaContenido' => $invoice->getImpIva(),
            'otrosNacionales' => $otrosNacionales,
            'consumidorFinal' => $client?->getCondicionIva() === CondicionIva::CONSUMIDOR_FINAL,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function exportData(ExportInvoice $invoice): array
    {
        $rows = [];
        foreach ($invoice->getItems() as $item) {
            $rows[] = [
                'codigo' => $item->getCodigo(),
                'descripcion' => $item->getDescripcion(),
                'cantidad' => $item->getCantidad(),
                'unidad' => Exportacion::unidadDescription($item->getUnidad()),
                'precio' => $item->getPrecioUnitario(),
                'bonificacion' => $item->getBonificacion(),
                'subtotal' => $item->isBonificacionGeneral() ? -$item->getTotal() : $item->getTotal(),
            ];
        }

        return [
            'type' => 'export',
            'discrimina' => false,
            'rows' => $rows,
            'ivas' => [],
            'client' => null,
            'servicios' => false,
            'transparencia' => false,
            'consumidorFinal' => false,
        ];
    }

    private function title(int $tipo): string
    {
        $name = TipoComprobante::description($tipo);

        return mb_strtoupper(preg_replace('/\s+[ABCMEe]$/u', '', $name) ?? $name);
    }

    private function imageSource(string $logo): string
    {
        if (str_starts_with($logo, 'data:') || str_starts_with($logo, 'http')) {
            return $logo;
        }
        if (!is_file($logo)) {
            return '';
        }
        $mime = mime_content_type($logo) ?: 'image/png';

        return 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($logo));
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function renderTemplate(string $template, array $data): string
    {
        $e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $money = static fn (float $value): string => Num::display($value);
        $qty = static fn (float $value): string => Num::display($value, Num::decimals($value) > 2 ? min(6, Num::decimals($value)) : 2);
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            include $template;

            return (string) ob_get_clean();
        } catch (\Throwable $t) {
            ob_end_clean();
            throw $t;
        }
    }
}
