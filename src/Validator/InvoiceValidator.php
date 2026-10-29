<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Validator;

use RoyaltyFusion\Afip\Calculator\InvoiceCalculator;
use RoyaltyFusion\Afip\Catalog\AlicuotaIva;
use RoyaltyFusion\Afip\Catalog\Concepto;
use RoyaltyFusion\Afip\Catalog\CondicionIva;
use RoyaltyFusion\Afip\Catalog\Moneda;
use RoyaltyFusion\Afip\Catalog\TipoComprobante;
use RoyaltyFusion\Afip\Catalog\TipoDocumento;
use RoyaltyFusion\Afip\Catalog\TipoOpcional;
use RoyaltyFusion\Afip\Catalog\TipoTributo;
use RoyaltyFusion\Afip\Model\Sale\Invoice;
use RoyaltyFusion\Afip\Support\Cbu;
use RoyaltyFusion\Afip\Support\Cuit;
use RoyaltyFusion\Afip\Support\Date;
use RoyaltyFusion\Afip\Support\Num;

/**
 * Valida un comprobante de WSFEv1 antes de enviarlo a ARCA.
 *
 * Replica las validaciones documentadas en el Manual del Desarrollador de WSFEv1
 * (RG 4291 y modificatorias: RG 4367 FCE, RG 4540, RG 5616, RG 5700) para que los
 * errores se detecten localmente y el comprobante llegue a ARCA completo y consistente.
 */
final class InvoiceValidator
{
    public const MODE_CAE = 'cae';
    public const MODE_CAEA = 'caea';

    private const MAX_PTO_VTA = 99998;
    private const MAX_NUMERO = 99999999;
    private const MAX_IMPORTE = 9999999999999.99;

    public function __construct(private readonly ValidatorOptions $options = new ValidatorOptions())
    {
    }

    public function getOptions(): ValidatorOptions
    {
        return $this->options;
    }

    public function validate(Invoice $invoice, string $mode = self::MODE_CAE): ValidationResult
    {
        $r = new ValidationResult();
        $tipo = $invoice->getTipoComprobante();

        if (!TipoComprobante::exists($tipo) || !TipoComprobante::isWsfe($tipo)) {
            $r->error('CBTE_TIPO_INVALIDO', 'tipoComprobante', sprintf(
                'El tipo de comprobante %d no es válido para WSFEv1%s.',
                $tipo,
                TipoComprobante::isWsfex($tipo) ? ' (los comprobantes de exportación se autorizan con WSFEX)' : ''
            ), 10007);

            return $r;
        }

        $this->validateHeader($invoice, $r);
        $this->validateEmisor($invoice, $r);
        $this->validateFechas($invoice, $r, $mode);
        $this->validateReceptor($invoice, $r);
        $this->validateImportes($invoice, $r);
        $this->validateIva($invoice, $r);
        $this->validateTributos($invoice, $r);
        $this->validateMoneda($invoice, $r);
        $this->validateAsociados($invoice, $r);
        $this->validateOpcionales($invoice, $r);
        $this->validateFce($invoice, $r);
        $this->validateCompradores($invoice, $r);
        $this->validateActividades($invoice, $r);
        $this->validateDetails($invoice, $r);
        if ($mode === self::MODE_CAEA) {
            $this->validateCaea($invoice, $r);
        }

        return $r;
    }

    /**
     * Valida un lote de comprobantes a enviar en un mismo FECAESolicitar.
     *
     * @param Invoice[] $invoices
     */
    public function validateBatch(array $invoices, string $mode = self::MODE_CAE): ValidationResult
    {
        $r = new ValidationResult();
        if ($invoices === []) {
            $r->error('LOTE_VACIO', 'invoices', 'Debe enviar al menos un comprobante.');

            return $r;
        }
        if (count($invoices) > $this->options->maxRegistrosPorRequest) {
            $r->error('LOTE_EXCEDIDO', 'invoices', sprintf(
                'Se pueden enviar como máximo %d comprobantes por solicitud (FECompTotXRequest).',
                $this->options->maxRegistrosPorRequest
            ), 10002);
        }

        $first = reset($invoices);
        $expected = null;
        foreach (array_values($invoices) as $i => $invoice) {
            $r->merge($this->validate($invoice, $mode), sprintf('[%d].', $i));
            if ($invoice->getTipoComprobante() !== $first->getTipoComprobante() || $invoice->getPuntoVenta() !== $first->getPuntoVenta()) {
                $r->error('LOTE_HETEROGENEO', sprintf('[%d]', $i), 'Todos los comprobantes del lote deben tener el mismo tipo y punto de venta.');
            }
            if ($invoice->getNumero() !== null) {
                if ($expected !== null && $invoice->getNumero() !== $expected) {
                    $r->error('LOTE_NO_CORRELATIVO', sprintf('[%d].numero', $i), sprintf(
                        'Los comprobantes del lote deben ser correlativos: se esperaba el número %d.',
                        $expected
                    ), 10016);
                }
                $expected = (int) $invoice->getNumeroHasta() + 1;
            }
        }

        return $r;
    }

