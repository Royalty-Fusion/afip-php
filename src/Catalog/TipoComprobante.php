<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Catalog;

/**
 * Tipos de comprobante (FEParamGetTiposCbte / FEXGetPARAM_Cbte_Tipo).
 */
final class TipoComprobante
{
    public const FACTURA_A = 1;
    public const NOTA_DEBITO_A = 2;
    public const NOTA_CREDITO_A = 3;
    public const RECIBO_A = 4;
    public const NOTA_VENTA_CONTADO_A = 5;
    public const FACTURA_B = 6;
    public const NOTA_DEBITO_B = 7;
    public const NOTA_CREDITO_B = 8;
    public const RECIBO_B = 9;
    public const NOTA_VENTA_CONTADO_B = 10;
    public const FACTURA_C = 11;
    public const NOTA_DEBITO_C = 12;
    public const NOTA_CREDITO_C = 13;
    public const RECIBO_C = 15;
    public const FACTURA_EXPORTACION = 19;
    public const NOTA_DEBITO_EXPORTACION = 20;
    public const NOTA_CREDITO_EXPORTACION = 21;
    public const CBTE_A_RG1415 = 34;
    public const CBTE_B_RG1415 = 35;
    public const OTROS_A_RG1415 = 39;
    public const OTROS_B_RG1415 = 40;
    public const BIENES_USADOS = 49;
    public const FACTURA_M = 51;
    public const NOTA_DEBITO_M = 52;
    public const NOTA_CREDITO_M = 53;
    public const RECIBO_M = 54;
    public const CTA_VTA_LIQ_PROD_A = 60;
    public const CTA_VTA_LIQ_PROD_B = 61;
    public const LIQUIDACION_A = 63;
    public const LIQUIDACION_B = 64;
    public const FCE_FACTURA_A = 201;
    public const FCE_NOTA_DEBITO_A = 202;
    public const FCE_NOTA_CREDITO_A = 203;
    public const FCE_FACTURA_B = 206;
    public const FCE_NOTA_DEBITO_B = 207;
    public const FCE_NOTA_CREDITO_B = 208;
    public const FCE_FACTURA_C = 211;
    public const FCE_NOTA_DEBITO_C = 212;
    public const FCE_NOTA_CREDITO_C = 213;
    public const FACTURA_T = 195;
    public const NOTA_DEBITO_T = 196;
    public const NOTA_CREDITO_T = 197;

    public const CLASE_A = 'A';
    public const CLASE_B = 'B';
    public const CLASE_C = 'C';
    public const CLASE_M = 'M';
    public const CLASE_E = 'E';
    public const CLASE_T = 'T';

    // Naturaleza del comprobante
    public const FACTURA = 'FACTURA';
    public const NOTA_DEBITO = 'NOTA_DEBITO';
    public const NOTA_CREDITO = 'NOTA_CREDITO';
    public const RECIBO = 'RECIBO';
    public const OTRO = 'OTRO';

    /**
     * id => [descripción, clase, naturaleza, servicio web]
     *
     * @var array<int, array{0: string, 1: ?string, 2: string, 3: string}>
     */
    private const DATA = [
        1 => ['Factura A', 'A', self::FACTURA, 'wsfe'],
        2 => ['Nota de Débito A', 'A', self::NOTA_DEBITO, 'wsfe'],
        3 => ['Nota de Crédito A', 'A', self::NOTA_CREDITO, 'wsfe'],
        4 => ['Recibo A', 'A', self::RECIBO, 'wsfe'],
        5 => ['Nota de Venta al Contado A', 'A', self::OTRO, 'wsfe'],
        6 => ['Factura B', 'B', self::FACTURA, 'wsfe'],
        7 => ['Nota de Débito B', 'B', self::NOTA_DEBITO, 'wsfe'],
        8 => ['Nota de Crédito B', 'B', self::NOTA_CREDITO, 'wsfe'],
        9 => ['Recibo B', 'B', self::RECIBO, 'wsfe'],
        10 => ['Nota de Venta al Contado B', 'B', self::OTRO, 'wsfe'],
        11 => ['Factura C', 'C', self::FACTURA, 'wsfe'],
        12 => ['Nota de Débito C', 'C', self::NOTA_DEBITO, 'wsfe'],
        13 => ['Nota de Crédito C', 'C', self::NOTA_CREDITO, 'wsfe'],
        15 => ['Recibo C', 'C', self::RECIBO, 'wsfe'],
        19 => ['Factura de Exportación E', 'E', self::FACTURA, 'wsfex'],
        20 => ['Nota de Débito por Operaciones con el Exterior E', 'E', self::NOTA_DEBITO, 'wsfex'],
        21 => ['Nota de Crédito por Operaciones con el Exterior E', 'E', self::NOTA_CREDITO, 'wsfex'],
        34 => ['Cbtes. A del Anexo I, Apartado A, inc. f), RG 1415', 'A', self::OTRO, 'wsfe'],
        35 => ['Cbtes. B del Anexo I, Apartado A, inc. f), RG 1415', 'B', self::OTRO, 'wsfe'],
        39 => ['Otros comprobantes A que cumplan con RG 1415', 'A', self::OTRO, 'wsfe'],
        40 => ['Otros comprobantes B que cumplan con RG 1415', 'B', self::OTRO, 'wsfe'],
        49 => ['Comprobante de Compra de Bienes Usados a Consumidor Final', null, self::OTRO, 'wsfe'],
        51 => ['Factura M', 'M', self::FACTURA, 'wsfe'],
        52 => ['Nota de Débito M', 'M', self::NOTA_DEBITO, 'wsfe'],
        53 => ['Nota de Crédito M', 'M', self::NOTA_CREDITO, 'wsfe'],
        54 => ['Recibo M', 'M', self::RECIBO, 'wsfe'],
        60 => ['Cuenta de Venta y Líquido Producto A', 'A', self::OTRO, 'wsfe'],
        61 => ['Cuenta de Venta y Líquido Producto B', 'B', self::OTRO, 'wsfe'],
        63 => ['Liquidación A', 'A', self::OTRO, 'wsfe'],
        64 => ['Liquidación B', 'B', self::OTRO, 'wsfe'],
        201 => ['Factura de Crédito Electrónica MiPyMEs (FCE) A', 'A', self::FACTURA, 'wsfe'],
        202 => ['Nota de Débito Electrónica MiPyMEs (FCE) A', 'A', self::NOTA_DEBITO, 'wsfe'],
        203 => ['Nota de Crédito Electrónica MiPyMEs (FCE) A', 'A', self::NOTA_CREDITO, 'wsfe'],
        206 => ['Factura de Crédito Electrónica MiPyMEs (FCE) B', 'B', self::FACTURA, 'wsfe'],
        207 => ['Nota de Débito Electrónica MiPyMEs (FCE) B', 'B', self::NOTA_DEBITO, 'wsfe'],
        208 => ['Nota de Crédito Electrónica MiPyMEs (FCE) B', 'B', self::NOTA_CREDITO, 'wsfe'],
        211 => ['Factura de Crédito Electrónica MiPyMEs (FCE) C', 'C', self::FACTURA, 'wsfe'],
        212 => ['Nota de Débito Electrónica MiPyMEs (FCE) C', 'C', self::NOTA_DEBITO, 'wsfe'],
        213 => ['Nota de Crédito Electrónica MiPyMEs (FCE) C', 'C', self::NOTA_CREDITO, 'wsfe'],
        195 => ['Factura T (turismo)', 'T', self::FACTURA, 'wsct'],
        196 => ['Nota de Débito T (turismo)', 'T', self::NOTA_DEBITO, 'wsct'],
        197 => ['Nota de Crédito T (turismo)', 'T', self::NOTA_CREDITO, 'wsct'],
    ];

