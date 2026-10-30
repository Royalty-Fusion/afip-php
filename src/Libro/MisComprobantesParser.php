<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Libro;

use RoyaltyFusion\Afip\Catalog\AlicuotaIva;
use RoyaltyFusion\Afip\Catalog\Moneda;
use RoyaltyFusion\Afip\Catalog\TipoComprobante;
use RoyaltyFusion\Afip\Catalog\TipoDocumento;
use RoyaltyFusion\Afip\Support\Num;

/**
 * Lee el CSV que exporta el servicio "Mis Comprobantes" de ARCA (emitidos o recibidos).
 *
 * ARCA no tiene un web service para descargar los comprobantes recibidos: el camino
 * oficial es exportar el CSV desde el portal e importarlo. El formato no está publicado
 * y cambió con los años, así que el lector reconoce las columnas por su nombre (sin
 * importar mayúsculas, tildes ni puntuación), detecta el separador (; o ,), el formato
 * de los números (1.234,56 o 1234.56) y la codificación, y saltea las líneas de título.
 *
 * Reconoce tanto el formato con neto e IVA totales como el que trae una columna por
 * alícuota ("Neto Grav. IVA 21%", "IVA 21%"). Si solo hay totales, deduce la alícuota
 * del cociente IVA / neto; si no encaja con ninguna, lo informa en {@see getWarnings()}.
 *
 * Pruebe el lector con una exportación real antes de usarlo en producción y, si ARCA
 * renombra una columna, agregue el alias con {@see setAlias()}.
 */
final class MisComprobantesParser
{
    public const RECIBIDOS = 'recibidos';
    public const EMITIDOS = 'emitidos';

    /** Campo => alias normalizados (minúsculas, sin tildes ni puntuación). */
    private const ALIASES = [
        'fecha' => ['fecha', 'fecha de emision', 'fecha emision', 'fecha cbte', 'fecha comprobante'],
        'tipo' => ['tipo', 'tipo de comprobante', 'tipo comprobante', 'tipo cbte'],
        'puntoVenta' => ['punto de venta', 'pto vta', 'pto de vta', 'punto venta'],
        'numero' => ['numero desde', 'nro desde', 'numero', 'nro comprobante', 'numero de comprobante'],
        'numeroHasta' => ['numero hasta', 'nro hasta'],
        'codigo' => ['cod autorizacion', 'codigo autorizacion', 'codigo de autorizacion', 'cae', 'cod de autorizacion'],
        'docTipo' => ['tipo doc emisor', 'tipo doc receptor', 'tipo doc', 'tipo documento', 'tipo de documento', 'tipo doc vendedor', 'tipo doc comprador'],
        'docNro' => ['nro doc emisor', 'nro doc receptor', 'nro doc', 'numero de documento', 'nro documento', 'cuit', 'nro doc vendedor', 'nro doc comprador'],
        'nombre' => ['denominacion emisor', 'denominacion receptor', 'denominacion', 'razon social', 'nombre'],
        'cotizacion' => ['tipo cambio', 'tipo de cambio', 'cotizacion'],
        'moneda' => ['moneda'],
        'neto' => ['imp neto gravado', 'neto gravado', 'importe neto gravado', 'imp neto gravado total', 'total neto gravado'],
        'noGravado' => ['imp neto no gravado', 'neto no gravado', 'importe no gravado', 'no gravado'],
        'exento' => ['imp op exentas', 'op exentas', 'importe exento', 'exento', 'operaciones exentas'],
        'otrosTributos' => ['otros tributos', 'imp otros tributos', 'otros impuestos'],
        'iva' => ['iva', 'total iva', 'imp iva', 'importe iva'],
        'total' => ['imp total', 'importe total', 'total'],
    ];

    private const EMISOR = ['emisor', 'vendedor'];
    private const RECEPTOR = ['receptor', 'comprador'];

    /** @var array<string, string> alias normalizado => campo */
    private array $extraAliases = [];

    /** @var string[] */
    private array $warnings = [];