    private function validateHeader(Invoice $invoice, ValidationResult $r): void
    {
        $ptoVta = $invoice->getPuntoVenta();
        if ($ptoVta < 1 || $ptoVta > self::MAX_PTO_VTA) {
            $r->error('PTO_VTA_INVALIDO', 'puntoVenta', sprintf('El punto de venta debe estar entre 1 y %d.', self::MAX_PTO_VTA), 10004);
        }

        if (!Concepto::exists($invoice->getConcepto())) {
            $r->error('CONCEPTO_INVALIDO', 'concepto', 'El concepto debe ser 1 (Productos), 2 (Servicios) o 3 (Productos y Servicios).', 10011);
        }

        $numero = $invoice->getNumero();
        if ($numero === null) {
            return;
        }
        if ($numero < 1 || $numero > self::MAX_NUMERO) {
            $r->error('NUMERO_INVALIDO', 'numero', sprintf('El número de comprobante debe estar entre 1 y %d.', self::MAX_NUMERO));

            return;
        }
        $hasta = (int) $invoice->getNumeroHasta();
        if ($hasta < $numero || $hasta > self::MAX_NUMERO) {
            $r->error('NUMERO_HASTA_INVALIDO', 'numeroHasta', 'El número "hasta" debe ser mayor o igual al número "desde".');
        } elseif ($hasta !== $numero) {
            $clase = $invoice->getClase();
            $docTipo = $invoice->getClient()?->getTipoDoc();
            if (!in_array($clase, ['B', 'C'], true) || $docTipo !== TipoDocumento::OTRO || TipoComprobante::isFce($invoice->getTipoComprobante())) {
                $r->error('RANGO_NO_PERMITIDO', 'numeroHasta', 'Solo pueden informarse rangos de comprobantes (CbteDesde ≠ CbteHasta) en clase B o C a consumidor final sin identificar (DocTipo 99).');
            }
        }
    }

    private function validateEmisor(Invoice $invoice, ValidationResult $r): void
    {
        $company = $invoice->getCompany();
        if ($company === null) {
            return;
        }
        if ($company->getCuit() !== '' && !Cuit::isValid($company->getCuit())) {
            $r->error('EMISOR_CUIT_INVALIDO', 'company.cuit', sprintf('El CUIT del emisor "%s" no es válido.', $company->getCuit()));
        }
        if (!$this->options->validarCondicionEmisor) {
            return;
        }
        $clase = $invoice->getClase();
        if ($clase !== null && !in_array($clase, CondicionIva::issuableClasses($company->getCondicionIva()), true)) {
            $r->error('CLASE_NO_EMITIBLE', 'tipoComprobante', sprintf(
                'Un emisor "%s" no puede emitir comprobantes clase %s.',
                CondicionIva::description($company->getCondicionIva()),
                $clase
            ), 10005);
        }
    }

    private function validateFechas(Invoice $invoice, ValidationResult $r, string $mode): void
    {
        $tipo = $invoice->getTipoComprobante();
        $fecha = $invoice->getFechaEmision();
        $servicios = Concepto::includesServices($invoice->getConcepto());

        if ($mode === self::MODE_CAE) {
            $hoy = $this->options->fechaReferencia ?? Date::today();
            $dias = $servicios ? $this->options->diasFechaServicios : $this->options->diasFechaProductos;
            $diff = Date::diffDays($hoy, $fecha);
            if (abs($diff) > $dias) {
                $r->error('FECHA_FUERA_DE_RANGO', 'fechaEmision', sprintf(
                    'La fecha del comprobante (%s) no puede diferir en más de %d días de la fecha actual (%s) para el concepto %s.',
                    $fecha->format('d/m/Y'),
                    $dias,
                    $hoy->format('d/m/Y'),
                    Concepto::description($invoice->getConcepto())
                ), 10016);
            }
        }

        $desde = $invoice->getFechaServicioDesde();
        $hasta = $invoice->getFechaServicioHasta();
        $vto = $invoice->getFechaVencimientoPago();
        $fceFactura = TipoComprobante::isFceFactura($tipo);
        $fceNota = TipoComprobante::isFce($tipo) && !$fceFactura;

        if ($servicios) {
            if ($desde === null || $hasta === null) {
                $r->error('FECHAS_SERVICIO_REQUERIDAS', 'fechaServicioDesde', 'Para concepto Servicios o Productos y Servicios son obligatorias la fecha desde y hasta del servicio.', 10035);
            } elseif (Date::diffDays($desde, $hasta) < 0) {
                $r->error('FECHAS_SERVICIO_INVERTIDAS', 'fechaServicioHasta', 'La fecha de servicio "hasta" no puede ser anterior a la fecha "desde".', 10036);
            }
            if (!$fceNota && $vto === null) {
                $r->error('FECHA_VTO_PAGO_REQUERIDA', 'fechaVencimientoPago', 'Para concepto Servicios o Productos y Servicios es obligatoria la fecha de vencimiento del pago.', 10035);
            }
        } else {
            if ($desde !== null || $hasta !== null) {
                $r->error('FECHAS_SERVICIO_NO_CORRESPONDEN', 'fechaServicioDesde', 'Las fechas de servicio solo se informan cuando el concepto es Servicios o Productos y Servicios.');
            }
            if ($vto !== null && !$fceFactura) {
                $r->error('FECHA_VTO_PAGO_NO_CORRESPONDE', 'fechaVencimientoPago', 'La fecha de vencimiento del pago solo se informa en servicios o en Facturas de Crédito Electrónicas MiPyME.');
            }
        }

        if ($fceNota && $vto !== null) {
            $r->error('FCE_NOTA_VTO_PAGO', 'fechaVencimientoPago', 'Las notas de crédito/débito FCE no deben informar fecha de vencimiento del pago.');
        }
        if ($vto !== null && Date::diffDays($fecha, $vto) < 0) {
            $r->error('FECHA_VTO_PAGO_ANTERIOR', 'fechaVencimientoPago', 'La fecha de vencimiento del pago no puede ser anterior a la fecha del comprobante.', 10037);
        }
    }

