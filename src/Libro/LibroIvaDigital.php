<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Libro;

use RoyaltyFusion\Afip\Catalog\AlicuotaIva;
use RoyaltyFusion\Afip\Catalog\Moneda;
use RoyaltyFusion\Afip\Catalog\TipoComprobante;
use RoyaltyFusion\Afip\Catalog\TipoDocumento;
use RoyaltyFusion\Afip\Exception\ValidationException;
use RoyaltyFusion\Afip\Model\Export\ExportInvoice;
use RoyaltyFusion\Afip\Model\Sale\Invoice;
use RoyaltyFusion\Afip\Model\Sale\Iva;
use RoyaltyFusion\Afip\Support\Cuit;
use RoyaltyFusion\Afip\Support\Num;
use RoyaltyFusion\Afip\Validator\ValidationResult;

/**
 * Archivos de importación del Libro IVA Digital (RG 4597) para un período.
 *
 * Genera los TXT de ancho fijo que acepta el servicio "Libro de IVA Digital" de ARCA:
 *
 * | Archivo                       | Registro |
 * |-------------------------------|----------|
 * | Ventas: comprobantes          | 266      |
 * | Ventas: alícuotas             | 62       |
 * | Compras: comprobantes         | 325      |
 * | Compras: alícuotas            | 84       |
 * | Compras: despachos (tipo 66)  | 325      |
 * | Compras: alícuotas despachos  | 50       |
 *
 * Antes de generar valida todo (totales, alícuotas, documentos, duplicados, período) y,
 * si hay errores, lanza ValidationException sin generar nada.
 */
final class LibroIvaDigital
{
    public const VENTAS = 'ventas';
    public const COMPRAS = 'compras';

    public const DESPACHO_IMPORTACION = 66;

    /** Comprobantes del libro que no se emiten por web service. */
    public const OTROS_TIPOS = [
        33 => 'Liquidación primaria de granos',
        66 => 'Despacho de importación',
        80 => 'Informe diario de cierre (zeta)',
        81 => 'Tique factura A',
        82 => 'Tique factura B',
        83 => 'Tique',
        99 => 'Otros comprobantes que no cumplen con la RG 1415',
    ];

    /** Tipos en los que se informa el emisor o corredor (campos 23 a 25 de compras). */
    public const TIPOS_CON_CORREDOR = [33, 58, 59, 60, 63];

    /** Tipos de venta sin fecha de vencimiento de pago (se informa 00000000). */
    private const SIN_VENCIMIENTO = [
        16, 19, 20, 21, 55, 81, 82, 83, 110, 111, 112, 113, 114, 115, 116, 117, 118, 119, 120,
        201, 202, 203, 206, 207, 208, 211, 212, 213,
    ];

    private const EOL = "\r\n";

    /** @var RegistroIva[] */
    private array $ventas = [];

    /** @var RegistroIva[] */
    private array $compras = [];

    private ?\DateTimeImmutable $desde = null;
    private ?\DateTimeImmutable $hasta = null;

    /**
     * @param string|null $cuitInformante CUIT de quien presenta el libro (se usa como
     *                                    corredor en liquidaciones sin intermediario)
     * @param float       $tolerancia     diferencia admitida entre el total y la suma de conceptos
     */
    public function __construct(
        private readonly ?string $cuitInformante = null,
        private readonly float $tolerancia = 0.01
    ) {
    }

    /**
     * Período del libro (año y mes). Las ventas fuera del período son error; las
     * compras posteriores al período también.
     */
    public function setPeriodo(int $anio, int $mes): static
    {
        $this->desde = new \DateTimeImmutable(sprintf('%04d-%02d-01', $anio, $mes));
        $this->hasta = $this->desde->modify('last day of this month');

        return $this;
    }

    public function addVenta(RegistroIva|Invoice|ExportInvoice $venta): static
    {
        $this->ventas[] = $venta instanceof RegistroIva ? $venta : RegistroFactory::fromSale($venta);

        return $this;
    }

    /**
     * @param iterable<RegistroIva|Invoice|ExportInvoice> $ventas
     */
    public function addVentas(iterable $ventas): static
    {
        foreach ($ventas as $venta) {
            $this->addVenta($venta);
        }

        return $this;
    }