    /**
     * Agrega un nombre de columna para un campo (ej. setAlias('total', 'Imp. Total $')).
     */
    public function setAlias(string $campo, string $columna): static
    {
        if (!isset(self::ALIASES[$campo])) {
            throw new \InvalidArgumentException(sprintf('Campo desconocido: %s (válidos: %s).', $campo, implode(', ', array_keys(self::ALIASES))));
        }
        $this->extraAliases[self::normalize($columna)] = $campo;

        return $this;
    }

    /**
     * @return string[] avisos de la última lectura (filas omitidas, alícuotas deducidas)
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * @param string $rol RECIBIDOS (compras: la contraparte es el emisor) o EMITIDOS
     *                    (ventas: la contraparte es el receptor)
     *
     * @return RegistroIva[]
     */
    public function parse(string $contenido, string $rol = self::RECIBIDOS): array
    {
        if (!in_array($rol, [self::RECIBIDOS, self::EMITIDOS], true)) {
            throw new \InvalidArgumentException('El rol debe ser "recibidos" o "emitidos".');
        }
        $this->warnings = [];
        $contenido = self::toUtf8($contenido);
        $lines = preg_split('/\r\n|\r|\n/', $contenido) ?: [];

        $headerIndex = null;
        $delimiter = ';';
        foreach ($lines as $i => $line) {
            $n = self::normalize($line);
            if (str_contains($n, 'fecha') && str_contains($n, 'tipo') && (str_contains($n, 'total') || str_contains($n, 'punto de venta'))) {
                $headerIndex = $i;
                $delimiter = substr_count($line, ';') >= substr_count($line, ',') ? ';' : ',';
                break;
            }
        }
        if ($headerIndex === null) {
            throw new \InvalidArgumentException('No se encontró la fila de encabezados (Fecha, Tipo, ... Imp. Total) en el archivo.');
        }

        $headers = str_getcsv($lines[$headerIndex], $delimiter, '"', '');
        $map = $this->mapColumns($headers, $rol);
        foreach (['fecha', 'tipo', 'puntoVenta', 'numero', 'total'] as $required) {
            if (!isset($map['fields'][$required])) {
                throw new \InvalidArgumentException(sprintf('Falta la columna "%s" en el archivo (encabezados: %s).', $required, implode(' | ', $headers)));
            }
        }

        $registros = [];
        $count = count($lines);
        for ($i = $headerIndex + 1; $i < $count; $i++) {
            if (trim($lines[$i]) === '' || trim($lines[$i], " ;,\t\"") === '') {
                continue;
            }
            $row = str_getcsv($lines[$i], $delimiter, '"', '');
            try {
                $registros[] = $this->buildRecord($row, $map, $i + 1);
            } catch (\InvalidArgumentException $e) {
                $this->warnings[] = sprintf('Línea %d omitida: %s', $i + 1, $e->getMessage());
            }
        }

        return $registros;
    }

    /**
     * @return RegistroIva[]
     */
    public function parseFile(string $path, string $rol = self::RECIBIDOS): array
    {
        $contenido = @file_get_contents($path);
        if ($contenido === false) {
            throw new \RuntimeException(sprintf('No se pudo leer %s.', $path));
        }

        return $this->parse($contenido, $rol);
    }

    /**
     * @param array<int, string|null> $headers
     *
     * @return array{fields: array<string, int>, netos: array<int, int>, ivas: array<int, int>}
     */
    private function mapColumns(array $headers, string $rol): array
    {
        $propios = $rol === self::RECIBIDOS ? self::EMISOR : self::RECEPTOR;
        $ajenos = $rol === self::RECIBIDOS ? self::RECEPTOR : self::EMISOR;
        $fields = [];
        $netos = [];
        $ivas = [];

        foreach ($headers as $index => $header) {
            $n = self::normalize((string) $header);
            if ($n === '') {
                continue;
            }
            // Columnas de la otra parte (ej. "Nro. Doc. Receptor" en recibidos): se ignoran.
            foreach ($ajenos as $palabra) {
                if (str_contains($n, $palabra)) {
                    continue 2;
                }
            }

            // Columnas por alícuota: "neto grav iva 21%", "iva 10,5%".
            if (preg_match('/(\d+(?:[.,]\d+)?)\s*%/', (string) $header, $m)) {
                $id = AlicuotaIva::fromRate((float) str_replace(',', '.', $m[1]));
                if ($id !== null) {
                    if (str_contains($n, 'neto')) {
                        $netos[$id] = $index;
                    } else {
                        $ivas[$id] = $index;
                    }
                }
                continue;
            }

            $campo = $this->extraAliases[$n] ?? null;
            if ($campo === null) {
                $sinRol = trim((string) preg_replace('/\b(' . implode('|', $propios) . ')\b/', '', $n));
                $sinRol = (string) preg_replace('/\s+/', ' ', $sinRol);
                foreach (self::ALIASES as $f => $aliases) {
                    if (in_array($n, $aliases, true) || in_array($sinRol, $aliases, true)) {
                        $campo = $f;
                        break;
                    }
                }
            }
            if ($campo !== null && !isset($fields[$campo])) {
                $fields[$campo] = $index;
            }
        }

        return ['fields' => $fields, 'netos' => $netos, 'ivas' => $ivas];
    }