    private function validateReceptor(Invoice $invoice, ValidationResult $r): void
    {
        $client = $invoice->getClient();
        if ($client === null) {
            $r->error('RECEPTOR_REQUERIDO', 'client', 'Debe informar el receptor. Para consumidor final sin identificar use Client::consumidorFinal().');

            return;
        }

        $clase = $invoice->getClase();
        $docTipo = $client->getTipoDoc();
        $docNro = $client->getNumDoc();

        if (!TipoDocumento::exists($docTipo)) {
            $r->error('DOC_TIPO_INVALIDO', 'client.tipoDoc', sprintf('El tipo de documento %d no es válido (FEParamGetTiposDoc).', $docTipo), 10015);
        } elseif (!preg_match('/^\d{1,11}$/', $docNro)) {
            $r->error('DOC_NRO_INVALIDO', 'client.numDoc', 'El número de documento debe ser numérico de hasta 11 dígitos (ARCA no admite letras en DocNro).', 10015);
        } elseif (TipoDocumento::isCuitLike($docTipo)) {
            if (!Cuit::isValid($docNro)) {
                $r->error('DOC_NRO_INVALIDO', 'client.numDoc', sprintf('El %s "%s" no es válido (dígito verificador incorrecto).', TipoDocumento::description($docTipo), $docNro), 10015);
            }
        } elseif ($docTipo === TipoDocumento::DNI) {
            if (strlen($docNro) > 8 || (int) $docNro <= 0) {
                $r->error('DOC_NRO_INVALIDO', 'client.numDoc', 'El DNI debe ser un número de hasta 8 dígitos mayor a 0.', 10015);
            }
        } elseif ($docTipo === TipoDocumento::OTRO) {
            if ((int) $docNro !== 0) {
                $r->error('DOC_NRO_INVALIDO', 'client.numDoc', 'Para DocTipo 99 (consumidor final sin identificar) el número de documento debe ser 0.', 10015);
            }
        } elseif ((int) $docNro <= 0) {
            $r->error('DOC_NRO_INVALIDO', 'client.numDoc', 'El número de documento debe ser mayor a 0.', 10015);
        }

        if (in_array($clase, ['A', 'M'], true) && $docTipo !== TipoDocumento::CUIT) {
            $r->error('CLASE_A_REQUIERE_CUIT', 'client.tipoDoc', sprintf('Los comprobantes clase %s requieren que el receptor se identifique con CUIT (DocTipo 80).', $clase), 10013);
        }

        if ($this->options->exigirDatosReceptorA && in_array($clase, ['A', 'M'], true)) {
            if (trim((string) $client->getRazonSocial()) === '') {
                $r->error('RECEPTOR_NOMBRE_REQUERIDO', 'client.razonSocial', 'Debe informar la razón social del receptor.');
            }
            if ($client->getAddress() === null || trim((string) $client->getAddress()) === '') {
                $r->error('RECEPTOR_DOMICILIO_REQUERIDO', 'client.address', 'Debe informar el domicilio del receptor.');
            }
        }

        // RG 5616 — Condición frente al IVA del receptor
        $condicion = $client->getCondicionIva();
        if ($condicion === null) {
            if ($this->options->requiereCondicionIvaReceptor) {
                $r->error('CONDICION_IVA_REQUERIDA', 'client.condicionIva', 'La condición frente al IVA del receptor (CondicionIVAReceptorId) es obligatoria (RG 5616).', 10246);
            }
        } elseif (!CondicionIva::exists($condicion)) {
            $r->error('CONDICION_IVA_INVALIDA', 'client.condicionIva', sprintf('La condición frente al IVA %d no es válida (FEParamGetCondicionIvaReceptor).', $condicion), 10242);
        } else {
            if ($clase !== null && !CondicionIva::isAllowedForClass($condicion, $clase)) {
                $r->error('CONDICION_IVA_CLASE', 'client.condicionIva', sprintf(
                    'La condición "%s" no puede informarse en comprobantes clase %s (admitida en: %s).',
                    CondicionIva::description($condicion),
                    $clase,
                    implode('/', CondicionIva::allowedClasses($condicion))
                ), 10243);
            }
            if (CondicionIva::requiresCuit($condicion) && $docTipo !== TipoDocumento::CUIT) {
                $r->error('CONDICION_IVA_REQUIERE_CUIT', 'client.tipoDoc', sprintf('Un receptor "%s" debe identificarse con CUIT.', CondicionIva::description($condicion)));
            }
            if ($condicion !== CondicionIva::CONSUMIDOR_FINAL && $docTipo === TipoDocumento::OTRO) {
                $r->warning('RECEPTOR_SIN_IDENTIFICAR', 'client.tipoDoc', sprintf('Un receptor "%s" normalmente debe identificarse con su documento.', CondicionIva::description($condicion)));
            }
        }

        // RG 5700/2025 — identificación obligatoria de consumidores finales
        if (in_array($clase, ['B', 'C'], true) && $docTipo === TipoDocumento::OTRO) {
            $totalPesos = $invoice->getImpTotal() * ($invoice->getMoneda() === Moneda::PESOS ? 1 : $invoice->getCotizacion());
            if ($totalPesos >= $this->options->topeConsumidorFinal) {
                $r->error('CONSUMIDOR_FINAL_SIN_IDENTIFICAR', 'client.tipoDoc', sprintf(
                    'Operaciones con consumidores finales de $ %s o más requieren identificar al receptor (DNI, CUIT, CUIL o CDI).',
                    Num::display($this->options->topeConsumidorFinal)
                ), 10015);
            }
        }
    }

