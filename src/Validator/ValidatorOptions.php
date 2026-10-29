<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Validator;

/**
 * Parámetros normativos configurables de la validación.
 *
 * Los valores por defecto reflejan la normativa vigente a la fecha del SDK. Si ARCA
 * modifica un monto o plazo, basta con ajustar la opción sin actualizar el SDK.
 */
final class ValidatorOptions
{
    public function __construct(
        /**
         * Monto a partir del cual una factura B a consumidor final debe identificar al
         * receptor (RG 5700/2025: $10.000.000).
         */
        public float $topeConsumidorFinal = 10_000_000.00,
        /** Diferencia máxima admitida por redondeo en importes. */
        public float $tolerancia = 0.01,
        /** CondicionIVAReceptorId obligatorio (RG 5616). */
        public bool $requiereCondicionIvaReceptor = true,
        /** Días de diferencia admitidos entre CbteFch y la fecha de envío (productos). */
        public int $diasFechaProductos = 5,
        /** Días de diferencia admitidos entre CbteFch y la fecha de envío (servicios). */
        public int $diasFechaServicios = 10,
        /** Fecha de referencia ("hoy"). Null = fecha actual en Argentina. */
        public ?\DateTimeInterface $fechaReferencia = null,
        /** Cantidad máxima de comprobantes por request (FECompTotXRequest). */
        public int $maxRegistrosPorRequest = 250,
        /** Validar que la clase de comprobante sea emitible según la condición IVA del emisor. */
        public bool $validarCondicionEmisor = true,
        /** Tratar como error (y no advertencia) los códigos de moneda/opcional desconocidos. */
        public bool $catalogosEstrictos = false,
        /** Exigir los datos del receptor (nombre y domicilio) en clases A y M. */
        public bool $exigirDatosReceptorA = false,
        /**
         * Tratar como error (y no advertencia) que los importes no coincidan con los ítems.
         * ARCA no recibe los ítems, pero la representación impresa los muestra.
         */
        public bool $itemsEstrictos = false,
        /** Tratar como error (y no advertencia) un comprobante con importe total 0. */
        public bool $totalCeroEsError = false,
    ) {
    }
}
