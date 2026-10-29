# WSDL oficiales de ARCA (homologación)

Se usan en `tests/Unit/WsdlSchemaTest.php` para validar, contra el esquema XSD de cada
servicio, todos los requests que genera el SDK.

| Archivo | Servicio | Versión |
|---|---|---|
| `wsfev1.wsdl` | WSFEv1 | vigente (incluye RG 5616: `CanMisMonExt`, `CondicionIVAReceptorId`, `FchCotiz`, `Actividades`) |
| `wsfexv1.wsdl` | WSFEXv1 | vigente (incluye `CanMisMonExt`, `Fecha_pago`, `Actividades`) |
| `wsct.wsdl` | WSCT | vigente (incluye `cancelaEnMismaMonedaExtranjera`) |
| `wsfecred.wsdl` | WSFECRED | vigente |
| `wsaa.wsdl`, `padron_a5.wsdl`, `padron_a13.wsdl` | WSAA y padrón | vigentes |
| `wscdc.wsdl` | WSCDC | homologación |
| `wsmtxca.wsdl`, `wsbfev1.wsdl` | WSMTXCA y WSBFEv1 | anteriores a la RG 5616: el test agrega los dos campos nuevos en la posición que indica el manual vigente |

Para actualizarlos, descargar `?wsdl` de cada servicio de homologación (ver `src/Endpoints.php`).