    private function validateImportes(Invoice $invoice, ValidationResult $r): void
    {
        $tol = $this->options->tolerancia;
        $fields = [
            'impTotal' => $invoice->getImpTotal(),
            'impNoGravado' => $invoice->getImpNoGravado(),
            'impNeto' => $invoice->getImpNeto(),
            'impExento' => $invoice->getImpExento(),
            'impTributos' => $invoice->getImpTributos(),
            'impIva' => $invoice->getImpIva(),
        ];
        foreach ($fields as $field => $value) {
            if (!is_finite($value) || $value < 0) {
                $r->error('IMPORTE_NEGATIVO', $field, 'El importe no puede ser negativo.', 10047);
            } elseif ($value > self::MAX_IMPORTE) {
                $r->error('IMPORTE_EXCEDIDO', $field, 'El importe supera el máximo admitido (13 enteros y 2 decimales).');
            } elseif (Num::decimals($value) > 2) {
                $r->error('IMPORTE_DECIMALES', $field, 'Los importes admiten como máximo 2 decimales. Redondee o use InvoiceCalculator.');
            }
        }

        $suma = $fields['impNoGravado'] + $fields['impNeto'] + $fields['impExento'] + $fields['impTributos'] + $fields['impIva'];
        if (!Num::equals($fields['impTotal'], $suma, $tol)) {
            $r->error('TOTAL_NO_CUADRA', 'impTotal', sprintf(
                'El importe total (%s) debe ser igual a No gravado + Neto + Exento + Tributos + IVA (%s).',
                Num::format($fields['impTotal']),
                Num::format($suma)
            ), 10048);
        }

        if ($fields['impTotal'] <= 0 && !TipoComprobante::isRecibo($invoice->getTipoComprobante())) {
            $r->warning('TOTAL_CERO', 'impTotal', 'El comprobante tiene importe total 0.');
        }

        if (TipoComprobante::sinIva($invoice->getTipoComprobante())) {
            if ($fields['impNoGravado'] != 0.0) {
                $r->error('CLASE_C_NO_GRAVADO', 'impNoGravado', 'En comprobantes sin IVA (clase C) el importe no gravado (ImpTotConc) debe ser 0.', 10071);
            }
            if ($fields['impExento'] != 0.0) {
                $r->error('CLASE_C_EXENTO', 'impExento', 'En comprobantes sin IVA (clase C) el importe exento (ImpOpEx) debe ser 0.', 10071);
            }
        }
    }

    private function validateIva(Invoice $invoice, ValidationResult $r): void
    {
        $tol = $this->options->tolerancia;
        $ivas = $invoice->getIvas();
        $tipo = $invoice->getTipoComprobante();

        if (TipoComprobante::sinIva($tipo)) {
            if ($ivas !== []) {
                $r->error('IVA_NO_CORRESPONDE', 'ivas', 'Los comprobantes clase C (y bienes usados) no deben informar alícuotas de IVA.', 10071);
            }
            if ($invoice->getImpIva() != 0.0) {
                $r->error('IVA_NO_CORRESPONDE', 'impIva', 'Los comprobantes clase C (y bienes usados) deben tener ImpIVA = 0.', 10071);
            }

            return;
        }

        if ($invoice->getImpNeto() > 0 && $ivas === []) {
            $r->error('IVA_REQUERIDO', 'ivas', 'Si el importe neto gravado es mayor a 0 deben informarse las alícuotas de IVA.', 10070);

            return;
        }
        if ($invoice->getImpNeto() == 0.0 && $ivas !== []) {
            $r->error('IVA_SIN_NETO', 'ivas', 'No deben informarse alícuotas de IVA si el importe neto gravado es 0.');
        }

        $sumBase = 0.0;
        $sumIva = 0.0;
        $seen = [];
        foreach ($ivas as $i => $iva) {
            $field = sprintf('ivas[%d]', $i);
            $sumBase += $iva->getBaseImp();
            $sumIva += $iva->getImporte();

            if (!AlicuotaIva::exists($iva->getId())) {
                $r->error('ALICUOTA_INVALIDA', $field . '.id', sprintf('La alícuota de IVA %d no es válida (FEParamGetTiposIva).', $iva->getId()), 10051);
                continue;
            }
            if (isset($seen[$iva->getId()])) {
                $r->error('ALICUOTA_DUPLICADA', $field . '.id', sprintf('La alícuota %s está informada más de una vez; agrupe las bases.', AlicuotaIva::description($iva->getId())));
            }
            $seen[$iva->getId()] = true;

            if ($iva->getBaseImp() <= 0) {
                $r->error('IVA_BASE_INVALIDA', $field . '.baseImp', 'La base imponible de la alícuota debe ser mayor a 0.');
            }
            if ($iva->getImporte() < 0) {
                $r->error('IVA_IMPORTE_NEGATIVO', $field . '.importe', 'El importe de IVA no puede ser negativo.');
            }
            foreach (['baseImp' => $iva->getBaseImp(), 'importe' => $iva->getImporte()] as $name => $value) {
                if (Num::decimals($value) > 2) {
                    $r->error('IMPORTE_DECIMALES', $field . '.' . $name, 'Los importes admiten como máximo 2 decimales.');
                }
            }

            $expected = Num::round($iva->getBaseImp() * AlicuotaIva::rate($iva->getId()) / 100);
            if (!Num::equals($expected, $iva->getImporte(), $tol)) {
                $r->error('IVA_MAL_CALCULADO', $field . '.importe', sprintf(
                    'El IVA %s sobre %s debe ser %s (informado: %s).',
                    AlicuotaIva::description($iva->getId()),
                    Num::format($iva->getBaseImp()),
                    Num::format($expected),
                    Num::format($iva->getImporte())
                ), 10051);
            }
        }

        if ($ivas !== []) {
            if (!Num::equals($sumBase, $invoice->getImpNeto(), $tol)) {
                $r->error('IVA_BASE_NO_CUADRA', 'impNeto', sprintf(
                    'La suma de las bases imponibles de IVA (%s) debe ser igual al importe neto gravado (%s).',
                    Num::format($sumBase),
                    Num::format($invoice->getImpNeto())
                ), 10053);
            }
            if (!Num::equals($sumIva, $invoice->getImpIva(), $tol)) {
                $r->error('IVA_TOTAL_NO_CUADRA', 'impIva', sprintf(
                    'La suma de los importes de IVA (%s) debe ser igual a ImpIVA (%s).',
                    Num::format($sumIva),
                    Num::format($invoice->getImpIva())
                ), 10052);
            }
        }
    }

