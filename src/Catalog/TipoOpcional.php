<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Catalog;

/**
 * Datos opcionales (FEParamGetTiposOpcional).
 */
final class TipoOpcional
{
    public const PROYECTO_PROMOCION_INDUSTRIAL = '2';
    public const EXCEPCION_COMPUTO_IVA_CF = '5';
    public const RG3668_CARACTER_FIRMANTE = '7';
    public const RG3368_EDUCACION_ACTIVIDAD = '10';
    public const RG2820_INMUEBLES = '11';
    public const RG3687_LOCACION_TURISTICA = '12';
    public const RG2863_REPRESENTANTES_MODELOS = '13';
    public const RG2863_AGENCIAS_PUBLICIDAD = '14';
    public const RG2863_MODELAJE = '15';
    public const RG4004_LOCACION_CASA_HABITACION = '17';
    public const FCE_ANULACION = '22';
    public const FCE_REFERENCIA_COMERCIAL = '23';
    public const FCE_TRANSFERENCIA = '27';
    public const RG3668_FIRMANTE_DOC_TIPO = '61';
    public const RG3668_FIRMANTE_DOC_NRO = '62';
    public const BIENES_USADOS_NOMBRE_VENDEDOR = '91';
    public const BIENES_USADOS_NACIONALIDAD_VENDEDOR = '92';
    public const BIENES_USADOS_DOMICILIO_VENDEDOR = '93';
    public const RG3368_EDUCACION_TIPO_DOC = '1011';
    public const RG3368_EDUCACION_NRO_DOC = '1012';
    public const RG4004_CUIT_PROPIETARIO = '1801';
    public const RG4004_NOMBRE_PROPIETARIO = '1802';
    public const FCE_CBU_EMISOR = '2101';
    public const FCE_ALIAS_EMISOR = '2102';

    /** Valores admitidos para FCE_TRANSFERENCIA (opcional 27). */
    public const TRANSFERENCIA_SCA = 'SCA'; // Sistema de Circulación Abierta
    public const TRANSFERENCIA_ADC = 'ADC'; // Agente de Depósito Colectivo

    /** @var array<string, string> */
    private const DATA = [
        '2' => 'RG Empresas Promovidas - Identificador de proyecto vinculado a Régimen de Promoción Industrial',
        '5' => 'Excepción cómputo IVA Crédito Fiscal',
        '7' => 'RG 3668 IVA Art.12 - Carácter del Firmante',
        '10' => 'RG 3368 Establecimientos de educación pública de gestión privada - Actividad Comprendida',
        '11' => 'RG 2820 Operaciones económicas vinculadas con bienes inmuebles - Actividad Comprendida',
        '12' => 'RG 3687 Locación temporaria de inmuebles con fines turísticos - Actividad Comprendida',
        '13' => 'RG 2863 Representantes de Modelos',
        '14' => 'RG 2863 Agencias de publicidad',
        '15' => 'RG 2863 Personas físicas que desarrollen actividad de modelaje',
        '17' => "RG 4004-E Locación de inmuebles destino 'casa-habitación' (1 intermediario / 2 directa)",
        '22' => 'FCE MiPyMEs - Anulación',
        '23' => 'FCE MiPyMEs - Referencia Comercial',
        '27' => 'FCE MiPyMEs - Transferencia (SCA/ADC)',
        '61' => 'RG 3668 IVA Art.12 - Firmante Doc Tipo',
        '62' => 'RG 3668 IVA Art.12 - Firmante Doc Nro',
        '91' => 'RG 3411 Bienes Usados - Nombre y Apellido o Denominación del vendedor',
        '92' => 'RG 3411 Bienes Usados - Nacionalidad del vendedor',
        '93' => 'RG 3411 Bienes Usados - Domicilio del vendedor',
        '1011' => 'RG 3368 Educación - Tipo de Documento',
        '1012' => 'RG 3368 Educación - Número de Documento',
        '1801' => "RG 4004-E Locación 'casa-habitación' - CUIT",
        '1802' => "RG 4004-E Locación 'casa-habitación' - Apellido y nombres / Razón social",
        '2101' => 'FCE MiPyMEs - CBU del Emisor',
        '2102' => 'FCE MiPyMEs - Alias del Emisor',
    ];

    public static function exists(string|int $id): bool
    {
        return isset(self::DATA[(string) $id]);
    }

    public static function description(string|int $id): string
    {
        return self::DATA[(string) $id] ?? (string) $id;
    }

    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        return self::DATA;
    }
}
