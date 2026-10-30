# afip-php — SDK de Facturación Electrónica para Argentina (ARCA, ex-AFIP)

SDK en PHP para emitir comprobantes electrónicos en Argentina e integrarlos al ERP. El diseño
sigue el estilo de [Greenter](https://greenter.dev) (Perú): modelos simples, una fachada `See`
que valida y envía, y una representación impresa lista para entregar al cliente.

**Todo comprobante se valida localmente antes de enviarse.** Si algo está mal (totales que no
cierran, IVA mal calculado, CUIT inválido, condición frente al IVA incompatible, fechas fuera
de rango, datos de FCE incompletos…), el SDK lanza `ValidationException` con el detalle y **no
contacta a ARCA**.

## Qué incluye

| Servicio | Qué hace |
|---|---|
| **WSAA** | Login con certificado: genera el TRA, lo firma en CMS y obtiene el Ticket de Acceso. Lo guarda en caché las 12 h de vigencia y lo renueva solo. |
| **WSFEv1** | Facturas, notas de débito y crédito, recibos A/B/C/M, FCE MiPyME (201–213) y bienes usados. Pedido de CAE, CAEA (solicitar, consultar, informar, sin movimiento), consulta de comprobantes y todas las tablas `FEParamGet*`. |
| **WSFEXv1** | Factura de exportación E (19, 20, 21): permisos de embarque, incoterms, ítems, cotización y parámetros. |
| **WSMTXCA** | Factura con detalle de ítems informado a ARCA (clases A, B y M, incluida FCE): código de barras, unidad de medida, IVA e importe por ítem. CAE, CAEA y parámetros. |
| **WSBFEv1** | Bono Fiscal Electrónico (bienes de capital): ítems con código NCM, zona y percepciones. |
| **WSCT** | Comprobantes de turismo clase T (195, 196, 197) para no residentes: reintegro de IVA, formas de pago, pasaporte. |
| **WSFECRED** | FCE MiPyME: si el cliente está obligado a recibir FCE y desde qué monto; como receptor, consultar, aceptar o rechazar FCE, rechazar notas e informar la cancelación. |
| **WSCDC** | Constatación de comprobantes: verifica ante ARCA las facturas de proveedores (CAE, CAEA o CAI). |
| **Padrón A5** | Constancia de inscripción: razón social, domicilio fiscal, impuestos, actividades y condición frente al IVA deducida. Devuelve el receptor listo para facturar. |
| **Padrón A13** | Datos de identificación y CUIT/CUIL a partir de un DNI. |
| **Libro IVA Digital** | Archivos TXT de importación de ventas, compras y despachos de importación (RG 4597), validados antes de generarlos. |
| **Compras** | Lectura del CSV de "Mis Comprobantes" (emitidos y recibidos) y constatación de cada compra o de un QR con WSCDC. |
| **QR** | Código QR de la RG 4291 (con CAE o CAEA). |
| **Representación impresa** | HTML y PDF con el formato de ARCA: letra y código, CAE, QR y las leyendas de la Ley 27.743 y de la RG 5700. |
| **Calculadora** | Calcula neto, IVA por alícuota, exento, no gravado, tributos y total a partir de los ítems, con el redondeo que exige ARCA. |

Normativa contemplada: RG 4291 (WSFEv1 y QR), RG 5616 (condición frente al IVA del receptor
y `CanMisMonExt`), RG 5700/2025 (tope de $10.000.000 para identificar al consumidor final),
RG 4367 (FCE MiPyME), RG 4540 (período asociado), RG 4109 (compradores múltiples),
RG 3668, RG 4004, RG 3411 (opcionales) y Ley 27.743 (Régimen de Transparencia Fiscal al Consumidor).

## Requisitos

- PHP 8.1 o superior con las extensiones `openssl`, `curl`, `dom`, `libxml` y `json`. No hace falta `ext-soap`.
- Opcionales:
  - `dompdf/dompdf`, para generar el PDF.
  - `chillerlan/php-qrcode`, para la imagen del QR.

```bash
composer require royaltyfusion/afip-php:^0.1
```

```bash
composer require dompdf/dompdf chillerlan/php-qrcode
```

## Certificados

### Homologación (pruebas)

1. Generá la clave y el pedido de certificado (CSR):

   ```bash
   openssl genrsa -out homo.key 2048
   ```

   ```bash
   openssl req -new -key homo.key -subj "/C=AR/O=Mi Empresa/CN=erp/serialNumber=CUIT 20123456789" -out homo.csr
   ```

2. Entrá a ARCA con clave fiscal y abrí **WSASS – Autogestión Certificados Homologación**. Ahí se crea el certificado con el CSR.
3. En el mismo WSASS, autorizá el certificado para cada servicio que uses: `wsfe`, `wsfex` y `ws_sr_constancia_inscripcion`.

### Producción

1. Subí el CSR en **Administración de Certificados Digitales** y descargá el `.crt`.
2. En **Administrador de Relaciones de Clave Fiscal**, asociá el certificado a cada servicio:
   - *Facturación Electrónica* para `wsfe`.
   - *Factura Electrónica de Exportación* para `wsfex`.
   - *Consulta de Constancia de Inscripción* para el padrón.
3. En **Administración de puntos de venta y domicilios**, creá un punto de venta del tipo *RECE / Factura Electrónica – Web Services*.

> Guardá la clave privada fuera del repositorio y del *document root*. Para un `.p12` o `.pfx`, usá `Certificate::fromPkcs12File()`.

## Inicio rápido

```php
use RoyaltyFusion\Afip\See;
use RoyaltyFusion\Afip\Environment;
use RoyaltyFusion\Afip\Auth\Certificate;
use RoyaltyFusion\Afip\Calculator\InvoiceCalculator;
use RoyaltyFusion\Afip\Catalog\{AlicuotaIva, Concepto, CondicionIva, TipoComprobante, TipoDocumento};
use RoyaltyFusion\Afip\Model\{Client, Company};
use RoyaltyFusion\Afip\Model\Sale\{Invoice, SaleDetail};
use RoyaltyFusion\Afip\Exception\ValidationException;

$see = new See(Environment::HOMOLOGACION);              // o Environment::PRODUCCION
$see->setCertificate(Certificate::fromFiles('cert.crt', 'private.key'));
$see->setCuit('20123456789');                           // opcional: se toma del certificado
$see->setCacheDirectory('/var/lib/erp/afip');           // tickets de acceso + bloqueos de numeración

$invoice = (new Invoice())
    ->setTipoComprobante(TipoComprobante::FACTURA_A)
    ->setPuntoVenta(1)
    ->setConcepto(Concepto::PRODUCTOS)
    ->setCompany((new Company())->setCuit('20123456789')->setRazonSocial('MI EMPRESA S.A.')
        ->setCondicionIva(CondicionIva::RESPONSABLE_INSCRIPTO))
    ->setClient((new Client())->setTipoDoc(TipoDocumento::CUIT)->setNumDoc('30500010912')
        ->setRazonSocial('CLIENTE S.A.')->setCondicionIva(CondicionIva::RESPONSABLE_INSCRIPTO))
    ->addDetail((new SaleDetail())->setDescripcion('Notebook')->setCantidad(2)
        ->setPrecioUnitario(850000)->setAlicuotaIva(AlicuotaIva::IVA_21));

(new InvoiceCalculator())->calculate($invoice);   // ImpNeto, ImpIVA, alícuotas, ImpTotal

try {
    $result = $see->send($invoice);   // valida → numera (último + 1) → FECAESolicitar
    if ($result->isSuccess()) {
        echo $invoice->getNumeroCompleto(), ' CAE ', $result->getCae(),
             ' vence ', $result->getCaeVencimiento()->format('d/m/Y');
    } else {
        echo 'Rechazado: ', $result->getErrorMessage();   // errores + observaciones de ARCA
    }
} catch (ValidationException $e) {
    foreach ($e->getResult()->getErrors() as $error) {
        echo $error->getCode(), ' - ', $error->getField(), ': ', $error->getMessage(), PHP_EOL;
    }
}
```

La carpeta [`examples/`](examples) tiene un script para cada caso:

| Script | Caso |
|---|---|
| `01` | Factura A |
| `02` | Factura B a consumidor final |
| `03` | Factura C de servicios |
| `04` | Nota de crédito |
| `05` | FCE MiPyME |
| `06` | Exportación |
| `07` | Padrón |
| `08` | CAEA |
| `09` | PDF |
| `10` | Parámetros |
| `11` | Factura con detalle de ítems (WSMTXCA) |
| `12` | Bono fiscal (WSBFE) |
| `13` | Factura T de turismo (WSCT) |
| `14` | FCE: obligación del cliente y FCE recibidas (WSFECRED) |
| `15` | Constatación de facturas de proveedores (WSCDC) |

## Modelos

| Clase | Uso |
|---|---|
| `Model\Company` | Emisor: CUIT, razón social, condición frente al IVA, IIBB, inicio de actividades y domicilio. |
| `Model\Client` | Receptor: tipo y número de documento, razón social, **condición frente al IVA (RG 5616)** y domicilio. `Client::consumidorFinal()` crea el receptor 99/0/CF. |
| `Model\Sale\Invoice` | Comprobante de WSFEv1. |
| `Model\Sale\Note` | Nota de crédito o débito. `setAfectado($factura)` carga el comprobante asociado. |
| `Model\Sale\SaleDetail` | Ítem: precio con o sin IVA, alícuota, exento o no gravado, bonificación en % o en importe. |
| `Model\Sale\Iva`, `Tributo`, `Opcional`, `CbteAsociado`, `Periodo`, `Comprador` | Los nodos de WSFEv1. |
| `Model\Export\ExportInvoice`, `ExportItem`, `Permiso`, `ExportCbteAsociado` | Comprobante de WSFEXv1. |
| `Model\Sale\BonoFiscalInvoice` | Bono fiscal (WSBFE): un `Invoice` con zona e Id. |
| `Model\Sale\TurismoInvoice`, `FormaPago` | Comprobante de turismo (WSCT): país y domicilio del receptor, relación emisor-receptor, reintegro y formas de pago. |
| `Model\Sale\DatoAdicional` | Datos adicionales de WSMTXCA y WSCT (t, c1..c6). |

### Qué método usar

| Comprobante | Servicio | Método |
|---|---|---|
| Facturas, notas y recibos A/B/C/M, FCE (sin detalle de ítems) | WSFEv1 | `$see->send($invoice)` / `sendBatch([...])` |
| Facturas y notas A/B/M con detalle de ítems | WSMTXCA | `$see->sendMtxca($invoice)` |
| Exportación E | WSFEXv1 | `$see->sendExport($exportInvoice)` |
| Bono fiscal | WSBFE | `$see->sendBfe($bono)` |
| Turismo T | WSCT | `$see->sendTurismo($turismo)` |
| Comprobantes emitidos con CAEA | WSFEv1 / WSMTXCA | `$see->informCaea(...)` / `informCaeaMtxca(...)` |

En WSMTXCA, WSBFE y WSCT, ARCA recibe cada ítem con su IVA. Calculá con
`(new InvoiceCalculator())->calculate($invoice, true)`: esa opción calcula el IVA y el importe de cada
ítem y arma los subtotales por alícuota como suma de los ítems, tal como los valida ARCA.
Para una bonificación general usá `SaleDetail::descuento('Bonificación', 100, AlicuotaIva::IVA_21)`.
WSMTXCA la envía como unidad 99 y WSCT como ítem tipo 99, ambas con importes negativos.

Si un comprobante de WSMTXCA o WSCT se reenvía con el mismo número después de un error de
comunicación, ARCA lo rechaza por número repetido. En ese caso el SDK consulta el comprobante: si
ya estaba autorizado con los mismos datos, devuelve ese CAE (`isRecovered()`) en lugar del rechazo.

Los catálogos están en `Catalog\*`: `TipoComprobante`, `TipoDocumento`, `AlicuotaIva`, `Concepto`,
`CondicionIva`, `TipoTributo`, `TipoOpcional`, `Moneda` y `Exportacion`. Cada uno trae
descripción y reglas, por ejemplo `TipoComprobante::clase()`, `isFce()` o `CondicionIva::allowedClasses()`.

## Validaciones

`$see->validate($invoice)` devuelve un `ValidationResult` sin enviar nada. `$see->send()`
ejecuta la misma validación y además hace estos controles en línea:

- El punto de venta está habilitado y no fue dado de baja (`FEParamGetPtosVenta`).
- La fecha no es anterior a la del último comprobante autorizado.

Y estos controles opcionales, que consultan otros servicios de ARCA:

| Opción | Qué controla |
|---|---|
| `setFceCheck(true)` | Emisor MiPyME: exige FCE cuando el cliente está obligado y el importe alcanza el mínimo, y rechaza la FCE a clientes no obligados (WSFECRED). |
| `setQuotationTolerance(5.0)` | La cotización no se desvía más de 5 % de la oficial de ARCA. |
| `setPadronCheck(true)` | El cliente está activo en ARCA y su condición frente al IVA coincide con el padrón (por grupos: el padrón no distingue, por ejemplo, monotributo social de monotributo). |
| `setCheckAssociatedVouchers(true)` | En notas de crédito y débito, el comprobante asociado propio existe en el mismo web service. No sirve para comprobantes emitidos por otros medios, como Comprobantes en línea o un controlador fiscal. |

El listado completo de reglas, con su código y el error equivalente de ARCA, está en
[docs/VALIDACIONES.md](docs/VALIDACIONES.md). En resumen, el SDK controla:

- **Estructura:** tipo de comprobante, punto de venta, numeración y rangos (solo B/C a consumidor final), concepto.
- **Fechas:** ±5 días para productos y ±10 para servicios. En servicios exige período y vencimiento de pago y que sean coherentes.
- **Receptor:**
  - Tipo de documento y dígito verificador de CUIT/CUIL/CDI.
  - Las clases A y M exigen CUIT.
  - La condición frente al IVA es obligatoria, debe ser válida y compatible con la clase (RG 5616).
  - Desde $10.000.000 hay que identificar al consumidor final (RG 5700).
- **Importes:**
  - `ImpTotal = ImpTotConc + ImpNeto + ImpOpEx + ImpTrib + ImpIVA`.
  - Para cada alícuota, `Importe = BaseImp × %`. Las bases suman el neto gravado y los importes suman el IVA.
  - La clase C va sin IVA, no gravado ni exento.
  - Máximo 2 decimales y sin negativos.
  - Los tributos suman `ImpTrib`.
- **Moneda:** PES con cotización 1. Moneda extranjera con cotización > 0 y `CanMisMonExt`.
- **Notas:** comprobante o período asociado obligatorio, de la misma clase. No se pueden informar ambos.
- **FCE MiPyME:**
  - El receptor debe tener CUIT.
  - La factura exige vencimiento de pago, CBU (2101) válido y transferencia SCA/ADC (27).
  - Las notas exigen anulación (22), un comprobante asociado con CUIT y fecha, y no llevan vencimiento.
- **Emisor:** un monotributista o un exento no puede emitir A/B/M.
- **Ítems:** si se cargaron, se avisa cuando los importes del comprobante no coinciden con ellos. Es una advertencia, o un error con `itemsEstrictos`.
- **WSMTXCA, WSBFE y WSCT:** ítems obligatorios, IVA de cada ítem según su alícuota, subtotales iguales a la suma de los ítems y campos propios de cada servicio:
  - WSBFE: código NCM y tributos admitidos.
  - WSCT: relación emisor-receptor, reintegro, tarjeta y SWIFT.
  - WSMTXCA: la FCE usa datos adicionales en lugar de opcionales: 21 (CBU) y 27 (SCA/ADC) en facturas, 22 (anulación S/N) en notas.

Los parámetros normativos se configuran con `ValidatorOptions`. Así, si ARCA cambia un monto o
un plazo, no hace falta actualizar el SDK:

```php
use RoyaltyFusion\Afip\Validator\ValidatorOptions;

$see->setValidatorOptions(new ValidatorOptions(
    topeConsumidorFinal: 10_000_000.00,
    tolerancia: 0.01,
    requiereCondicionIvaReceptor: true,
    catalogosEstrictos: false,
    itemsEstrictos: false,
    totalCeroEsError: false,
));
```

## Integración con el ERP

- **Multi-empresa:** `See::fromConfig([...])` arma una instancia por emisor (certificado PEM, `.p12` o archivos, CUIT, ambiente, storage, bloqueo, logger, reloj) sin estado global. Ver [docs/SYMFONY.md](docs/SYMFONY.md).
- **Numeración:** si el comprobante no tiene número, `send()` consulta el último autorizado y usa el siguiente. La numeración se bloquea entre procesos por ambiente, CUIT, punto de venta y tipo:
  - `FileLock`: por defecto, o con `setCacheDirectory()`.
  - `PdoNumberingLock`: `GET_LOCK` de MySQL/MariaDB o advisory lock de PostgreSQL, para varios servidores. Se configura con `setNumberingLock()`. Si ARCA responde 10016 porque otro proceso tomó el número, el SDK renumera y reintenta (`setNumberingRetries()`).
- **Caídas y timeouts:** ante un error de comunicación, el SDK consulta el comprobante con `FECompConsultar`, con reintentos (`setRecoveryPolicy()`).
  - Si ARCA lo autorizó con los mismos datos, devuelve el CAE (`$result->isRecovered()`).
  - Si no lo puede confirmar, lanza `UncertainAuthorizationException`. El comprobante **conserva su número**. `toArray()` (o `json_encode($e)`) devuelve servicio, ambiente, CUIT, tipo, punto de venta, números y el hash SHA-256 de los datos enviados, para guardarlos en la BD.
  - Desde cualquier proceso (un cron), `$see->reconcile($invoice)` consulta ARCA por tipo, punto de venta y número:
    - devuelve el CAE si coincide;
    - devuelve `null` si no existe, y entonces se reenvía con el mismo número;
    - lanza `VoucherMismatchException` si el número está autorizado con otros datos.
  - Nunca generes un comprobante nuevo con otro número para la misma venta.
  - Si el número figura autorizado con otros datos, lo informa.
- **Rechazos:** si ARCA rechaza un comprobante numerado automáticamente, el número se libera (`getNumero()` vuelve a `null`) para corregirlo y reenviarlo. En un lote parcialmente aprobado, los aprobados conservan su número y su CAE, y el lote no se reenvía.
- **Auditoría:** `$result->getRequestXml()` y `getResponseXml()` devuelven los XML enviados y recibidos para guardarlos junto al comprobante. `$see->setLogger($logger)` acepta un logger PSR-3 o un callable y registra todas las llamadas. Antes de registrar oculta token, sign, el CMS del login y cualquier clave privada.
- **Tickets de acceso:** en producción guardalos en la base con `PdoTicketStorage` (MySQL/MariaDB, PostgreSQL o SQLite, solo `ext-pdo`), así sobreviven a los despliegues y se comparten entre servidores. También están `CacheTicketStorage` (Redis o cualquier caché PSR-16) y `FileTicketStorage`. Por defecto se usa el directorio temporal del sistema. Si el ticket se pierde, ARCA rechaza el login con `coe.alreadyAuthenticated` hasta que vence (12 h).
- **Cotización:** `$see->getCotizacion('DOL', $fecha)` devuelve la cotización oficial redondeada a 6 decimales, lista para `setMoneda('DOL', $cotizacion)`.
- **Reloj:** `$see->setClock($clock)` acepta un reloj PSR-20 o un callable, para validar con la fecha civil que quieras en los tests del ERP.
- **Contingencia:** con CAEA se pide el código por quincena (`requestCaea`), se emite offline y después se informa con `$see->informCaea($invoices)`.
- **Clientes:** `$see->getPadronA5()->getPersona($cuit)?->toClient()` arma el receptor con la razón social, el domicilio y la condición frente al IVA.
- **Endpoints:** `$see->setEndpoint(Endpoints::WSFE, $url)` cambia la URL de un servicio, por ejemplo para un simulador o si ARCA migra de dominio.

## Libro IVA Digital y compras

ARCA no tiene folios: cada comprobante se numera en orden y se autoriza con CAE. Tampoco
tiene un web service para descargar los comprobantes recibidos; el camino oficial es
exportar el CSV de "Mis Comprobantes" e importarlo:

```php
use RoyaltyFusion\Afip\Libro\{LibroIvaDigital, MisComprobantesParser};

$parser = new MisComprobantesParser();
$compras = $parser->parseFile('mis_comprobantes_recibidos.csv');   // RegistroIva[]
$parser->getWarnings();                                              // filas omitidas, alícuotas a revisar

foreach ($compras as $compra) {
    $see->verifyReceived($compra)->isSuccess();   // constata con WSCDC (CAE, CAEA o CAI)
}
$see->verifyQr($urlDelQr);                        // o directamente desde el QR impreso

$libro = (new LibroIvaDigital($see->getCuit()))
    ->setPeriodo(2026, 10)
    ->addVentas($facturasDelMes)      // Invoice, ExportInvoice o RegistroIva
    ->addCompras($compras);

$libro->validate();                   // ValidationResult: totales, alícuotas, documentos, duplicados, período
$libro->writeTo('/tmp/libro-iva');    // TXT listos para "Importar" en el Libro de IVA Digital
```

Detalle de los archivos, el mapeo de tributos y las validaciones en [docs/LIBRO_IVA.md](docs/LIBRO_IVA.md).

## Representación impresa

Para dibujar el comprobante con el motor del ERP (TCPDF, etc.), sin dompdf ni librerías de QR:

```php
$data = $see->getRepresentationData($invoice);
// $data['letra']         'A'
// $data['codigo']        '01'
// $data['puntoVenta']    '00001'
// $data['numero']        '00000042'
// $data['cae']           '76123456789012'      $data['caeVencimiento']  '2026-11-08'
// $data['qrUrl']         URL para generar el QR (RG 4291)
// $data['emisor'], $data['receptor'], $data['importes'] (neto, ivas[], tributos, total)
// $data['leyendas']      RG 5003 (A a monotributista), "A CONSUMIDOR FINAL" (RG 5700) y las del comprobante
// $data['transparenciaFiscal']  ['ivaContenido' => …, 'otrosImpuestosNacionalesIndirectos' => …] en clase B (Ley 27.743)
```

También hay un HTML y un PDF propios, opcionales:

```php
use RoyaltyFusion\Afip\Report\{HtmlReport, PdfReport};

$html = (new HtmlReport())->render($invoice, ['logo' => 'logo.png', 'condicionVenta' => 'Contado']);
$pdf  = (new PdfReport())->render($invoice, ['copia' => 'ORIGINAL']);   // requiere dompdf
$qr   = $see->getQrUrl($invoice);
```

El comprobante impreso trae:

- Letra y código de comprobante.
- Datos del emisor y del receptor.
- Ítems. En la clase A el IVA va discriminado; en B y C los precios son finales.
- Tributos y comprobantes asociados.
- Totales por alícuota.
- CAE con su vencimiento y el QR.
- En comprobantes B, el bloque del **Régimen de Transparencia Fiscal al Consumidor (Ley 27.743)** y la leyenda "A CONSUMIDOR FINAL".

Para usar un diseño propio, pasá `['template' => '/ruta/plantilla.php']`.

## Symfony

El SDK no depende de ningún framework y funciona con Symfony 7.4, Laravel o PHP plano.
La integración multi-empresa con Symfony (fábrica por establecimiento, tickets y bloqueos en la
base de datos, cron de reconciliación) está en [docs/SYMFONY.md](docs/SYMFONY.md).

## Errores

Todas las excepciones extienden `RoyaltyFusion\Afip\Exception\AfipException`.

| Excepción | Cuándo |
|---|---|
| `ValidationException` | El comprobante no pasó la validación local y no se envió. |
| `UncertainAuthorizationException` | Falló la comunicación al autorizar y no se pudo confirmar el resultado. Hay que reconciliar o reenviar el mismo comprobante con el mismo número. Extiende `TransportException`. |
| `VoucherMismatchException` | `reconcile()` encontró el número autorizado con otros datos. |
| `TransportException` | Error de red, TLS o HTTP antes de enviar el comprobante. |
| `WsaaException` | El login falló: certificado sin relación con el servicio, reloj desfasado, `coe.alreadyAuthenticated`… |
| `CertificateException` | El certificado o la clave son inválidos, están vencidos o no se corresponden. |
| `ServiceException` | ARCA devolvió `Errors` en una consulta o parámetro. Expone `getErrors()`. |
| `SoapFaultException` | ARCA devolvió un SOAP Fault. |
| `PadronException` | Error del padrón. Si la persona no existe, se devuelve `null`. |

Un rechazo de ARCA en `FECAESolicitar` **no** es una excepción: `isSuccess()` devuelve `false` y
`getErrors()`, `getObservaciones()` y `hasCode()` dan el detalle.

### Contrato de errores para el ERP

| Situación | Cómo llega | ¿Se envió a ARCA? | Número | Acción del ERP |
|---|---|---|---|---|
| Datos inválidos o incompletos | `ValidationException` (`getResult()->getErrors()`: código, campo, mensaje) | No | No se asigna | Mostrar los errores al usuario, corregir y reenviar. |
| Rechazo de ARCA | `AuthorizationResult` con `isSuccess() === false` (`getErrors()`, `getObservaciones()`, `hasCode()`) | Sí, rechazado | Se libera si era automático | Mostrar el motivo, corregir y reenviar. |
| Aprobado | `isSuccess() === true` | Sí | Asignado | Guardar número, CAE, vencimiento y los XML. |
| Aprobado tras una caída | `isSuccess()` e `isRecovered()` | Sí | Asignado | Igual que aprobado. |
| Estado incierto (contingencia) | `UncertainAuthorizationException` | Desconocido | Se conserva | Guardar `toArray()`, marcar "pendiente de confirmar" y reconciliar con `reconcile()` desde un cron. No emitir otro número. |
| Número usado con otros datos | `VoucherMismatchException` / `AfipException` | Sí (otro comprobante) | Ocupado | Intervención manual: revisar duplicados. |
| Error de red antes de enviar | `TransportException` | No | Se libera | Reintentar más tarde. |
| Login / certificado | `WsaaException`, `CertificateException` | No | — | Revisar el certificado, su vencimiento y la relación con el servicio. Si dice `coe.alreadyAuthenticated`, el ticket no se persistió: usar `PdoTicketStorage` y esperar al vencimiento. |
| Consulta o parámetro | `ServiceException`, `SoapFaultException`, `PadronException` | — | — | Registrar y reintentar. |

## Tests

```bash
composer install
```

```bash
vendor/bin/phpunit
```

Los tests usan un transporte simulado que responde como ARCA, así que no requieren conexión ni certificados.

`tests/Unit/WsdlSchemaTest.php` genera los requests de todos los servicios (más de 130) y los
valida contra el esquema XSD de los WSDL oficiales de ARCA (`tests/Fixtures/wsdl`). Así se
comprueba el orden de los elementos, los campos obligatorios, los tipos y los largos.

### Pruebas reales en homologación

`tests/Integration` se conecta a ARCA homologación. Solo corre si definís las variables de entorno; sin ellas los tests se omiten, y la CI no los ejecuta.

```bash
AFIP_HOMO_CERT=cert.crt AFIP_HOMO_KEY=clave.key AFIP_HOMO_CUIT=20123456789 AFIP_HOMO_PTOVTA=1 vendor/bin/phpunit --testsuite homologacion
```

Las pruebas cubren:

- FEDummy y el último comprobante autorizado.
- Una Factura A, B y C, cada una con su nota de crédito asociada, la consulta del comprobante y `reconcile()`.
- El padrón A5.
- La recuperación tras un timeout simulado después de enviar.

Antes de pasar a producción, emití en homologación al menos un comprobante de cada tipo que
vaya a usar el ERP.

## Notas

- Los web services siguen en los dominios `afip.gov.ar`. Si ARCA publica URLs nuevas, se cambian con `setEndpoint()` o `Endpoints::override()`.
- El SDK no descarga el WSDL en cada request: arma los sobres SOAP siguiendo el orden exacto de los WSDL oficiales y los tests los validan contra esos esquemas.
- Cada servicio debe estar asociado al certificado: en WSASS para homologación y en el Administrador de Relaciones para producción. Los nombres son `wsfe`, `wsfex`, `wsmtxca`, `wsbfe`, `wsct`, `wsfecred`, `wscdc`, `ws_sr_constancia_inscripcion` y `ws_sr_padron_a13`.
- **Fuera del alcance:** las liquidaciones y remitos de los sectores agropecuario y cárnico (WSLPG, WSLSP, WSLTV, WSLUM, carta de porte WSCPE, remitos electrónicos) y el cálculo de percepciones provinciales según los padrones de ARBA o AGIP. El SDK recibe el importe de la percepción como tributo y valida que cierre.
- Los servidores de ARCA usan claves DH cortas. `CurlTransport` aplica `DEFAULT@SECLEVEL=1` para que OpenSSL 3 pueda conectarse, sin desactivar la verificación del certificado.