    private function validateTributos(Invoice $invoice, ValidationResult $r): void
    {
        $tol = $this->options->tolerancia;
        $sum = 0.0;
        foreach ($invoice->getTributos() as $i => $tributo) {
            $field = sprintf('tributos[%d]', $i);
            $sum += $tributo->getImporte();
            if (!TipoTributo::exists($tributo->getId())) {
                $r->error('TRIBUTO_INVALIDO', $field . '.id', sprintf('El tipo de tributo %d no es válido (FEParamGetTiposTributos).', $tributo->getId()));
            }
            $desc = trim($tributo->getDescripcion());
            if ($desc === '') {
                $r->error('TRIBUTO_DESC_REQUERIDA', $field . '.descripcion', 'Debe informar la descripción del tributo.');
            } elseif (mb_strlen($desc) > 80) {
                $r->error('TRIBUTO_DESC_LARGA', $field . '.descripcion', 'La descripción del tributo admite hasta 80 caracteres.');
            }
            if ($tributo->getBaseImp() < 0 || $tributo->getAlicuota() < 0 || $tributo->getImporte() < 0) {
                $r->error('TRIBUTO_NEGATIVO', $field, 'La base, alícuota e importe del tributo no pueden ser negativos.');
            }
            if (Num::decimals($tributo->getImporte()) > 2 || Num::decimals($tributo->getBaseImp()) > 2) {
                $r->error('IMPORTE_DECIMALES', $field, 'Los importes del tributo admiten como máximo 2 decimales.');
            }
            if ($tributo->getBaseImp() > 0 && $tributo->getAlicuota() > 0) {
                $expected = Num::round($tributo->getBaseImp() * $tributo->getAlicuota() / 100);
                if (!Num::equals($expected, $tributo->getImporte(), $tol)) {
                    $r->warning('TRIBUTO_MAL_CALCULADO', $field . '.importe', sprintf(
                        'El importe del tributo (%s) no coincide con base × alícuota (%s).',
                        Num::format($tributo->getImporte()),
                        Num::format($expected)
                    ));
                }
            }
        }

        if ($invoice->getTributos() === [] && $invoice->getImpTributos() > 0) {
            $r->error('TRIBUTOS_REQUERIDOS', 'tributos', 'Si ImpTrib es mayor a 0 debe informar el detalle de tributos.', 10049);
        } elseif (!Num::equals($sum, $invoice->getImpTributos(), $tol)) {
            $r->error('TRIBUTOS_NO_CUADRAN', 'impTributos', sprintf(
                'La suma de los tributos (%s) debe ser igual a ImpTrib (%s).',
                Num::format($sum),
                Num::format($invoice->getImpTributos())
            ), 10049);
        }
    }

