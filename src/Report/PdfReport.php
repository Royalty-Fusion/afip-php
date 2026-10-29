<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Report;

use RoyaltyFusion\Afip\Model\Export\ExportInvoice;
use RoyaltyFusion\Afip\Model\Sale\Invoice;

/**
 * Genera el PDF de la representación impresa. Requiere dompdf/dompdf.
 */
final class PdfReport
{
    public function __construct(private readonly HtmlReport $html = new HtmlReport())
    {
    }

    /**
     * @param array<string, mixed> $params ver {@see HtmlReport}
     */
    public function render(Invoice|ExportInvoice $invoice, array $params = []): string
    {
        if (!class_exists(\Dompdf\Dompdf::class)) {
            throw new \RuntimeException('Para generar PDF instale dompdf: composer require dompdf/dompdf');
        }
        $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
        $dompdf->loadHtml($this->html->render($invoice, $params), 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