    public function addCompra(RegistroIva $compra): static
    {
        $this->compras[] = $compra;

        return $this;
    }

    /**
     * @param iterable<RegistroIva> $compras
     */
    public function addCompras(iterable $compras): static
    {
        foreach ($compras as $compra) {
            $this->addCompra($compra);
        }

        return $this;
    }

    /** @return RegistroIva[] */
    public function getVentas(): array
    {
        return $this->ventas;
    }

    /** @return RegistroIva[] */
    public function getCompras(): array
    {
        return $this->compras;
    }

    public function validate(): ValidationResult
    {
        $result = new ValidationResult();
        $this->validateLibro($this->ventas, self::VENTAS, $result);
        $this->validateLibro($this->compras, self::COMPRAS, $result);

        return $result;
    }

    public function ventasComprobantes(): string
    {
        $this->assertValid();

        return $this->join(array_map(fn (RegistroIva $r): string => $this->ventaLine($r), $this->sorted($this->ventas)));
    }

    public function ventasAlicuotas(): string
    {
        $this->assertValid();
        $lines = [];
        foreach ($this->sorted($this->ventas) as $r) {
            foreach ($this->alicuotasDe($r, self::VENTAS) as $iva) {
                $lines[] = self::tipo($r) . self::pv($r) . self::nro($r->getNumero())
                    . self::amount($iva->getBaseImp()) . sprintf('%04d', $iva->getId()) . self::amount($iva->getImporte());
            }
        }

        return $this->join($lines);
    }

    /**
     * Comprobantes de compra, sin los despachos de importación.
     */
    public function comprasComprobantes(): string
    {
        $this->assertValid();
        $lines = [];
        foreach ($this->sorted($this->compras) as $r) {
            if ($r->getTipoComprobante() !== self::DESPACHO_IMPORTACION) {
                $lines[] = $this->compraLine($r);
            }
        }

        return $this->join($lines);
    }

    public function comprasAlicuotas(): string
    {
        $this->assertValid();
        $lines = [];
        foreach ($this->sorted($this->compras) as $r) {
            if ($r->getTipoComprobante() === self::DESPACHO_IMPORTACION) {
                continue;
            }
            foreach ($this->alicuotasDe($r, self::COMPRAS) as $iva) {
                $lines[] = self::tipo($r) . self::pv($r) . self::nro($r->getNumero())
                    . sprintf('%02d', $r->getDocTipo()) . self::docNro($r)
                    . self::amount($iva->getBaseImp()) . sprintf('%04d', $iva->getId()) . self::amount($iva->getImporte());
            }
        }

        return $this->join($lines);
    }

    /**
     * Despachos de importación (tipo 66).
     */
    public function importacionesComprobantes(): string
    {
        $this->assertValid();
        $lines = [];
        foreach ($this->sorted($this->compras) as $r) {
            if ($r->getTipoComprobante() === self::DESPACHO_IMPORTACION) {
                $lines[] = $this->compraLine($r);
            }
        }

        return $this->join($lines);
    }

    public function importacionesAlicuotas(): string
    {
        $this->assertValid();
        $lines = [];
        foreach ($this->sorted($this->compras) as $r) {
            if ($r->getTipoComprobante() !== self::DESPACHO_IMPORTACION) {
                continue;
            }
            foreach ($this->alicuotasDe($r, self::COMPRAS) as $iva) {
                $lines[] = self::despacho($r) . self::amount($iva->getBaseImp())
                    . sprintf('%04d', $iva->getId()) . self::amount($iva->getImporte());
            }
        }

        return $this->join($lines);
    }