    /**
     * @param array<int, string|null>                                                       $row
     * @param array{fields: array<string, int>, netos: array<int, int>, ivas: array<int, int>} $map
     */
    private function buildRecord(array $row, array $map, int $line): RegistroIva
    {
        $get = static fn (string $campo): string => isset($map['fields'][$campo]) ? trim((string) ($row[$map['fields'][$campo]] ?? '')) : '';
        $num = static fn (string $campo): float => self::number($get($campo));

        $fecha = self::date($get('fecha'));
        $tipo = self::tipo($get('tipo'));
        $numero = (int) preg_replace('/\D/', '', $get('numero'));
        if ($numero <= 0) {
            throw new \InvalidArgumentException('número de comprobante vacío');
        }

        $r = new RegistroIva($fecha, $tipo, (int) preg_replace('/\D/', '', $get('puntoVenta')), $numero);
        $hasta = (int) preg_replace('/\D/', '', $get('numeroHasta'));
        if ($hasta > $numero) {
            $r->setNumeroHasta($hasta);
        }
        $r->setContraparte(self::docTipo($get('docTipo')), $get('docNro'), $get('nombre'));

        $moneda = self::moneda($get('moneda'));
        $cotizacion = $num('cotizacion');
        $r->setMoneda($moneda, $moneda === Moneda::PESOS || $cotizacion <= 0 ? 1.0 : $cotizacion);

        // Mis Comprobantes puede mostrar las notas de crédito en negativo: el libro las
        // registra en positivo.
        $abs = static fn (float $v): float => abs($v);
        $r->setTotal($abs($num('total')))
            ->setNoGravado($abs($num('noGravado')))
            ->setExento($abs($num('exento')))
            ->setOtrosTributos($abs($num('otrosTributos')))
            ->setCodigoAutorizacion($get('codigo') === '' ? null : $get('codigo'));

        if (!TipoComprobante::sinIva($tipo)) {
            $this->applyAlicuotas($r, $row, $map, $abs($num('neto')), $abs($num('iva')), $line);
        }

        return $r;
    }

    /**
     * @param array<int, string|null>                                                       $row
     * @param array{fields: array<string, int>, netos: array<int, int>, ivas: array<int, int>} $map
     */
    private function applyAlicuotas(RegistroIva $r, array $row, array $map, float $neto, float $iva, int $line): void
    {
        $ids = array_unique(array_merge(array_keys($map['netos']), array_keys($map['ivas'])));
        sort($ids);
        $porAlicuota = false;
        foreach ($ids as $id) {
            $n = isset($map['netos'][$id]) ? abs(self::number((string) ($row[$map['netos'][$id]] ?? ''))) : 0.0;
            $i = isset($map['ivas'][$id]) ? abs(self::number((string) ($row[$map['ivas'][$id]] ?? ''))) : 0.0;
            if ($n == 0.0 && $i == 0.0) {
                continue;
            }
            if ($n == 0.0 && $id !== AlicuotaIva::IVA_0) {
                $n = Num::round($i * 100 / AlicuotaIva::rate($id));
            }
            $r->addAlicuota($id, $n, $i);
            $porAlicuota = true;
        }
        if ($porAlicuota || ($neto == 0.0 && $iva == 0.0)) {
            return;
        }

        if ($neto == 0.0) {
            $this->warnings[] = sprintf('Línea %d (%s): hay IVA %s sin neto gravado; complete las alícuotas a mano.', $line, $r->getClave(), Num::format($iva));

            return;
        }
        $rate = $iva / $neto * 100;
        $id = null;
        foreach (AlicuotaIva::all() as $candidate => $value) {
            if (abs(Num::round($neto * $value / 100) - $iva) <= 0.01 * 2 || abs($rate - $value) < 0.05) {
                $id = $candidate;
                break;
            }
        }
        if ($id === null) {
            $this->warnings[] = sprintf(
                'Línea %d (%s): el IVA %s sobre %s (%s%%) no corresponde a una sola alícuota; complete las alícuotas a mano.',
                $line,
                $r->getClave(),
                Num::format($iva),
                Num::format($neto),
                number_format($rate, 2, ',', '')
            );

            return;
        }
        $r->addAlicuota($id, $neto, $iva);
    }

