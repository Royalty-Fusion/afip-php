<?php
/**
 * Plantilla de representación impresa.
 *
 * Variables disponibles: $invoice, $company, $client, $rows, $ivas, $letra, $codigo, $tipoNombre,
 * $copia, $logo, $qrUrl, $qrImage, $simbolo, $discrimina, $servicios, $transparencia, $params,
 * y los helpers $e (escape), $money y $qty.
 *
 * @var \RoyaltyFusion\Afip\Model\Sale\Invoice|\RoyaltyFusion\Afip\Model\Export\ExportInvoice $invoice
 * @var \RoyaltyFusion\Afip\Model\Company $company
 */

use RoyaltyFusion\Afip\Catalog\AlicuotaIva;
use RoyaltyFusion\Afip\Catalog\CondicionIva;
use RoyaltyFusion\Afip\Catalog\Exportacion;
use RoyaltyFusion\Afip\Catalog\Moneda;
use RoyaltyFusion\Afip\Catalog\TipoComprobante;
use RoyaltyFusion\Afip\Support\Cuit;

$isExport = $type === 'export';
$address = $company->getAddress();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title><?= $e($tipoNombre . ' ' . $invoice->getNumeroCompleto()) ?></title>
<style>
    * { box-sizing: border-box; }
    body { font-family: "DejaVu Sans", Arial, Helvetica, sans-serif; font-size: 10px; color: #111; margin: 0; }
    .page { width: 100%; padding: 12px; }
    table { width: 100%; border-collapse: collapse; }
    .box { border: 1px solid #000; }
    .copia { text-align: center; font-weight: bold; font-size: 13px; padding: 4px; border: 1px solid #000; border-bottom: 0; }
    .header td { vertical-align: top; padding: 8px; }
    .letter { width: 64px; text-align: center; border: 1px solid #000; border-top: 0; }
    .letter .l { font-size: 34px; font-weight: bold; line-height: 40px; }
    .letter .c { font-size: 9px; font-weight: bold; }
    .company-name { font-size: 15px; font-weight: bold; margin-bottom: 6px; }
    .title { font-size: 17px; font-weight: bold; margin-bottom: 6px; }
    .muted { color: #333; }
    .section td { padding: 4px 8px; }
    .items { margin-top: 6px; }
    .items th { background: #e6e6e6; border: 1px solid #000; padding: 4px; font-size: 9px; }
    .items td { border-left: 1px solid #ccc; border-right: 1px solid #ccc; padding: 3px 4px; vertical-align: top; }
    .items tr:last-child td { border-bottom: 1px solid #000; }
    .r { text-align: right; }
    .c { text-align: center; }
    .totals td { padding: 3px 8px; }
    .totals .label { text-align: right; font-weight: bold; }
    .totals .value { text-align: right; width: 130px; }
    .grand { font-size: 13px; }
    .legend { font-size: 9px; padding: 4px 8px; }
    .transparency { border: 1px solid #000; padding: 6px 8px; margin-top: 6px; }
    .footer td { vertical-align: middle; padding: 8px; }
    .qr img { width: 110px; height: 110px; }
    .cae { text-align: right; font-size: 11px; }
    .cae b { font-size: 12px; }
    .arca { font-weight: bold; font-size: 14px; font-style: italic; }
</style>
</head>
<body>
<div class="page">
    <div class="copia"><?= $e($copia) ?></div>
    <table class="box header">
        <tr>
            <td style="width: 46%;">
                <?php if ($logo): ?><img src="<?= $e($logo) ?>" alt="" style="max-height: 60px; max-width: 220px; margin-bottom: 6px;"><br><?php endif; ?>
                <div class="company-name"><?= $e($company->getNombreFantasia() ?: $company->getRazonSocial()) ?></div>
                <div><b>Razón Social:</b> <?= $e($company->getRazonSocial()) ?></div>
                <?php if ($address !== null): ?><div><b>Domicilio Comercial:</b> <?= $e((string) $address) ?></div><?php endif; ?>
                <div><b>Condición frente al IVA:</b> <?= $e(CondicionIva::description($company->getCondicionIva())) ?></div>
                <?php if ($company->getTelefono() || $company->getEmail()): ?>
                    <div class="muted"><?= $e(implode(' · ', array_filter([$company->getTelefono(), $company->getEmail(), $company->getWeb()]))) ?></div>
                <?php endif; ?>
            </td>
            <td class="letter">
                <div class="l"><?= $e($letra) ?></div>
                <div class="c">COD. <?= $e($codigo) ?></div>
            </td>
            <td>
                <div class="title"><?= $e($tipoNombre) ?></div>
                <div><b>Punto de Venta:</b> <?= sprintf('%05d', $invoice->getPuntoVenta()) ?> &nbsp; <b>Comp. Nro:</b> <?= sprintf('%08d', (int) $invoice->getNumero()) ?></div>
                <div><b>Fecha de Emisión:</b> <?= $e($invoice->getFechaEmision()->format('d/m/Y')) ?></div>
                <div><b>CUIT:</b> <?= $e(Cuit::format($company->getCuit())) ?></div>
                <div><b>Ingresos Brutos:</b> <?= $e($company->getIngresosBrutos() ?? Cuit::format($company->getCuit())) ?></div>
                <?php if ($company->getInicioActividades()): ?>
                    <div><b>Fecha de Inicio de Actividades:</b> <?= $e($company->getInicioActividades()->format('d/m/Y')) ?></div>
                <?php endif; ?>
            </td>
        </tr>
    </table>

    <?php if (!$isExport && $servicios): ?>
    <table class="box section" style="border-top: 0;">
        <tr>
            <td><b>Período Facturado Desde:</b> <?= $e($invoice->getFechaServicioDesde()?->format('d/m/Y')) ?></td>
            <td><b>Hasta:</b> <?= $e($invoice->getFechaServicioHasta()?->format('d/m/Y')) ?></td>
            <td><b>Fecha de Vto. para el pago:</b> <?= $e($invoice->getFechaVencimientoPago()?->format('d/m/Y')) ?></td>
        </tr>
    </table>
    <?php elseif (!$isExport && $invoice->getFechaVencimientoPago() !== null): ?>
    <table class="box section" style="border-top: 0;">
        <tr><td><b>Fecha de Vto. para el pago:</b> <?= $e($invoice->getFechaVencimientoPago()->format('d/m/Y')) ?></td></tr>
    </table>
    <?php endif; ?>

    <table class="box section" style="margin-top: 4px;">
        <?php if ($isExport): ?>
        <tr>
            <td style="width: 50%;"><b>Cliente:</b> <?= $e($invoice->getCliente()) ?></td>
            <td><b>Domicilio:</b> <?= $e($invoice->getDomicilioCliente()) ?></td>
        </tr>
        <tr>
            <td><b>CUIT País / ID Impositivo:</b> <?= $e(trim(($invoice->getCuitPaisCliente() ?? '') . ' ' . ($invoice->getIdImpositivo() ?? ''))) ?></td>
            <td><b>País de destino:</b> <?= $e($invoice->getPaisDestino()) ?> &nbsp; <b>Incoterms:</b> <?= $e(trim(($invoice->getIncoterms() ?? '') . ' ' . ($invoice->getIncotermsDescripcion() ?? ''))) ?></td>
        </tr>
        <tr>
            <td><b>Tipo de exportación:</b> <?= $e(Exportacion::tipoExportacionDescription($invoice->getTipoExportacion())) ?></td>
            <td><b>Forma de pago:</b> <?= $e($invoice->getFormaPago()) ?></td>
        </tr>
        <?php else: ?>
        <tr>
            <td style="width: 50%;"><b><?= $e($clientDoc) ?>:</b> <?= $e($clientDocNro) ?></td>
            <td><b>Apellido y Nombre / Razón Social:</b> <?= $e($client?->getRazonSocial()) ?></td>
        </tr>
        <tr>
            <td><b>Condición frente al IVA:</b> <?= $e($clientCondicion) ?></td>
            <td><b>Domicilio:</b> <?= $e($client?->getAddress() !== null ? (string) $client->getAddress() : '') ?></td>
        </tr>
        <tr>
            <td><b>Condición de venta:</b> <?= $e($params['condicionVenta'] ?? 'Contado') ?></td>
            <td><?php if (!empty($params['remito'])): ?><b>Remito:</b> <?= $e($params['remito']) ?><?php endif; ?></td>
        </tr>
        <?php endif; ?>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th>Código</th>
                <th>Producto / Servicio</th>
                <th>Cantidad</th>
                <th>U. Medida</th>
                <th>Precio Unit.</th>
                <th><?= $isExport ? 'Bonif.' : '% Bonif' ?></th>
                <th>Subtotal</th>
                <?php if ($discrimina): ?><th>Alícuota IVA</th><th>Subtotal c/IVA</th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td><?= $e($row['codigo']) ?></td>
                <td><?= nl2br($e($row['descripcion'])) ?></td>
                <td class="r"><?= $qty((float) $row['cantidad']) ?></td>
                <td class="c"><?= $e($row['unidad']) ?></td>
                <td class="r"><?= $money((float) $row['precio']) ?></td>
                <td class="r"><?= $money((float) $row['bonificacion']) ?></td>
                <td class="r"><?= $money((float) $row['subtotal']) ?></td>
                <?php if ($discrimina): ?>
                    <td class="c"><?= $e($row['alicuota']) ?></td>
                    <td class="r"><?= $money((float) $row['subtotalConIva']) ?></td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        <?php if ($rows === []): ?>
            <tr><td colspan="<?= $discrimina ? 9 : 7 ?>" class="c muted">Sin detalle</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <?php if (!$isExport && $invoice->getTributos() !== []): ?>
    <table class="items">
        <thead><tr><th>Otros tributos</th><th>Detalle</th><th>Base imponible</th><th>Alícuota %</th><th>Importe</th></tr></thead>
        <tbody>
        <?php foreach ($invoice->getTributos() as $t): ?>
            <tr>
                <td><?= $e(\RoyaltyFusion\Afip\Catalog\TipoTributo::description($t->getId())) ?></td>
                <td><?= $e($t->getDescripcion()) ?></td>
                <td class="r"><?= $money($t->getBaseImp()) ?></td>
                <td class="r"><?= $money($t->getAlicuota()) ?></td>
                <td class="r"><?= $money($t->getImporte()) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <?php if (!$isExport && $invoice->getCbtesAsociados() !== []): ?>
    <div class="legend"><b>Comprobantes asociados:</b>
        <?= $e(implode(', ', array_map(static fn ($a): string => sprintf('%s %05d-%08d', TipoComprobante::description($a->getTipo()), $a->getPuntoVenta(), $a->getNumero()), $invoice->getCbtesAsociados()))) ?>
    </div>
    <?php endif; ?>
    <?php if (!$isExport && $invoice->getPeriodoAsociado() !== null): ?>
    <div class="legend"><b>Período asociado:</b> <?= $e($invoice->getPeriodoAsociado()->getDesde()->format('d/m/Y')) ?> al <?= $e($invoice->getPeriodoAsociado()->getHasta()->format('d/m/Y')) ?></div>
    <?php endif; ?>

    <table class="box totals" style="margin-top: 6px;">
        <?php if ($isExport): ?>
            <tr class="grand"><td class="label">Importe Total: <?= $e($simbolo) ?></td><td class="value"><b><?= $money($invoice->getImpTotal()) ?></b></td></tr>
        <?php elseif ($discrimina): ?>
            <tr><td class="label">Importe Neto Gravado: <?= $e($simbolo) ?></td><td class="value"><?= $money($invoice->getImpNeto()) ?></td></tr>
            <?php if ($invoice->getImpNoGravado() > 0): ?><tr><td class="label">Importe Neto No Gravado: <?= $e($simbolo) ?></td><td class="value"><?= $money($invoice->getImpNoGravado()) ?></td></tr><?php endif; ?>
            <?php if ($invoice->getImpExento() > 0): ?><tr><td class="label">Importe Exento: <?= $e($simbolo) ?></td><td class="value"><?= $money($invoice->getImpExento()) ?></td></tr><?php endif; ?>
            <?php foreach ($ivas as $id => $amount): ?>
                <tr><td class="label">IVA <?= $e(AlicuotaIva::description((int) $id)) ?>: <?= $e($simbolo) ?></td><td class="value"><?= $money($amount) ?></td></tr>
            <?php endforeach; ?>
            <tr><td class="label">Importe Otros Tributos: <?= $e($simbolo) ?></td><td class="value"><?= $money($invoice->getImpTributos()) ?></td></tr>
            <tr class="grand"><td class="label">Importe Total: <?= $e($simbolo) ?></td><td class="value"><b><?= $money($invoice->getImpTotal()) ?></b></td></tr>
        <?php else: ?>
            <tr><td class="label">Subtotal: <?= $e($simbolo) ?></td><td class="value"><?= $money($invoice->getImpTotal() - $invoice->getImpTributos()) ?></td></tr>
            <tr><td class="label">Importe Otros Tributos: <?= $e($simbolo) ?></td><td class="value"><?= $money($invoice->getImpTributos()) ?></td></tr>
            <tr class="grand"><td class="label">Importe Total: <?= $e($simbolo) ?></td><td class="value"><b><?= $money($invoice->getImpTotal()) ?></b></td></tr>
        <?php endif; ?>
    </table>

    <?php if ($invoice->getMoneda() !== Moneda::PESOS): ?>
    <div class="legend"><b>Moneda:</b> <?= $e(Moneda::description($invoice->getMoneda())) ?> &nbsp; <b>Tipo de cambio:</b> <?= $e(rtrim(rtrim(number_format($invoice->getCotizacion(), 6, ',', '.'), '0'), ',')) ?>
        <?php if ($invoice->getPagoMismaMoneda() !== null): ?> &nbsp; <b>Cancela en misma moneda:</b> <?= $invoice->getPagoMismaMoneda() ? 'Sí' : 'No' ?><?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($transparencia): ?>
    <div class="transparency">
        <b>Régimen de Transparencia Fiscal al Consumidor (Ley 27.743)</b><br>
        IVA Contenido: <?= $e($simbolo) ?> <?= $money((float) $ivaContenido) ?> &nbsp;&nbsp;
        Otros Impuestos Nacionales Indirectos: <?= $e($simbolo) ?> <?= $money((float) $otrosNacionales) ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($leyendaRg5003)): ?>
    <div class="legend"><b><?= $e($leyendaRg5003) ?></b></div>
    <?php endif; ?>

    <?php if (!$isExport && $consumidorFinal && $letra === 'B'): ?>
    <div class="legend"><b>A CONSUMIDOR FINAL</b></div>
    <?php endif; ?>

    <?php foreach (($isExport ? array_filter([$invoice->getObservacionesComerciales(), $invoice->getObservaciones()]) : array_filter(array_merge($invoice->getLeyendas(), [$invoice->getObservaciones()]))) as $leyenda): ?>
        <div class="legend"><?= nl2br($e($leyenda)) ?></div>
    <?php endforeach; ?>

    <table class="box footer" style="margin-top: 8px;">
        <tr>
            <td class="qr" style="width: 130px;">
                <?php if ($qrImage): ?><img src="<?= $e($qrImage) ?>" alt="QR"><?php elseif ($qrUrl): ?><span class="muted" style="font-size: 7px; word-break: break-all;"><?= $e($qrUrl) ?></span><?php endif; ?>
            </td>
            <td>
                <div class="arca">ARCA</div>
                <div><b>Comprobante Autorizado</b></div>
                <div class="muted" style="font-size: 8px;">Esta Agencia no se responsabiliza por los datos ingresados en el detalle de la operación</div>
            </td>
            <td class="cae">
                <?php if ($invoice->getCae()): ?>
                    <div><?= !$isExport && $invoice->getCaea() && !$invoice->getCae() ? 'CAEA' : 'CAE' ?> N°: <b><?= $e($invoice->getCae()) ?></b></div>
                    <div>Fecha de Vto. de CAE: <b><?= $e($invoice->getCaeVencimiento()?->format('d/m/Y')) ?></b></div>
                <?php elseif (!$isExport && $invoice->getCaea()): ?>
                    <div>CAEA N°: <b><?= $e($invoice->getCaea()) ?></b></div>
                <?php else: ?>
                    <div class="muted">Comprobante no autorizado</div>
                <?php endif; ?>
            </td>
        </tr>
    </table>
    <?php if (!empty($params['footer'])): ?><div class="legend c"><?= nl2br($e($params['footer'])) ?></div><?php endif; ?>
</div>
</body>
</html>
