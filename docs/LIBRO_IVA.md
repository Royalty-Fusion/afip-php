# Libro IVA Digital y compras

## Archivos que genera `LibroIvaDigital`

Son los TXT de ancho fijo que se suben con "Importar" en el servicio **Libro de IVA
Digital** de ARCA (RG 4597). El diseño de registro es el del ex Régimen de Información de
Compras y Ventas (RG 3685), con los importes en la moneda del comprobante.

| Método | Archivo | Largo del registro |
|---|---|---|
| `ventasComprobantes()` | `…_VENTAS_CBTE.txt` | 266 |
| `ventasAlicuotas()` | `…_VENTAS_ALICUOTAS.txt` | 62 |
| `comprasComprobantes()` | `…_COMPRAS_CBTE.txt` (sin despachos) | 325 |
| `comprasAlicuotas()` | `…_COMPRAS_ALICUOTAS.txt` | 84 |
| `importacionesComprobantes()` | `…_IMPORTACIONES_CBTE.txt` (despachos, tipo 66) | 325 |
| `importacionesAlicuotas()` | `…_IMPORTACIONES_ALICUOTAS.txt` | 50 |

- Codificación ISO-8859-1 y líneas separadas por CRLF. Los registros se ordenan por fecha,
  tipo, punto de venta y número.
- Importes con 13 enteros y 2 decimales sin separador; tipo de cambio con 4 enteros y 6
  decimales. Las notas de crédito van en positivo.
- Los comprobantes que discriminan IVA y no tienen alícuotas (exentos o no gravados)
  llevan una línea al 0% en cero, como pide el libro. Los comprobantes C, y los B y C
  recibidos, no informan alícuotas.
- `files()` devuelve solo los archivos con contenido; `writeTo($dir)` los escribe.

Antes de subir los archivos, conviene hacer una importación de prueba en el servicio de
ARCA con un período chico: el diseño de registro puede cambiar.

## De dónde salen los registros

| Origen | Cómo |
|---|---|
| Comprobantes emitidos con el SDK | `addVenta($invoice)` o `RegistroFactory::fromInvoice($invoice)` |
| Exportaciones | `addVenta($exportInvoice)`: total como exento y código de operación X |
| CSV de "Mis Comprobantes" | `MisComprobantesParser::parse($csv, 'recibidos' \| 'emitidos')` |
| QR impreso en una compra | `RegistroFactory::fromQr($url)` (sin denominación ni desglose) |
| Cualquier otro (tiques, despachos, liquidaciones) | `new RegistroIva(...)` |

### Tributos de WSFE en el libro de ventas

| Tributo | Campo |
|---|---|
| 6 Percepción de IVA, 13 Percepción de IVA a no categorizado | Percepción a no categorizados |
| 1 Impuestos nacionales | Percepciones de impuestos nacionales |
| 5 IIBB, 7 Percepción de IIBB | Percepciones de Ingresos Brutos |
| 3 Impuestos municipales, 8 Percepciones municipales | Percepciones municipales |
| 4 Impuestos internos | Impuestos internos |
| 2, 9, 99 | Otros tributos |

El registro que devuelve la fábrica se puede corregir antes de agregarlo al libro.

## Lectura de "Mis Comprobantes"

ARCA no publica el formato del CSV y lo cambió varias veces. El lector:

- busca la fila de encabezados y saltea los títulos;
- reconoce las columnas por nombre, sin importar mayúsculas, tildes ni puntuación;
- detecta el separador (`;` o `,`), el formato de número (`1.234,56` o `1234.56`), el BOM y
  la codificación (UTF-8 o Windows-1252);
- usa las columnas del emisor en recibidos y las del receptor en emitidos;
- toma las columnas por alícuota ("Neto Grav. IVA 21%", "IVA 21%") cuando existen. Si solo
  hay neto e IVA totales, deduce la alícuota; si no encaja con ninguna, deja el registro sin
  alícuotas y lo avisa en `getWarnings()`;
- convierte las notas de crédito a positivo.

Si una columna no se reconoce, agregue el alias: `$parser->setAlias('total', 'Importe Total $')`.

## Validaciones

| Código | Qué controla |
|---|---|
| `LIBRO_TIPO_INVALIDO`, `LIBRO_TIPO_DESCONOCIDO` | Tipo de comprobante (1 a 999; aviso si no está en el catálogo) |
| `LIBRO_DESPACHO_EN_VENTAS`, `LIBRO_DESPACHO_INVALIDO` | Despacho de importación solo en compras, con 16 caracteres |
| `LIBRO_PV_INVALIDO`, `LIBRO_NUMERO_INVALIDO`, `LIBRO_NUMERO_HASTA`, `LIBRO_NUMERO_HASTA_COMPRAS` | Punto de venta, número y rango |
| `LIBRO_FECHA_ANTERIOR`, `LIBRO_FECHA_POSTERIOR` | Ventas dentro del período; nada posterior al período |
| `LIBRO_DOC_TIPO`, `LIBRO_DOC_LARGO`, `LIBRO_CUIT_INVALIDO` | Documento de la contraparte |
| `LIBRO_COMPRADOR_CUIT`, `LIBRO_COMPRADOR_SIN_NRO`, `LIBRO_COMPRADOR_SIN_NOMBRE` | Comprador: CUIT en A y M; consumidor final con 99 y 0 |
| `LIBRO_VENDEDOR_SIN_DOC`, `LIBRO_VENDEDOR_SIN_NOMBRE`, `LIBRO_VENDEDOR_CUIT` | Vendedor identificado; CUIT en A y M |
| `LIBRO_TOTAL_NO_CUADRA`, `LIBRO_CONCEPTOS_EXCEDEN`, `LIBRO_TOTAL_NEGATIVO`, `LIBRO_IMPORTE_EXCEDE` | Total igual a la suma de conceptos, en positivo y dentro del campo |
| `LIBRO_SIN_ALICUOTAS`, `LIBRO_ALICUOTAS_NO_CORRESPONDEN`, `LIBRO_ALICUOTAS_MAX`, `LIBRO_ALICUOTA_INVALIDA`, `LIBRO_ALICUOTA_REPETIDA`, `LIBRO_ALICUOTA_IMPORTE` | Alícuotas que corresponden a la clase, sin repetir y con el IVA bien calculado |
| `LIBRO_MONEDA`, `LIBRO_MONEDA_DESCONOCIDA`, `LIBRO_COTIZACION_PESOS`, `LIBRO_COTIZACION` | Moneda y tipo de cambio |
| `LIBRO_CODIGO_OPERACION` | Código de operación válido para ventas o compras |
| `LIBRO_CREDITO_NEGATIVO`, `LIBRO_CREDITO_EXCEDE` | Crédito fiscal computable entre 0 y el IVA |
| `LIBRO_CORREDOR_CUIT` | Emisor o corredor en liquidaciones (33, 58, 59, 60, 63) |
| `LIBRO_PERCEPCION_NO_CATEG_C` | Sin percepción a no categorizados en comprobantes C |
| `LIBRO_DUPLICADO` | Comprobante repetido (en compras, por vendedor) |

Si hay errores, los métodos que generan archivos lanzan `ValidationException` y no
generan nada.