    public static function exists(int $id): bool
    {
        return isset(self::DATA[$id]);
    }

    public static function isWsfe(int $id): bool
    {
        return (self::DATA[$id][3] ?? null) === 'wsfe';
    }

    public static function isWsfex(int $id): bool
    {
        return (self::DATA[$id][3] ?? null) === 'wsfex';
    }

    public static function isWsct(int $id): bool
    {
        return (self::DATA[$id][3] ?? null) === 'wsct';
    }

    public static function description(int $id): string
    {
        return self::DATA[$id][0] ?? sprintf('Comprobante %d', $id);
    }

    /**
     * Clase (letra) del comprobante: A, B, C, M, E o null si no tiene letra (ej. 49).
     */
    public static function clase(int $id): ?string
    {
        return self::DATA[$id][1] ?? null;
    }

    public static function nature(int $id): ?string
    {
        return self::DATA[$id][2] ?? null;
    }

    public static function isFactura(int $id): bool
    {
        return self::nature($id) === self::FACTURA;
    }

    public static function isNotaCredito(int $id): bool
    {
        return self::nature($id) === self::NOTA_CREDITO;
    }

    public static function isNotaDebito(int $id): bool
    {
        return self::nature($id) === self::NOTA_DEBITO;
    }

    public static function isNota(int $id): bool
    {
        return self::isNotaCredito($id) || self::isNotaDebito($id);
    }

    public static function isRecibo(int $id): bool
    {
        return self::nature($id) === self::RECIBO;
    }

    /**
     * Factura de Crédito Electrónica MiPyME (RG 4367) — facturas y notas.
     */
    public static function isFce(int $id): bool
    {
        return $id >= 201 && $id <= 213;
    }

    public static function isFceFactura(int $id): bool
    {
        return in_array($id, [201, 206, 211], true);
    }

    /**
     * Comprobantes que discriminan IVA (objeto Iva obligatorio si ImpNeto > 0).
     */
    public static function discriminaIva(int $id): bool
    {
        return in_array(self::clase($id), [self::CLASE_A, self::CLASE_B, self::CLASE_M], true);
    }

    /**
     * Comprobantes que no llevan IVA (clase C y bienes usados).
     */
    public static function sinIva(int $id): bool
    {
        return self::clase($id) === self::CLASE_C || $id === self::BIENES_USADOS;
    }

    /**
     * Código a imprimir debajo de la letra ("COD. 01").
     */
    public static function printCode(int $id): string
    {
        return str_pad((string) $id, $id > 99 ? 3 : 2, '0', STR_PAD_LEFT);
    }

    /**
     * Tipos de comprobante que pueden asociarse a una nota de la clase indicada.
     *
     * @return int[]
     */
    public static function associableTo(int $noteType): array
    {
        $clase = self::clase($noteType);
        $fce = self::isFce($noteType);

        return array_values(array_filter(
            array_keys(self::DATA),
            static fn (int $id): bool => self::clase($id) === $clase
                && (self::DATA[$id][3] ?? null) === (self::DATA[$noteType][3] ?? null)
                && ($fce ? self::isFce($id) : !self::isFce($id))
        ));
    }

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return array_map(static fn (array $row): string => $row[0], self::DATA);
    }
}
