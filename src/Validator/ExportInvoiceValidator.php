<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Validator;

use RoyaltyFusion\Afip\Catalog\Exportacion;
use RoyaltyFusion\Afip\Catalog\Moneda;
use RoyaltyFusion\Afip\Catalog\TipoComprobante;
use RoyaltyFusion\Afip\Model\Export\ExportInvoice;
use RoyaltyFusion\Afip\Support\Cuit;
use RoyaltyFusion\Afip\Support\Date;
use RoyaltyFusion\Afip\Support\Num;

/**
 * Valida un comprobante de exportación (WSFEXv1) antes de enviarlo a ARCA.
 */
final class ExportInvoiceValidator
{
    public function __construct(private readonly ValidatorOptions $options = new ValidatorOptions())
    {
    }

    public function validate(ExportInvoice $invoice): ValidationResult
    {
        $r = new ValidationResult();
        $tipo = $invoice->getTipoComprobante();
        $tol = $this->options->tolerancia;

        if (!TipoComprobante::isWsfex($tipo)) {
            $r->error('CBTE_TIPO_INVALIDO', 'tipoComprobante', 'El tipo de comprobante de exportación debe ser 19 (Factura E), 20 (Nota de Débito E) o 21 (Nota de Crédito E).');

            return $r;
        }
        $isFactura = $tipo === TipoComprobante::FACTURA_EXPORTACION;

        if ($invoice->getPuntoVenta() < 1 || $invoice->getPuntoVenta() > 99998) {
            $r->error('PTO_VTA_INVALIDO', 'puntoVenta', 'El punto de venta debe estar entre 1 y 99998.');
        }
        if ($invoice->getNumero() !== null && ($invoice->getNumero() < 1 || $invoice->getNumero() > 99999999)) {
            $r->error('NUMERO_INVALIDO', 'numero', 'El número de comprobante debe estar entre 1 y 99999999.');
        }
        if ($invoice->getId() !== null && $invoice->getId() < 1) {
            $r->error('ID_INVALIDO', 'id', 'El Id de la solicitud debe ser mayor a 0.');
        }

        // Fecha
        $hoy = $this->options->fechaReferencia ?? Date::today();
        $dias = $invoice->getTipoExportacion() === Exportacion::EXPORTACION_BIENES
            ? $this->options->diasFechaProductos
            : $this->options->diasFechaServicios;
        if (abs(Date::diffDays($hoy, $invoice->getFechaEmision())) > $dias) {
            $r->warning('FECHA_FUERA_DE_RANGO', 'fechaEmision', sprintf('La fecha del comprobante difiere en más de %d días de la fecha actual; ARCA podría rechazarla.', $dias));
        }

        // Tipo de exportación y permisos de embarque
        $tipoExpo = $invoice->getTipoExportacion();
        if (!Exportacion::tipoExportacionExists($tipoExpo)) {
            $r->error('TIPO_EXPO_INVALIDO', 'tipoExportacion', 'El tipo de exportación debe ser 1 (bienes), 2 (servicios) o 4 (otros).');
        }
        $permiso = $invoice->getPermisoExistente();
        if ($isFactura && $tipoExpo === Exportacion::EXPORTACION_BIENES) {
            if (!in_array($permiso, ['S', 'N'], true)) {
                $r->error('PERMISO_EXISTENTE_REQUERIDO', 'permisoExistente', 'En facturas de exportación de bienes debe indicar si existe permiso de embarque ("S" o "N").');
            } elseif ($permiso === 'S' && $invoice->getPermisos() === []) {
                $r->error('PERMISOS_REQUERIDOS', 'permisos', 'Si existe permiso de embarque debe informar al menos un permiso.');
            } elseif ($permiso === 'N' && $invoice->getPermisos() !== []) {
                $r->error('PERMISOS_NO_CORRESPONDEN', 'permisos', 'No deben informarse permisos si Permiso_existente es "N".');
            }
        } else {
            if ($permiso !== null && $permiso !== '') {
                $r->error('PERMISO_EXISTENTE_NO_CORRESPONDE', 'permisoExistente', 'El indicador de permiso de embarque solo se informa en facturas de exportación de bienes.');
            }
            if ($invoice->getPermisos() !== []) {
                $r->error('PERMISOS_NO_CORRESPONDEN', 'permisos', 'Los permisos de embarque solo se informan en facturas de exportación de bienes.');
            }
        }
        foreach ($invoice->getPermisos() as $i => $p) {
            if (!preg_match('/^[A-Za-z0-9]{16}$/', $p->getId())) {
                $r->error('PERMISO_INVALIDO', sprintf('permisos[%d].id', $i), 'El permiso de embarque debe tener 16 caracteres alfanuméricos.');
            }
            if ($p->getPaisDestino() <= 0) {
                $r->error('PERMISO_DESTINO', sprintf('permisos[%d].paisDestino', $i), 'Debe informar el país de destino de la mercadería.');
            }
        }

        // Cliente
        if ($invoice->getPaisDestino() <= 0) {
            $r->error('PAIS_DESTINO_REQUERIDO', 'paisDestino', 'Debe informar el país de destino del comprobante (FEXGetPARAM_DST_pais).');
        }
        if (trim($invoice->getCliente()) === '') {
            $r->error('CLIENTE_REQUERIDO', 'cliente', 'Debe informar el nombre o razón social del cliente.');
        }
        if (trim((string) $invoice->getDomicilioCliente()) === '') {
            $r->error('DOMICILIO_REQUERIDO', 'domicilioCliente', 'Debe informar el domicilio del cliente.');
        }
        $cuitPais = (string) $invoice->getCuitPaisCliente();
        if ($cuitPais === '' && trim((string) $invoice->getIdImpositivo()) === '') {
            $r->error('CLIENTE_ID_REQUERIDO', 'cuitPaisCliente', 'Debe informar el CUIT del país del cliente (FEXGetPARAM_DST_CUIT) o su identificador impositivo.');
        }
        if ($cuitPais !== '' && !Cuit::isValid($cuitPais)) {
            $r->error('CUIT_PAIS_INVALIDO', 'cuitPaisCliente', sprintf('El CUIT de país "%s" no es válido.', $cuitPais));
        }

        // Moneda
        $moneda = $invoice->getMoneda();
        if (!preg_match('/^[A-Z0-9]{3}$/', $moneda)) {
            $r->error('MONEDA_INVALIDA', 'moneda', 'El código de moneda debe tener 3 caracteres (FEXGetPARAM_MON).');
        } elseif (!Moneda::exists($moneda)) {
            $r->warning('MONEDA_DESCONOCIDA', 'moneda', sprintf('La moneda "%s" no figura en el catálogo local.', $moneda));
        }
        if ($moneda === Moneda::PESOS) {
            if ($invoice->getCotizacion() != 1.0 && $invoice->getCotizacion() != 0.0) {
                $r->error('COTIZACION_INVALIDA', 'cotizacion', 'Para moneda PES la cotización debe ser 1.');
            }
            if ($invoice->getPagoMismaMoneda() !== null) {
                $r->error('CAN_MIS_MON_EXT_NO_CORRESPONDE', 'pagoMismaMoneda', 'CanMisMonExt no se informa si la moneda es PES.');
            }
        } else {
            if (!($invoice->getCotizacion() > 0)) {
                $r->error('COTIZACION_INVALIDA', 'cotizacion', 'Para moneda extranjera la cotización debe ser mayor a 0.');
            } elseif (Num::decimals($invoice->getCotizacion()) > 6) {
                $r->error('COTIZACION_DECIMALES', 'cotizacion', 'La cotización admite como máximo 6 decimales.');
            }
            if (!$isFactura && $invoice->getPagoMismaMoneda() !== null) {
                $r->error('CAN_MIS_MON_EXT_NO_CORRESPONDE', 'pagoMismaMoneda', 'CanMisMonExt no se informa en notas de crédito/débito de exportación.');
            }
        }

        // Idioma, incoterms, pago
        if (!Exportacion::idiomaExists($invoice->getIdioma())) {
            $r->error('IDIOMA_INVALIDO', 'idioma', 'El idioma debe ser 1 (español), 2 (inglés) o 3 (portugués).');
        }
        $incoterms = $invoice->getIncoterms();
        if ($incoterms !== null && $incoterms !== '' && !Exportacion::incotermExists($incoterms)) {
            $r->error('INCOTERMS_INVALIDO', 'incoterms', sprintf('El Incoterm "%s" no es válido (FEXGetPARAM_Incoterms).', $incoterms));
        }
        if ($isFactura && $tipoExpo === Exportacion::EXPORTACION_BIENES && ($incoterms === null || $incoterms === '')) {
            $r->warning('INCOTERMS_RECOMENDADO', 'incoterms', 'Se recomienda informar el Incoterm en exportaciones de bienes.');
        }
        if (mb_strlen((string) $invoice->getIncotermsDescripcion()) > 20) {
            $r->error('INCOTERMS_DS_LARGO', 'incotermsDescripcion', 'La descripción del Incoterm admite hasta 20 caracteres.');
        }
        if ($isFactura && in_array($tipoExpo, [Exportacion::SERVICIOS, Exportacion::OTROS], true) && $invoice->getFechaPago() === null) {
            $r->error('FECHA_PAGO_REQUERIDA', 'fechaPago', 'En exportación de servicios u otros debe informar la fecha de pago.');
        }
        if (mb_strlen((string) $invoice->getObservacionesComerciales()) > 4000) {
            $r->error('OBS_COMERCIALES_LARGO', 'observacionesComerciales', 'Las observaciones comerciales admiten hasta 4000 caracteres.');
        }
        if (mb_strlen((string) $invoice->getObservaciones()) > 1000) {
            $r->error('OBS_LARGO', 'observaciones', 'Las observaciones admiten hasta 1000 caracteres.');
        }
        if (mb_strlen((string) $invoice->getFormaPago()) > 50) {
            $r->error('FORMA_PAGO_LARGO', 'formaPago', 'La forma de pago admite hasta 50 caracteres.');
        }

        // Asociados
        if (!$isFactura && $invoice->getCbtesAsociados() === []) {
            $r->error('CBTE_ASOC_REQUERIDO', 'cbtesAsociados', 'Las notas de crédito/débito de exportación deben informar el comprobante asociado.');
        }
        foreach ($invoice->getCbtesAsociados() as $i => $asoc) {
            $field = sprintf('cbtesAsociados[%d]', $i);
            if (!TipoComprobante::isWsfex($asoc->getTipo())) {
                $r->error('CBTE_ASOC_TIPO', $field . '.tipo', 'El comprobante asociado debe ser de exportación (19, 20 o 21).');
            }
            if ($asoc->getPuntoVenta() < 1 || $asoc->getNumero() < 1) {
                $r->error('CBTE_ASOC_NUMERO', $field, 'El punto de venta y número del comprobante asociado deben ser mayores a 0.');
            }
            if (!Cuit::isValid($asoc->getCuit())) {
                $r->error('CBTE_ASOC_CUIT', $field . '.cuit', 'El CUIT del comprobante asociado no es válido.');
            }
        }

        // Ítems e importe total
        if ($invoice->getItems() === []) {
            $r->error('ITEMS_REQUERIDOS', 'items', 'El comprobante de exportación debe tener al menos un ítem.');
        }
        foreach ($invoice->getItems() as $i => $item) {
            $field = sprintf('items[%d]', $i);
            if (trim($item->getDescripcion()) === '') {
                $r->error('ITEM_DESCRIPCION', $field . '.descripcion', 'El ítem debe tener descripción.');
            } elseif (mb_strlen($item->getDescripcion()) > 4000) {
                $r->error('ITEM_DESCRIPCION_LARGA', $field . '.descripcion', 'La descripción del ítem admite hasta 4000 caracteres.');
            }
            if (!Exportacion::unidadExists($item->getUnidad())) {
                $r->warning('ITEM_UNIDAD_DESCONOCIDA', $field . '.unidad', sprintf('La unidad de medida %d no figura en el catálogo local (FEXGetPARAM_UMed).', $item->getUnidad()));
            }
            if ($item->getCantidad() < 0 || $item->getPrecioUnitario() < 0 || $item->getBonificacion() < 0 || $item->getTotal() < 0) {
                $r->error('ITEM_NEGATIVO', $field, 'Cantidad, precio, bonificación y total del ítem no pueden ser negativos.');
                continue;
            }
            if ($item->isBonificacionGeneral()) {
                if ($item->getCantidad() != 0.0 || $item->getPrecioUnitario() != 0.0) {
                    $r->error('ITEM_BONIFICACION', $field, 'En ítems de bonificación (unidad 99) la cantidad y el precio deben ser 0 y el importe va en el total.');
                }
                continue;
            }
            if ($item->getUnidad() !== Exportacion::UMED_SENIAS_ANTICIPOS) {
                $expected = Num::round($item->getCantidad() * $item->getPrecioUnitario() - $item->getBonificacion());
                if (!Num::equals($expected, $item->getTotal(), $tol)) {
                    $r->error('ITEM_TOTAL', $field . '.total', sprintf(
                        'El total del ítem (%s) debe ser cantidad × precio − bonificación (%s).',
                        Num::format($item->getTotal()),
                        Num::format($expected)
                    ));
                }
            }
        }
        $total = $invoice->getImpTotal();
        if ($total <= 0) {
            $r->error('TOTAL_INVALIDO', 'impTotal', 'El importe total debe ser mayor a 0.');
        } elseif (Num::decimals($total) > 2) {
            $r->error('IMPORTE_DECIMALES', 'impTotal', 'El importe total admite como máximo 2 decimales.');
        } elseif ($invoice->getItems() !== [] && !Num::equals($total, $invoice->calculateTotal(), $tol)) {
            $r->error('TOTAL_NO_CUADRA', 'impTotal', sprintf(
                'El importe total (%s) debe ser igual a la suma de los ítems menos bonificaciones (%s).',
                Num::format($total),
                Num::format($invoice->calculateTotal())
            ));
        }

        foreach ($invoice->getOpcionales() as $i => $opcional) {
            if (trim($opcional->getId()) === '' || trim($opcional->getValor()) === '') {
                $r->error('OPCIONAL_INCOMPLETO', sprintf('opcionales[%d]', $i), 'El opcional debe tener Id y valor.');
            }
        }

        return $r;
    }
}