    private function validateMoneda(Invoice $invoice, ValidationResult $r): void
    {
        $moneda = $invoice->getMoneda();
        $cotizacion = $invoice->getCotizacion();

        if (!preg_match('/^[A-Z0-9]{3}$/', $moneda)) {
            $r->error('MONEDA_INVALIDA', 'moneda', sprintf('El código de moneda "%s" no es válido (3 caracteres, ver FEParamGetTiposMonedas).', $moneda), 10119);

            return;
        }
        if (!Moneda::exists($moneda)) {
            $message = sprintf('La moneda "%s" no figura en el catálogo local; verifique con FEParamGetTiposMonedas.', $moneda);
            $this->options->catalogosEstrictos
                ? $r->error('MONEDA_DESCONOCIDA', 'moneda', $message, 10119)
                : $r->warning('MONEDA_DESCONOCIDA', 'moneda', $message, 10119);
        }

        if ($moneda === Moneda::PESOS) {
            if ($cotizacion != 1.0) {
                $r->error('COTIZACION_INVALIDA', 'cotizacion', 'Para moneda PES la cotización debe ser 1.', 10120);
            }
            if ($invoice->getPagoMismaMoneda() !== null) {
                $r->error('CAN_MIS_MON_EXT_NO_CORRESPONDE', 'pagoMismaMoneda', 'El indicador de cancelación en misma moneda extranjera (CanMisMonExt) solo se informa en comprobantes en moneda extranjera.');
            }
        } else {
            if (!is_finite($cotizacion) || $cotizacion <= 0) {
                $r->error('COTIZACION_INVALIDA', 'cotizacion', 'Para moneda extranjera la cotización debe ser mayor a 0.', 10120);
            } elseif (Num::decimals($cotizacion) > 6) {
                $r->error('COTIZACION_DECIMALES', 'cotizacion', 'La cotización admite como máximo 6 decimales.');
            }
            if ($invoice->getPagoMismaMoneda() === null) {
                $r->warning('CAN_MIS_MON_EXT_NO_INFORMADO', 'pagoMismaMoneda', 'No se indicó si el comprobante se cancela en la misma moneda extranjera (CanMisMonExt); se enviará "N".');
            }
        }
    }

    private function validateAsociados(Invoice $invoice, ValidationResult $r): void
    {
        $tipo = $invoice->getTipoComprobante();
        $asociados = $invoice->getCbtesAsociados();
        $periodo = $invoice->getPeriodoAsociado();
        $esNota = TipoComprobante::isNota($tipo);
        $fce = TipoComprobante::isFce($tipo);

        if ($esNota && $asociados === [] && $periodo === null) {
            $r->error('CBTE_ASOC_REQUERIDO', 'cbtesAsociados', 'Las notas de crédito y débito deben informar el comprobante asociado (CbtesAsoc) o el período asociado (PeriodoAsoc).', 10040);
        }
        if ($asociados !== [] && $periodo !== null) {
            $r->error('CBTE_ASOC_Y_PERIODO', 'periodoAsociado', 'No pueden informarse a la vez comprobantes asociados y período asociado.');
        }
        if ($fce && $esNota && $periodo !== null) {
            $r->error('FCE_PERIODO_NO_PERMITIDO', 'periodoAsociado', 'Las notas FCE deben asociar el comprobante (CbtesAsoc), no un período.');
        }
        if ($periodo !== null && !$esNota) {
            $r->warning('PERIODO_EN_FACTURA', 'periodoAsociado', 'El período asociado se utiliza en notas de crédito/débito.');
        }

        $permitidos = TipoComprobante::associableTo($tipo);
        foreach ($asociados as $i => $asoc) {
            $field = sprintf('cbtesAsociados[%d]', $i);
            if (!TipoComprobante::exists($asoc->getTipo())) {
                $r->error('CBTE_ASOC_TIPO_INVALIDO', $field . '.tipo', sprintf('El tipo de comprobante asociado %d no es válido.', $asoc->getTipo()));
            } elseif ($esNota && !in_array($asoc->getTipo(), $permitidos, true)) {
                $r->error('CBTE_ASOC_CLASE', $field . '.tipo', sprintf(
                    'Una %s solo puede asociar comprobantes de la misma clase%s (informado: %s).',
                    TipoComprobante::description($tipo),
                    $fce ? ' y de tipo FCE' : '',
                    TipoComprobante::description($asoc->getTipo())
                ), 10042);
            }
            if ($asoc->getPuntoVenta() < 1 || $asoc->getPuntoVenta() > self::MAX_PTO_VTA) {
                $r->error('CBTE_ASOC_PTO_VTA', $field . '.puntoVenta', 'El punto de venta del comprobante asociado no es válido.');
            }
            if ($asoc->getNumero() < 1 || $asoc->getNumero() > self::MAX_NUMERO) {
                $r->error('CBTE_ASOC_NUMERO', $field . '.numero', 'El número del comprobante asociado no es válido.');
            }
            if ($asoc->getCuit() !== null && !Cuit::isValid($asoc->getCuit())) {
                $r->error('CBTE_ASOC_CUIT', $field . '.cuit', sprintf('El CUIT del comprobante asociado "%s" no es válido.', $asoc->getCuit()));
            }
            if ($asoc->getFecha() !== null && Date::diffDays($invoice->getFechaEmision(), $asoc->getFecha()) > 0) {
                $r->error('CBTE_ASOC_FECHA', $field . '.fecha', 'La fecha del comprobante asociado no puede ser posterior a la del comprobante.');
            }
            if ($fce) {
                if ($asoc->getCuit() === null) {
                    $r->error('FCE_CBTE_ASOC_CUIT', $field . '.cuit', 'En FCE el comprobante asociado debe informar el CUIT del emisor.');
                }
                if ($asoc->getFecha() === null) {
                    $r->error('FCE_CBTE_ASOC_FECHA', $field . '.fecha', 'En FCE el comprobante asociado debe informar su fecha.');
                }
            }
        }

        if ($periodo !== null) {
            if (Date::diffDays($periodo->getDesde(), $periodo->getHasta()) < 0) {
                $r->error('PERIODO_INVERTIDO', 'periodoAsociado.hasta', 'La fecha "hasta" del período asociado no puede ser anterior a la fecha "desde".');
            }
            if (Date::diffDays($invoice->getFechaEmision(), $periodo->getHasta()) > 0) {
                $r->error('PERIODO_POSTERIOR', 'periodoAsociado.hasta', 'El período asociado no puede terminar después de la fecha del comprobante.');
            }
        }
    }