    /**
     * Todos los archivos no vacíos: nombre => contenido (ISO-8859-1, líneas CRLF).
     *
     * @return array<string, string>
     */
    public function files(): array
    {
        $prefix = $this->desde === null ? 'LIBRO_IVA_DIGITAL' : 'LIBRO_IVA_DIGITAL_' . $this->desde->format('Ym');
        $files = [
            $prefix . '_VENTAS_CBTE.txt' => $this->ventasComprobantes(),
            $prefix . '_VENTAS_ALICUOTAS.txt' => $this->ventasAlicuotas(),
            $prefix . '_COMPRAS_CBTE.txt' => $this->comprasComprobantes(),
            $prefix . '_COMPRAS_ALICUOTAS.txt' => $this->comprasAlicuotas(),
            $prefix . '_IMPORTACIONES_CBTE.txt' => $this->importacionesComprobantes(),
            $prefix . '_IMPORTACIONES_ALICUOTAS.txt' => $this->importacionesAlicuotas(),
        ];

        return array_filter($files, static fn (string $content): bool => $content !== '');
    }

    /**
     * Escribe los archivos en un directorio y devuelve sus rutas.
     *
     * @return string[]
     */
    public function writeTo(string $directory): array
    {
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('No se pudo crear el directorio %s.', $directory));
        }
        $paths = [];
        foreach ($this->files() as $name => $content) {
            $path = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $name;
            if (file_put_contents($path, $content) === false) {
                throw new \RuntimeException(sprintf('No se pudo escribir %s.', $path));
            }
            $paths[] = $path;
        }

        return $paths;
    }

    /**
     * Alícuotas que se informan para el comprobante. Los comprobantes que discriminan IVA
     * y no tienen alícuotas (exentos, no gravados) llevan una línea al 0% en cero.
     *
     * @return Iva[]
     */
    public function alicuotasDe(RegistroIva $r, string $libro): array
    {
        if (!self::llevaAlicuotas($r, $libro)) {
            return [];
        }
        $alicuotas = $r->getAlicuotas();

        return $alicuotas === [] ? [new Iva(AlicuotaIva::IVA_0, 0.0, 0.0)] : $alicuotas;
    }

    /**
     * Código de operación informado (el indicado o el deducido de los importes).
     */
    public static function codigoOperacion(RegistroIva $r, string $libro): string
    {
        $codigo = $r->getCodigoOperacion();
        if ($codigo !== null && $codigo !== '') {
            return $codigo;
        }
        $tipo = $r->getTipoComprobante();
        if ($libro === self::VENTAS && TipoComprobante::isWsfex($tipo)) {
            return CodigoOperacion::EXTERIOR;
        }
        if ($libro === self::COMPRAS && $tipo === self::DESPACHO_IMPORTACION) {
            return CodigoOperacion::EXTERIOR;
        }
        $gravado = abs($r->getNetoGravado()) > 0.0 || abs($r->getIva()) > 0.0;
        if (!$gravado && $r->getExento() > 0 && abs($r->getNoGravado()) < 0.005) {
            return CodigoOperacion::EXENTO;
        }
        if (!$gravado && $r->getNoGravado() > 0 && abs($r->getExento()) < 0.005) {
            return CodigoOperacion::NO_GRAVADO;
        }

        return CodigoOperacion::NINGUNO;
    }

    private static function llevaAlicuotas(RegistroIva $r, string $libro): bool
    {
        $tipo = $r->getTipoComprobante();
        if (TipoComprobante::sinIva($tipo)) {
            return false;
        }
        // En compras, B y C no discriminan IVA: no hay crédito fiscal que informar.
        if ($libro === self::COMPRAS && TipoComprobante::clase($tipo) === TipoComprobante::CLASE_B) {
            return false;
        }

        return true;
    }

    private function ventaLine(RegistroIva $r): string
    {
        $tipo = $r->getTipoComprobante();
        $vencimiento = in_array($tipo, self::SIN_VENCIMIENTO, true)
            ? '00000000'
            : ($r->getFechaVencimientoPago() ?? $r->getFecha())->format('Ymd');

        return $r->getFecha()->format('Ymd')
            . self::tipo($r)
            . self::pv($r)
            . self::nro($r->getNumero())
            . self::nro($r->getNumeroHasta())
            . sprintf('%02d', $r->getDocTipo())
            . self::docNro($r)
            . self::text($this->nombre($r), 30)
            . self::amount($r->getTotal())
            . self::amount($r->getNoGravado())
            . self::amount($r->getPercepcionNoCategorizados() + $r->getPercepcionIva())
            . self::amount($r->getExento())
            . self::amount($r->getPercepcionesNacionales())
            . self::amount($r->getPercepcionesIibb())
            . self::amount($r->getPercepcionesMunicipales())
            . self::amount($r->getImpuestosInternos())
            . self::text($r->getMoneda(), 3)
            . self::rate($r)
            . (string) count($this->alicuotasDe($r, self::VENTAS))
            . self::codigoOperacion($r, self::VENTAS)
            . self::amount($r->getOtrosTributos())
            . $vencimiento;
    }

    private function compraLine(RegistroIva $r): string
    {
        $tipo = $r->getTipoComprobante();
        $alicuotas = $this->alicuotasDe($r, self::COMPRAS);
        $credito = $r->getCreditoFiscalComputable()
            ?? ($alicuotas === [] ? 0.0 : $r->getIva());
        $conCorredor = in_array($tipo, self::TIPOS_CON_CORREDOR, true);
        $cuitCorredor = $conCorredor ? ($r->getCuitCorredor() ?? Cuit::normalize((string) $this->cuitInformante)) : '';

        return $r->getFecha()->format('Ymd')
            . self::tipo($r)
            . self::pv($r)
            . self::nro($r->getNumero())
            . ($tipo === self::DESPACHO_IMPORTACION ? self::despacho($r) : str_repeat(' ', 16))
            . sprintf('%02d', $r->getDocTipo())
            . self::docNro($r)
            . self::text($r->getNombre(), 30)
            . self::amount($r->getTotal())
            . self::amount($r->getNoGravado())
            . self::amount($r->getExento())
            . self::amount($r->getPercepcionIva() + $r->getPercepcionNoCategorizados())
            . self::amount($r->getPercepcionesNacionales())
            . self::amount($r->getPercepcionesIibb())
            . self::amount($r->getPercepcionesMunicipales())
            . self::amount($r->getImpuestosInternos())
            . self::text($r->getMoneda(), 3)
            . self::rate($r)
            . (string) count($alicuotas)
            . self::codigoOperacion($r, self::COMPRAS)
            . self::amount($credito)
            . self::amount($r->getOtrosTributos())
            . str_pad($conCorredor ? $cuitCorredor : '', 11, '0', STR_PAD_LEFT)
            . self::text($conCorredor ? (string) $r->getDenominacionCorredor() : '', 30)
            . self::amount($conCorredor ? $r->getIvaComision() : 0.0);
    }

    /**
     * @param RegistroIva[] $registros
     */
    private function validateLibro(array $registros, string $libro, ValidationResult $result): void
    {
        $vistos = [];
        foreach ($registros as $i => $r) {
            $f = sprintf('%s[%d].', $libro, $i);
            $tipo = $r->getTipoComprobante();
            $esDespacho = $libro === self::COMPRAS && $tipo === self::DESPACHO_IMPORTACION;

            if ($tipo < 1 || $tipo > 999) {
                $result->error('LIBRO_TIPO_INVALIDO', $f . 'tipoComprobante', sprintf('Tipo de comprobante %d fuera de rango (1 a 999).', $tipo));
            } elseif (!TipoComprobante::exists($tipo) && !isset(self::OTROS_TIPOS[$tipo])) {
                $result->warning('LIBRO_TIPO_DESCONOCIDO', $f . 'tipoComprobante', sprintf('El tipo de comprobante %d no está en el catálogo del SDK: verifique que el Libro IVA Digital lo acepte.', $tipo));
            }
            if ($libro === self::VENTAS && $tipo === self::DESPACHO_IMPORTACION) {
                $result->error('LIBRO_DESPACHO_EN_VENTAS', $f . 'tipoComprobante', 'El despacho de importación (66) solo va en el libro de compras.');
            }
            if ($r->getPuntoVenta() < 0 || $r->getPuntoVenta() > 99999) {
                $result->error('LIBRO_PV_INVALIDO', $f . 'puntoVenta', 'El punto de venta debe estar entre 0 y 99999.');
            }
            if (!$esDespacho && ($r->getNumero() < 1 || $r->getNumero() > 99999999)) {
                $result->error('LIBRO_NUMERO_INVALIDO', $f . 'numero', 'El número de comprobante debe estar entre 1 y 99999999.');
            }
            if ($libro === self::VENTAS && $r->getNumeroHasta() < $r->getNumero()) {
                $result->error('LIBRO_NUMERO_HASTA', $f . 'numeroHasta', 'El número hasta no puede ser menor que el número desde.');
            }
            if ($libro === self::COMPRAS && $r->getNumeroHasta() !== $r->getNumero()) {
                $result->error('LIBRO_NUMERO_HASTA_COMPRAS', $f . 'numeroHasta', 'En compras cada comprobante se registra por separado (sin rango de números).');
            }
            if ($esDespacho && !preg_match('/^[0-9A-Z]{16}$/', (string) $r->getDespachoImportacion())) {
                $result->error('LIBRO_DESPACHO_INVALIDO', $f . 'despachoImportacion', 'El despacho de importación debe tener 16 caracteres alfanuméricos.');
            }

            $this->validatePeriodo($r, $libro, $f, $result);
            $this->validateContraparte($r, $libro, $f, $result);
            $this->validateImportes($r, $libro, $f, $result);

            $clave = $r->getClave($libro === self::COMPRAS);
            if (isset($vistos[$clave])) {
                $result->error('LIBRO_DUPLICADO', $f . 'numero', sprintf('Comprobante duplicado (%s): también está en la posición %d.', $clave, $vistos[$clave]));
            } else {
                $vistos[$clave] = $i;
            }
        }
    }

    private function validatePeriodo(RegistroIva $r, string $libro, string $f, ValidationResult $result): void
    {
        if ($this->desde === null || $this->hasta === null) {
            return;
        }
        $fecha = $r->getFecha()->format('Y-m-d');
        if ($fecha > $this->hasta->format('Y-m-d')) {
            $result->error('LIBRO_FECHA_POSTERIOR', $f . 'fecha', sprintf('La fecha %s es posterior al período %s.', $fecha, $this->desde->format('m/Y')));
        } elseif ($libro === self::VENTAS && $fecha < $this->desde->format('Y-m-d')) {
            $result->error('LIBRO_FECHA_ANTERIOR', $f . 'fecha', sprintf('La venta del %s no corresponde al período %s.', $fecha, $this->desde->format('m/Y')));
        }
    }

    private function validateContraparte(RegistroIva $r, string $libro, string $f, ValidationResult $result): void
    {
        $tipo = $r->getTipoComprobante();
        $docTipo = $r->getDocTipo();
        $docNro = $r->getDocNro();
        $clase = TipoComprobante::clase($tipo);

        if (!TipoDocumento::exists($docTipo)) {
            $result->error('LIBRO_DOC_TIPO', $f . 'docTipo', sprintf('Tipo de documento %d inexistente.', $docTipo));
        }
        if (strlen($docNro) > 20) {
            $result->error('LIBRO_DOC_LARGO', $f . 'docNro', 'El número de documento no puede superar los 20 dígitos.');
        }
        if (TipoDocumento::isCuitLike($docTipo) && !Cuit::isValid($docNro)) {
            $result->error('LIBRO_CUIT_INVALIDO', $f . 'docNro', sprintf('%s inválido: %s.', TipoDocumento::description($docTipo), $docNro));
        }

        if ($libro === self::COMPRAS) {
            if ($tipo !== self::DESPACHO_IMPORTACION && ($docTipo === TipoDocumento::OTRO || (int) $docNro === 0)) {
                $result->error('LIBRO_VENDEDOR_SIN_DOC', $f . 'docNro', 'En compras hay que informar el documento del vendedor (no se admite el tipo 99 ni el número 0).');
            }
            if (trim($r->getNombre()) === '') {
                $result->error('LIBRO_VENDEDOR_SIN_NOMBRE', $f . 'nombre', 'Falta la denominación del vendedor.');
            }
            if (in_array($clase, [TipoComprobante::CLASE_A, TipoComprobante::CLASE_M], true) && $docTipo !== TipoDocumento::CUIT) {
                $result->error('LIBRO_VENDEDOR_CUIT', $f . 'docTipo', 'Un comprobante A o M lo emite un responsable inscripto: el vendedor se identifica con CUIT (80).');
            }

            return;
        }

        if (in_array($clase, [TipoComprobante::CLASE_A, TipoComprobante::CLASE_M], true) && $docTipo !== TipoDocumento::CUIT) {
            $result->error('LIBRO_COMPRADOR_CUIT', $f . 'docTipo', 'Un comprobante A o M exige CUIT (80) del comprador.');
        }
        if ($docTipo !== TipoDocumento::OTRO && (int) $docNro === 0) {
            $result->error('LIBRO_COMPRADOR_SIN_NRO', $f . 'docNro', 'Falta el número de documento del comprador (para consumidor final sin identificar use el tipo 99 y número 0).');
        }
        if ($docTipo !== TipoDocumento::OTRO && trim($r->getNombre()) === '') {
            $result->error('LIBRO_COMPRADOR_SIN_NOMBRE', $f . 'nombre', 'Falta la denominación del comprador.');
        }
    }

    private function validateImportes(RegistroIva $r, string $libro, string $f, ValidationResult $result): void
    {
        $tipo = $r->getTipoComprobante();

        $importes = [
            'total' => $r->getTotal(),
            'noGravado' => $r->getNoGravado(),
            'exento' => $r->getExento(),
            'percepcionIva' => $r->getPercepcionIva(),
            'percepcionNoCategorizados' => $r->getPercepcionNoCategorizados(),
            'percepcionesNacionales' => $r->getPercepcionesNacionales(),
            'percepcionesIibb' => $r->getPercepcionesIibb(),
            'percepcionesMunicipales' => $r->getPercepcionesMunicipales(),
            'impuestosInternos' => $r->getImpuestosInternos(),
            'otrosTributos' => $r->getOtrosTributos(),
            'ivaComision' => $r->getIvaComision(),
        ];
        foreach ($importes as $campo => $valor) {
            if (abs(Num::round($valor)) >= 1e12) {
                $result->error('LIBRO_IMPORTE_EXCEDE', $f . $campo, 'El importe no entra en el campo de 15 posiciones (máximo 999.999.999.999,99).');
            }
        }
        if ($r->getTotal() < 0) {
            $result->error('LIBRO_TOTAL_NEGATIVO', $f . 'total', 'El total va en positivo, también en las notas de crédito.');
        }
        if ($libro === self::VENTAS && abs($r->getPercepcionNoCategorizados()) > 0 && TipoComprobante::clase($tipo) === TipoComprobante::CLASE_C) {
            $result->error('LIBRO_PERCEPCION_NO_CATEG_C', $f . 'percepcionNoCategorizados', 'Un comprobante C no lleva percepción a no categorizados.');
        }

        // Moneda y cotización
        $moneda = $r->getMoneda();
        if (!preg_match('/^[0-9A-Z]{3}$/', $moneda)) {
            $result->error('LIBRO_MONEDA', $f . 'moneda', 'La moneda debe ser un código de 3 caracteres (ej. PES, DOL).');
        } elseif (!Moneda::exists($moneda)) {
            $result->warning('LIBRO_MONEDA_DESCONOCIDA', $f . 'moneda', sprintf('Moneda %s no está en el catálogo del SDK.', $moneda));
        }
        if ($moneda === Moneda::PESOS && abs($r->getCotizacion() - 1.0) > 1e-9) {
            $result->error('LIBRO_COTIZACION_PESOS', $f . 'cotizacion', 'En pesos la cotización es 1.');
        }
        if ($r->getCotizacion() <= 0 || $r->getCotizacion() >= 10000) {
            $result->error('LIBRO_COTIZACION', $f . 'cotizacion', 'La cotización debe ser mayor que 0 y menor que 10.000 (4 enteros y 6 decimales).');
        }

        // Código de operación
        $codigo = $r->getCodigoOperacion();
        if ($codigo !== null && $codigo !== '' && !CodigoOperacion::exists($codigo, $libro)) {
            $result->error('LIBRO_CODIGO_OPERACION', $f . 'codigoOperacion', sprintf(
                'Código de operación "%s" inválido para %s (válidos: %s).',
                $codigo,
                $libro,
                implode(', ', array_keys(CodigoOperacion::all($libro)))
            ));
        }

        // Alícuotas
        $alicuotas = $r->getAlicuotas();
        if (!self::llevaAlicuotas($r, $libro) && $alicuotas !== []) {
            $result->error('LIBRO_ALICUOTAS_NO_CORRESPONDEN', $f . 'alicuotas', $libro === self::COMPRAS
                ? 'Los comprobantes B y C recibidos no discriminan IVA: no se informan alícuotas (el importe va como no gravado o en el total).'
                : 'Los comprobantes C no discriminan IVA: no se informan alícuotas.');
        }
        if (count($alicuotas) > 9) {
            $result->error('LIBRO_ALICUOTAS_MAX', $f . 'alicuotas', 'Un comprobante admite como máximo 9 alícuotas.');
        }
        $ids = [];
        foreach ($alicuotas as $j => $iva) {
            $fa = sprintf('%salicuotas[%d].', $f, $j);
            if (!AlicuotaIva::exists($iva->getId())) {
                $result->error('LIBRO_ALICUOTA_INVALIDA', $fa . 'id', sprintf('Alícuota %d inexistente (válidas: 3, 4, 5, 6, 8, 9).', $iva->getId()));
                continue;
            }
            if (isset($ids[$iva->getId()])) {
                $result->error('LIBRO_ALICUOTA_REPETIDA', $fa . 'id', sprintf('La alícuota %s está repetida: agrupe los importes en una sola línea.', AlicuotaIva::description($iva->getId())));
            }
            $ids[$iva->getId()] = true;
            $esperado = Num::round($iva->getBaseImp() * AlicuotaIva::rate($iva->getId()) / 100);
            if (abs($esperado - $iva->getImporte()) > max(0.01, abs($esperado) * 0.0001) + 1e-9) {
                $result->warning('LIBRO_ALICUOTA_IMPORTE', $fa . 'importe', sprintf(
                    'El IVA %s de %s debería ser %s y es %s.',
                    AlicuotaIva::description($iva->getId()),
                    Num::format($iva->getBaseImp()),
                    Num::format($esperado),
                    Num::format($iva->getImporte())
                ));
            }
        }
        if ($libro === self::VENTAS && TipoComprobante::discriminaIva($tipo) && $alicuotas === [] && $r->getNoGravado() == 0.0 && $r->getExento() == 0.0 && $r->getTotal() > 0
            && !TipoComprobante::isWsfex($tipo)) {
            $result->error('LIBRO_SIN_ALICUOTAS', $f . 'alicuotas', 'El comprobante discrimina IVA: informe sus alícuotas o el importe exento / no gravado.');
        }

        // Crédito fiscal (compras)
        $credito = $r->getCreditoFiscalComputable();
        if ($libro === self::COMPRAS && $credito !== null) {
            if ($credito < 0) {
                $result->error('LIBRO_CREDITO_NEGATIVO', $f . 'creditoFiscalComputable', 'El crédito fiscal computable no puede ser negativo.');
            } elseif ($credito > $r->getIva() + 0.01) {
                $result->error('LIBRO_CREDITO_EXCEDE', $f . 'creditoFiscalComputable', 'El crédito fiscal computable no puede superar el IVA del comprobante.');
            }
        }

        // Corredor (compras)
        if ($libro === self::COMPRAS && in_array($tipo, self::TIPOS_CON_CORREDOR, true)) {
            $cuit = $r->getCuitCorredor() ?? $this->cuitInformante;
            if ($cuit === null || !Cuit::isValid($cuit)) {
                $result->error('LIBRO_CORREDOR_CUIT', $f . 'cuitCorredor', 'En liquidaciones se informa la CUIT del emisor o corredor (o la del informante si no hay intermediario).');
            }
        }

        // Total = suma de conceptos. Los comprobantes que no discriminan IVA (C, y B o C
        // recibidos) informan el total sin desglose: los conceptos no pueden superarlo.
        $tolerancia = max($this->tolerancia, 0.01 * max(1, count($alicuotas)));
        $diferencia = Num::round($r->getTotal() - $r->getSumaConceptos());
        if (self::llevaAlicuotas($r, $libro)) {
            if (abs($diferencia) > $tolerancia + 1e-9) {
                $result->error('LIBRO_TOTAL_NO_CUADRA', $f . 'total', sprintf(
                    'El total %s no coincide con la suma de conceptos %s (diferencia %s).',
                    Num::format($r->getTotal()),
                    Num::format($r->getSumaConceptos()),
                    Num::format($diferencia)
                ));
            }
        } elseif ($diferencia < -$tolerancia - 1e-9) {
            $result->error('LIBRO_CONCEPTOS_EXCEDEN', $f . 'total', sprintf(
                'Los conceptos informados (%s) superan el total %s.',
                Num::format($r->getSumaConceptos()),
                Num::format($r->getTotal())
            ));
        }
    }

    private function nombre(RegistroIva $r): string
    {
        $nombre = trim($r->getNombre());

        return $nombre === '' && $r->getDocTipo() === TipoDocumento::OTRO ? 'CONSUMIDOR FINAL' : $nombre;
    }

    private function assertValid(): void
    {
        $result = $this->validate();
        if (!$result->isValid()) {
            throw new ValidationException($result);
        }
    }

    /**
     * Orden por fecha, tipo, punto de venta y número.
     *
     * @param RegistroIva[] $registros
     *
     * @return RegistroIva[]
     */
    private function sorted(array $registros): array
    {
        usort($registros, static fn (RegistroIva $a, RegistroIva $b): int => [
            $a->getFecha()->format('Ymd'), $a->getTipoComprobante(), $a->getPuntoVenta(), $a->getNumero(),
        ] <=> [
            $b->getFecha()->format('Ymd'), $b->getTipoComprobante(), $b->getPuntoVenta(), $b->getNumero(),
        ]);

        return $registros;
    }

    /**
     * @param string[] $lines
     */
    private function join(array $lines): string
    {
        return implode(self::EOL, $lines);
    }

    private static function tipo(RegistroIva $r): string
    {
        return sprintf('%03d', $r->getTipoComprobante());
    }

    private static function pv(RegistroIva $r): string
    {
        return sprintf('%05d', $r->getPuntoVenta());
    }

    private static function nro(int $numero): string
    {
        return str_pad((string) $numero, 20, '0', STR_PAD_LEFT);
    }

    private static function docNro(RegistroIva $r): string
    {
        return str_pad($r->getDocNro(), 20, '0', STR_PAD_LEFT);
    }

    private static function despacho(RegistroIva $r): string
    {
        return str_pad((string) $r->getDespachoImportacion(), 16, '0', STR_PAD_LEFT);
    }

    /**
     * Importe con 13 enteros y 2 decimales, sin separador; los negativos llevan "-".
     */
    public static function amount(float $value): string
    {
        $cents = (int) round(abs(Num::round($value)) * 100);

        return $value < 0 && $cents > 0
            ? '-' . str_pad((string) $cents, 14, '0', STR_PAD_LEFT)
            : str_pad((string) $cents, 15, '0', STR_PAD_LEFT);
    }

    /**
     * Tipo de cambio con 4 enteros y 6 decimales.
     */
    private static function rate(RegistroIva $r): string
    {
        $value = $r->getMoneda() === Moneda::PESOS ? 1.0 : $r->getCotizacion();

        return str_pad((string) (int) round($value * 1000000), 10, '0', STR_PAD_LEFT);
    }

    /**
     * Texto en ISO-8859-1, recortado y completado con espacios a la derecha.
     */
    public static function text(string $value, int $length): string
    {
        $value = (string) preg_replace('/[\r\n\t]+/', ' ', trim($value));
        $latin = (string) mb_convert_encoding(mb_substr($value, 0, $length, 'UTF-8'), 'ISO-8859-1', 'UTF-8');

        return str_pad(substr($latin, 0, $length), $length, ' ');
    }
}
