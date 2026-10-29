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
| **Padrón A5** | Constancia de inscripción: razón social, domicilio fiscal, impuestos, actividades y condición frente al IVA deducida. Devuelve el receptor listo para facturar. |
| **Padrón A13** | Datos de identificación y CUIT/CUIL a partir de un DNI. |
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
composer require royalty-fusion/afip-php
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

Los catálogos están en `Catalog\*`: `TipoComprobante`, `TipoDocumento`, `AlicuotaIva`, `Concepto`,
`CondicionIva`, `TipoTributo`, `TipoOpcional`, `Moneda` y `Exportacion`. Cada uno trae
descripción y reglas, por ejemplo `TipoComprobante::clase()`, `isFce()` o `CondicionIva::allowedClasses()`.

## Validaciones

`$see->validate($invoice)` devuelve un `ValidationResult` sin enviar nada. `$see->send()`
ejecuta la misma validación y además hace estos controles en línea:

- El punto de venta está habilitado y no fue dado de baja (`FEParamGetPtosVenta`).
- La fecha no es anterior a la del último comprobante autorizado.

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
- **Ítems:** si se cargaron, los importes del comprobante tienen que coincidir con ellos.

Los parámetros normativos se configuran con `ValidatorOptions`. Así, si ARCA cambia un monto o
un plazo, no hace falta actualizar el SDK:

```php
use RoyaltyFusion\Afip\Validator\ValidatorOptions;

$see->setValidatorOptions(new ValidatorOptions(
    topeConsumidorFinal: 10_000_000.00,
    tolerancia: 0.01,
    requiereCondicionIvaReceptor: true,
    catalogosEstrictos: false,
));
```

## Integración con el ERP

- **Numeración:** si el comprobante no tiene número, `send()` consulta el último autorizado y usa el siguiente. Con `setCacheDirectory()`, la numeración queda bloqueada entre procesos con `flock`. Si ARCA responde 10016 porque otro proceso tomó el número, el SDK renumera y reintenta (`setNumberingRetries()`).
- **Caídas y timeouts:** ante un error de comunicación, el SDK consulta el comprobante con `FECompConsultar`.
  - Si ARCA lo autorizó con los mismos datos, devuelve el CAE (`$result->isRecovered()`).
  - Si no existe, relanza `TransportException` y el comprobante se puede reenviar sin riesgo de duplicarlo.
  - Si el número figura autorizado con otros datos, lo informa.
- **Auditoría:** `$result->getRequestXml()` y `getResponseXml()` devuelven los XML enviados y recibidos para guardarlos junto al comprobante. Con `$see->setLogger(fn ($op, $req, $res, $err) => ...)` se registran todas las llamadas.
- **Tickets de acceso:** usá `setCacheDirectory()`, `FileTicketStorage` o `CacheTicketStorage`, que acepta Redis o cualquier caché PSR-16. Si el TA no se persiste, ARCA rechaza el login siguiente con `coe.alreadyAuthenticated`.
- **Contingencia:** con CAEA se pide el código por quincena (`requestCaea`), se emite offline y después se informa con `$see->informCaea($invoices)`.
- **Clientes:** `$see->getPadronA5()->getPersona($cuit)?->toClient()` arma el receptor con la razón social, el domicilio y la condición frente al IVA.
- **Endpoints:** `$see->setEndpoint(Endpoints::WSFE, $url)` cambia la URL de un servicio, por ejemplo para un simulador o si ARCA migra de dominio.

## Representación impresa

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

## Errores

Todas las excepciones extienden `RoyaltyFusion\Afip\Exception\AfipException`.

| Excepción | Cuándo |
|---|---|
| `ValidationException` | El comprobante no pasó la validación local y no se envió. |
| `TransportException` | Error de red, TLS o HTTP. Al autorizar, el SDK ya intentó recuperar el resultado. |
| `WsaaException` | El login falló: certificado sin relación con el servicio, reloj desfasado, `coe.alreadyAuthenticated`… |
| `CertificateException` | El certificado o la clave son inválidos, están vencidos o no se corresponden. |
| `ServiceException` | ARCA devolvió `Errors` en una consulta o parámetro. Expone `getErrors()`. |
| `SoapFaultException` | ARCA devolvió un SOAP Fault. |
| `PadronException` | Error del padrón. Si la persona no existe, se devuelve `null`. |

Un rechazo de ARCA en `FECAESolicitar` **no** es una excepción: `isSuccess()` devuelve `false` y
`getErrors()`, `getObservaciones()` y `hasCode()` dan el detalle.

## Tests

```bash
composer install
```

```bash
vendor/bin/phpunit
```

Los tests usan un transporte simulado que responde como ARCA, así que no requieren conexión ni certificados.

Antes de pasar a producción, emití en **homologación** al menos un comprobante de cada tipo que
vaya a usar el ERP: A/B/C, notas, FCE y exportación.

## Notas

- Los web services siguen en los dominios `afip.gov.ar`. Si ARCA publica URLs nuevas, se cambian con `setEndpoint()` o `Endpoints::override()`.
- El SDK no descarga el WSDL en cada request: arma los sobres SOAP siguiendo el orden exacto de los WSDL oficiales. Los mensajes se verificaron contra los esquemas de WSFEv1, WSFEXv1, WSAA y Padrón A5.
- Los servidores de ARCA usan claves DH cortas. `CurlTransport` aplica `DEFAULT@SECLEVEL=1` para que OpenSSL 3 pueda conectarse, sin desactivar la verificación del certificado.