    private function validateOpcionales(Invoice $invoice, ValidationResult $r): void
    {
        $seen = [];
        foreach ($invoice->getOpcionales() as $i => $opcional) {
            $field = sprintf('opcionales[%d]', $i);
            $id = trim($opcional->getId());
            if ($id === '') {
                $r->error('OPCIONAL_ID_REQUERIDO', $field . '.id', 'Debe informar el Id del dato opcional.');
                continue;
            }
            if (isset($seen[$id])) {
                $r->error('OPCIONAL_DUPLICADO', $field . '.id', sprintf('El opcional %s está informado más de una vez.', $id));
            }
            $seen[$id] = true;
            if (!TipoOpcional::exists($id)) {
                $message = sprintf('El opcional %s no figura en el catálogo local; verifique con FEParamGetTiposOpcional.', $id);
                $this->options->catalogosEstrictos
                    ? $r->error('OPCIONAL_DESCONOCIDO', $field . '.id', $message)
                    : $r->warning('OPCIONAL_DESCONOCIDO', $field . '.id', $message);
            }
            if (trim($opcional->getValor()) === '') {
                $r->error('OPCIONAL_VALOR_REQUERIDO', $field . '.valor', sprintf('Debe informar el valor del opcional %s.', $id));
            }
        }

        $fce = TipoComprobante::isFce($invoice->getTipoComprobante());
        foreach ([TipoOpcional::FCE_CBU_EMISOR, TipoOpcional::FCE_ALIAS_EMISOR, TipoOpcional::FCE_ANULACION, TipoOpcional::FCE_TRANSFERENCIA] as $id) {
            if (!$fce && isset($seen[$id])) {
                $r->error('OPCIONAL_SOLO_FCE', 'opcionales', sprintf('El opcional %s (%s) solo corresponde a comprobantes FCE MiPyME.', $id, TipoOpcional::description($id)));
            }
        }

        // RG 3668: los tres datos del firmante se informan juntos
        $rg3668 = array_intersect([TipoOpcional::RG3668_FIRMANTE_DOC_TIPO, TipoOpcional::RG3668_FIRMANTE_DOC_NRO, TipoOpcional::RG3668_CARACTER_FIRMANTE], array_keys($seen));
        if ($rg3668 !== [] && count($rg3668) !== 3) {
            $r->error('RG3668_INCOMPLETO', 'opcionales', 'Para RG 3668 deben informarse juntos los opcionales 61 (tipo doc. firmante), 62 (nro. doc. firmante) y 7 (carácter del firmante).');
        }
    }

    private function validateFce(Invoice $invoice, ValidationResult $r): void
    {
        $tipo = $invoice->getTipoComprobante();
        if (!TipoComprobante::isFce($tipo)) {
            return;
        }

        if ($invoice->getClient() !== null && $invoice->getClient()->getTipoDoc() !== TipoDocumento::CUIT) {
            $r->error('FCE_RECEPTOR_CUIT', 'client.tipoDoc', 'En Factura de Crédito Electrónica MiPyME el receptor debe identificarse con CUIT.');
        }

        if (TipoComprobante::isFceFactura($tipo)) {
            if ($invoice->getFechaVencimientoPago() === null) {
                $r->error('FCE_VTO_PAGO_REQUERIDO', 'fechaVencimientoPago', 'La Factura de Crédito Electrónica MiPyME debe informar la fecha de vencimiento del pago.');
            }
            $cbu = $invoice->getOpcional(TipoOpcional::FCE_CBU_EMISOR);
            if ($cbu === null) {
                $r->error('FCE_CBU_REQUERIDO', 'opcionales', 'La Factura de Crédito Electrónica MiPyME debe informar el CBU del emisor (opcional 2101).');
            } elseif (!Cbu::isValid($cbu->getValor())) {
                $r->error('FCE_CBU_INVALIDO', 'opcionales', sprintf('El CBU "%s" no es válido (22 dígitos con dígitos verificadores).', $cbu->getValor()));
            }
            $transferencia = $invoice->getOpcional(TipoOpcional::FCE_TRANSFERENCIA);
            if ($transferencia === null) {
                $r->error('FCE_TRANSFERENCIA_REQUERIDA', 'opcionales', 'La Factura de Crédito Electrónica MiPyME debe informar el opcional 27 (Transferencia: SCA o ADC).');
            } elseif (!in_array($transferencia->getValor(), [TipoOpcional::TRANSFERENCIA_SCA, TipoOpcional::TRANSFERENCIA_ADC], true)) {
                $r->error('FCE_TRANSFERENCIA_INVALIDA', 'opcionales', 'El opcional 27 (Transferencia) debe ser "SCA" o "ADC".');
            }
            if ($invoice->getOpcional(TipoOpcional::FCE_ANULACION) !== null) {
                $r->error('FCE_ANULACION_EN_FACTURA', 'opcionales', 'El opcional 22 (Anulación) solo se informa en notas de crédito/débito FCE.');
            }
        } else {
            $anulacion = $invoice->getOpcional(TipoOpcional::FCE_ANULACION);
            if ($anulacion === null) {
                $r->error('FCE_ANULACION_REQUERIDA', 'opcionales', 'Las notas de crédito/débito FCE deben informar el opcional 22 (Anulación: S o N).');
            } elseif (!in_array($anulacion->getValor(), ['S', 'N'], true)) {
                $r->error('FCE_ANULACION_INVALIDA', 'opcionales', 'El opcional 22 (Anulación) debe ser "S" o "N".');
            }
        }
    }