    private static function date(string $value): \DateTimeImmutable
    {
        foreach (['!d/m/Y', '!j/n/Y', '!Y-m-d', '!d-m-Y', '!Ymd', '!d/m/y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            if ($date !== false && $date->format(substr($format, 1)) === $value) {
                return $date;
            }
        }

        throw new \InvalidArgumentException(sprintf('fecha inválida "%s"', $value));
    }

    /**
     * "1 - Factura A", "001", "Factura A" o "Nota de Crédito B".
     */
    private static function tipo(string $value): int
    {
        if (preg_match('/^\s*(\d{1,3})\b/', $value, $m)) {
            return (int) $m[1];
        }
        $n = self::normalize($value);
        foreach (TipoComprobante::all() as $id => $description) {
            if (self::normalize($description) === $n) {
                return $id;
            }
        }

        throw new \InvalidArgumentException(sprintf('tipo de comprobante desconocido "%s"', $value));
    }

    private static function docTipo(string $value): int
    {
        if (preg_match('/^\s*(\d{1,2})\b/', $value, $m)) {
            return (int) $m[1];
        }
        $n = self::normalize($value);
        foreach (TipoDocumento::all() as $id => $description) {
            if (self::normalize($description) === $n) {
                return $id;
            }
        }

        return $value === '' ? TipoDocumento::CUIT : TipoDocumento::OTRO;
    }

    private static function moneda(string $value): string
    {
        $v = strtoupper(trim($value));

        return match (true) {
            $v === '' || $v === '$' || $v === 'ARS' || $v === 'PESOS' => Moneda::PESOS,
            in_array($v, ['USD', 'U$S', 'US$', 'DOLAR', 'DÓLAR', 'DÓLAR ESTADOUNIDENSE'], true) => Moneda::DOLAR,
            in_array($v, ['EUR', '€', 'EURO'], true) => Moneda::EURO,
            default => $v,
        };
    }

    /**
     * "1.234,56", "1234,56", "1234.56", "-1.234,56", "$ 1.234,56" o vacío.
     */
    public static function number(string $value): float
    {
        $v = (string) preg_replace('/[^\d,.\-]/', '', $value);
        if ($v === '' || $v === '-') {
            return 0.0;
        }
        $lastComma = strrpos($v, ',');
        $lastDot = strrpos($v, '.');
        if ($lastComma !== false && ($lastDot === false || $lastComma > $lastDot)) {
            $v = str_replace(['.', ','], ['', '.'], $v);
        } elseif ($lastDot !== false && $lastComma !== false) {
            $v = str_replace(',', '', $v);
        } elseif ($lastDot !== false && substr_count($v, '.') > 1) {
            $v = str_replace('.', '', $v);
        }

        return (float) $v;
    }

    private static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = strtr($value, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'º' => '', '°' => '']);
        $value = (string) preg_replace('/[^a-z0-9%]+/', ' ', $value);

        return trim((string) preg_replace('/\s+/', ' ', $value));
    }

    private static function toUtf8(string $value): string
    {
        if (str_starts_with($value, "\xEF\xBB\xBF")) {
            $value = substr($value, 3);
        }

        return mb_check_encoding($value, 'UTF-8') ? $value : (string) mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
    }
}
