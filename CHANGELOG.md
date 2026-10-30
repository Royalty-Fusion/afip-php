# Changelog

Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/); el proyecto
usa [versionado semántico](https://semver.org/lang/es/).

## [0.1.1] - 2026-10-30

### Agregado
- Libro IVA Digital (RG 4597): `LibroIvaDigital` genera los TXT de importación de ventas,
  compras y despachos de importación (comprobantes y alícuotas), con más de 30
  validaciones antes de generar. `RegistroFactory` arma los registros desde `Invoice`,
  `ExportInvoice` o el QR de un comprobante recibido.
- Compras: `MisComprobantesParser` lee el CSV de "Mis Comprobantes" (emitidos y recibidos)
  y reconoce las columnas por nombre, con alícuotas por columna o deducidas.
- `See::verifyReceived()` y `See::verifyQr()` constatan con WSCDC una compra o un QR.

## [0.1.0] - 2026-10-29

Primera versión publicada.

### Servicios de ARCA
- WSAA: login con certificado (PEM, archivos o PKCS#12), firma CMS y Ticket de Acceso con caché.
- WSFEv1: CAE (individual y lotes), CAEA (solicitar, consultar, informar, sin movimiento),
  consultas y todas las tablas `FEParamGet*`, incluida la RG 5616 (`CondicionIVAReceptorId`,
  `CanMisMonExt`, `FchCotiz`, `Actividades`).
- WSFEXv1: exportación (19, 20, 21).
- WSMTXCA: factura con detalle de ítems (A, B, M y FCE), CAEA, descuentos y actividades.
- WSBFEv1: bono fiscal electrónico.
- WSCT: comprobantes de turismo clase T.
- WSFECRED: obligación de recibir FCE, aceptación, rechazo y cancelación de FCE MiPyME.
- WSCDC: constatación de comprobantes de proveedores.
- Padrón A5 (constancia de inscripción) y A13.

### Validación y emisión
- Más de 180 reglas locales antes de enviar: RG 4291, 5616, 5700, 4367, 4540, 4109, 3668,
  4004 y Ley 27.743.
- Controles en línea opcionales: obligación FCE, cotización, comprobante asociado y padrón.
- Numeración automática con bloqueo (archivos o base de datos), reintento ante número
  ocupado, recuperación tras errores de comunicación y estado incierto serializable.
- `See::reconcile()` para retomar desde otro proceso.

### Integración con el ERP
- `See::fromConfig()` / `SeeConfig` para multi-empresa sin estado global.
- `PdoTicketStorage` y `PdoNumberingLock` (MySQL/MariaDB, PostgreSQL, SQLite).
- Logger PSR-3 o callable con ocultamiento de credenciales; reloj inyectable (PSR-20).
- `getCotizacion()`, `getRepresentationData()` (para TCPDF), QR RG 4291, HTML/PDF opcionales.

### Calidad
- 144 tests unitarios; validación de los requests de los 10 servicios contra los WSDL
  oficiales; pruebas opcionales contra homologación (`--testsuite homologacion`).
- CI con PHP 8.1 a 8.5 y phpstan nivel 6.

[0.1.0]: https://github.com/Royalty-Fusion/afip-php/releases/tag/v0.1.0