    private function validateCompradores(Invoice $invoice, ValidationResult $r): void
    {
        $compradores = $invoice->getCompradores();
        if ($compradores === []) {
            return;
        }
        $total = 0.0;
        foreach ($compradores as $i => $comprador) {
            $field = sprintf('compradores[%d]', $i);
            $total += $comprador->getPorcentaje();
            if (!TipoDocumento::exists($comprador->getTipoDoc())) {
                $r->error('COMPRADOR_DOC_TIPO', $field . '.tipoDoc', 'El tipo de documento del comprador no es válido.');
            }
            if (!preg_match('/^\d{1,11}$/', $comprador->getNumDoc())
                || (TipoDocumento::isCuitLike($comprador->getTipoDoc()) && !Cuit::isValid($comprador->getNumDoc()))) {
                $r->error('COMPRADOR_DOC_NRO', $field . '.numDoc', 'El número de documento del comprador no es válido.');
            }
            if ($comprador->getPorcentaje() <= 0 || $comprador->getPorcentaje() > 100) {
                $r->error('COMPRADOR_PORCENTAJE', $field . '.porcentaje', 'El porcentaje del comprador debe ser mayor a 0 y hasta 100.');
            }
        }
        if (!Num::equals($total, 100.0, 0.001)) {
            $r->error('COMPRADORES_PORCENTAJE_TOTAL', 'compradores', sprintf('La suma de los porcentajes de los compradores debe ser 100 (informado: %s).', Num::formatCompact($total)));
        }
    }

    private function validateActividades(Invoice $invoice, ValidationResult $r): void
    {
        foreach ($invoice->getActividades() as $i => $actividad) {
            if ($actividad <= 0) {
                $r->error('ACTIVIDAD_INVALIDA', sprintf('actividades[%d]', $i), 'El código de actividad debe ser un número positivo (FEParamGetActividades).');
            }
        }
    }

    private function validateDetails(Invoice $invoice, ValidationResult $r): void
    {
        $details = $invoice->getDetails();
        if ($details === []) {
            return;
        }
        $before = count($r->getErrors());
        foreach ($details as $i => $detail) {
            $field = sprintf('details[%d]', $i);
            if (trim($detail->getDescripcion()) === '') {
                $r->error('ITEM_DESCRIPCION', $field . '.descripcion', 'El ítem debe tener descripción.');
            }
            if ($detail->getCantidad() <= 0) {
                $r->error('ITEM_CANTIDAD', $field . '.cantidad', 'La cantidad del ítem debe ser mayor a 0.');
            }
            if ($detail->getPrecioUnitario() < 0) {
                $r->error('ITEM_PRECIO', $field . '.precioUnitario', 'El precio unitario no puede ser negativo.');
            }
            if (!TipoComprobante::sinIva($invoice->getTipoComprobante())
                && $detail->getTratamientoIva() === 'gravado'
                && ($detail->getAlicuotaIva() === null || !AlicuotaIva::exists($detail->getAlicuotaIva()))) {
                $r->error('ITEM_ALICUOTA', $field . '.alicuotaIva', 'El ítem gravado debe indicar una alícuota de IVA válida.');
            }
        }

        if (count($r->getErrors()) > $before) {
            return;
        }

        // Los importes informados deben coincidir con los ítems.
        $copy = clone $invoice;
        try {
            (new InvoiceCalculator())->calculate($copy);
        } catch (\InvalidArgumentException) {
            return;
        }
        if (!Num::equals($copy->getImpTotal(), $invoice->getImpTotal(), $this->options->tolerancia)
            || !Num::equals($copy->getImpNeto(), $invoice->getImpNeto(), $this->options->tolerancia)
            || !Num::equals($copy->getImpIva(), $invoice->getImpIva(), $this->options->tolerancia)) {
            $r->error('ITEMS_NO_CUADRAN', 'details', sprintf(
                'Los importes del comprobante no coinciden con sus ítems (total según ítems: %s, informado: %s). Use InvoiceCalculator para calcularlos.',
                Num::format($copy->getImpTotal()),
                Num::format($invoice->getImpTotal())
            ));
        }
    }

    private function validateCaea(Invoice $invoice, ValidationResult $r): void
    {
        if (!preg_match('/^\d{14}$/', (string) $invoice->getCaea())) {
            $r->error('CAEA_INVALIDO', 'caea', 'Debe informar el CAEA (14 dígitos) con el que se emitió el comprobante.');
        }
        if ($invoice->getFechaHoraGeneracion() === null) {
            $r->error('CAEA_FECHA_GENERACION', 'fechaHoraGeneracion', 'Debe informar la fecha y hora de generación del comprobante emitido con CAEA.');
        }
        if ($invoice->getNumero() === null) {
            $r->error('CAEA_NUMERO', 'numero', 'Los comprobantes emitidos con CAEA deben tener número asignado.');
        }
    }
}
