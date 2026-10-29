# Validaciones locales

Reglas que el SDK verifica **antes** de enviar el comprobante a ARCA (generado a partir del código fuente).

- `error`: impide el envío; `See::send()` lanza `ValidationException` sin contactar a ARCA.
- `warning`: se informa en `ValidationResult::getWarnings()` pero no bloquea.
- *ARCA*: código de error/observación equivalente de ARCA, cuando se conoce.

Los montos y plazos normativos (tope de consumidor final, días de tolerancia, etc.) se configuran con `ValidatorOptions`.

## Comprobantes: WSFEv1, WSMTXCA, WSBFE y WSCT (InvoiceValidator)

| Código | Nivel | Campo | Descripción | ARCA |
|---|---|---|---|---|
| `ACTIVIDAD_INVALIDA` | error | `actividades[i]` | El código de actividad debe ser un número positivo (FEParamGetActividades). |  |
| `ALICUOTA_DUPLICADA` | error | `[i].id` | La alícuota … está informada más de una vez; agrupe las bases. |  |
| `ALICUOTA_INVALIDA` | error | `[i].id` | La alícuota de IVA … no es válida (FEParamGetTiposIva). | 10051 |
| `BFE_CONCEPTO` | error | `concepto` | El bono fiscal electrónico solo admite bienes (concepto Productos). |  |
| `BFE_NCM` | error | `details[i].codigoNcm` | Cada ítem del bono fiscal debe tener un código NCM válido (ej. 8471.30.12, ver BFEGetPARAM_NCM). |  |
| `BFE_NO_SOPORTADO` | error | `[i]` | WSBFE no admite …. |  |
| `BFE_RANGO` | error | `numeroHasta` | WSBFE autoriza un comprobante por solicitud (sin rangos). |  |
| `BFE_TRIBUTO` | error | `tributos[i].id` | WSBFE solo admite percepciones de IVA, IIBB, municipales e impuestos internos (informado: …). |  |
| `BFE_ZONA` | error | `zona` | Indique la zona (BFEGetPARAM_Zonas). |  |
| `CAEA_FECHA_GENERACION` | error | `fechaHoraGeneracion` | Debe informar la fecha y hora de generación del comprobante emitido con CAEA. |  |
| `CAEA_INVALIDO` | error | `caea` | Debe informar el CAEA (14 dígitos) con el que se emitió el comprobante. |  |
| `CAEA_NUMERO` | error | `numero` | Los comprobantes emitidos con CAEA deben tener número asignado. |  |
| `CAN_MIS_MON_EXT_NO_CORRESPONDE` | warning | `pagoMismaMoneda` | El indicador de cancelación en misma moneda extranjera (CanMisMonExt) solo aplica a moneda extranjera; no se enviará. |  |
| `CAN_MIS_MON_EXT_NO_INFORMADO` | warning | `pagoMismaMoneda` | No se indicó si el comprobante se cancela en la misma moneda extranjera (CanMisMonExt); se enviará "N". |  |
| `CBTE_ASOC_CLASE` | error | `[i].tipo` | Una … solo puede asociar comprobantes de la misma clase… (informado: …). | 10042 |
| `CBTE_ASOC_CUIT` | error | `[i].cuit` | El CUIT del comprobante asociado "…" no es válido. |  |
| `CBTE_ASOC_FECHA` | error | `[i].fecha` | La fecha del comprobante asociado no puede ser posterior a la del comprobante. |  |
| `CBTE_ASOC_NUMERO` | error | `[i].numero` | El número del comprobante asociado no es válido. |  |
| `CBTE_ASOC_PTO_VTA` | error | `[i].puntoVenta` | El punto de venta del comprobante asociado no es válido. |  |
| `CBTE_ASOC_REQUERIDO` | error | `cbtesAsociados` | Las notas de crédito y débito deben informar el comprobante asociado (CbtesAsoc) o el período asociado (PeriodoAsoc). | 10040 |
| `CBTE_ASOC_TIPO_INVALIDO` | error | `[i].tipo` | El tipo de comprobante asociado … no es válido. |  |
| `CBTE_ASOC_Y_PERIODO` | error | `periodoAsociado` | No pueden informarse a la vez comprobantes asociados y período asociado. |  |
| `CBTE_TIPO_INVALIDO` | error | `tipoComprobante` | El tipo de comprobante … no es válido para WSFEv1…. | 10007 |
| `CLASE_A_REQUIERE_CUIT` | error | `client.tipoDoc` | Los comprobantes clase … requieren que el receptor se identifique con CUIT (DocTipo 80). | 10013 |
| `CLASE_C_EXENTO` | error | `impExento` | En comprobantes sin IVA (clase C) el importe exento (ImpOpEx) debe ser 0. | 10071 |
| `CLASE_C_NO_GRAVADO` | error | `impNoGravado` | En comprobantes sin IVA (clase C) el importe no gravado (ImpTotConc) debe ser 0. | 10071 |
| `CLASE_NO_EMITIBLE` | error | `tipoComprobante` | Un emisor "…" no puede emitir comprobantes clase …. | 10005 |
| `COMPRADORES_PORCENTAJE_TOTAL` | error | `compradores` | La suma de los porcentajes de los compradores debe ser 100 (informado: …). |  |
| `COMPRADOR_DOC_NRO` | error | `[i].numDoc` | El número de documento del comprador no es válido. |  |
| `COMPRADOR_DOC_TIPO` | error | `[i].tipoDoc` | El tipo de documento del comprador no es válido. |  |
| `COMPRADOR_PORCENTAJE` | error | `[i].porcentaje` | El porcentaje del comprador debe ser mayor a 0 y hasta 100. |  |
| `CONCEPTO_INVALIDO` | error | `concepto` | El concepto debe ser 1 (Productos), 2 (Servicios) o 3 (Productos y Servicios). | 10011 |
| `CONDICION_IVA_CLASE` | error | `client.condicionIva` | La condición "…" no puede informarse en comprobantes clase … (admitida en: …). | 10243 |
| `CONDICION_IVA_INVALIDA` | error | `client.condicionIva` | La condición frente al IVA … no es válida (FEParamGetCondicionIvaReceptor). | 10242 |
| `CONDICION_IVA_REQUERIDA` | error | `client.condicionIva` | La condición frente al IVA del receptor (CondicionIVAReceptorId) es obligatoria (RG 5616). | 10246 |
| `CONDICION_IVA_REQUIERE_CUIT` | error | `client.tipoDoc` | Un receptor "…" debe identificarse con CUIT. |  |
| `CONSUMIDOR_FINAL_SIN_IDENTIFICAR` | error | `client.tipoDoc` | Operaciones con consumidores finales de $ … o más requieren identificar al receptor (DNI, CUIT, CUIL o CDI). | 10015 |
| `COTIZACION_DECIMALES` | error | `cotizacion` | La cotización admite como máximo 6 decimales. |  |
| `COTIZACION_INVALIDA` | error | `cotizacion` | Para moneda PES la cotización debe ser 1. | 10120 |
| `CT_DOMICILIO` | error | `domicilioReceptor` | El domicilio del receptor admite hasta 300 caracteres. |  |
| `CT_FORMA_PAGO` | warning | `formasPago` | Se recomienda informar la forma de pago (consultarFormasPago). |  |
| `CT_ITEM_CODIGO` | error | `details[i].codigo` | El código del ítem debe tener entre 3 y 50 caracteres. |  |
| `CT_MODELO` | error | `invoice` | Los comprobantes de turismo se emiten con TurismoInvoice. |  |
| `CT_NO_SOPORTADO` | error | `[i]` | WSCT no admite …. |  |
| `CT_RANGO` | error | `numeroHasta` | WSCT autoriza un comprobante por solicitud (sin rangos). |  |
| `CT_REINTEGRO` | error | `importeReintegro` | El reintegro se informa como importe negativo (o 0). |  |
| `CT_RELACION` | error | `codigoRelacionEmisorReceptor` | Debe informar la relación emisor-receptor (consultarRelacionEmisorReceptor). |  |
| `CT_SWIFT` | error | `formasPago[i].swiftCode` | El código SWIFT debe tener entre 8 y 11 caracteres. |  |
| `CT_TARJETA` | error | `formasPago[i].numeroTarjeta` | Informe solo los 6 primeros dígitos de la tarjeta. |  |
| `DOC_NRO_INVALIDO` | error | `client.numDoc` | El número de documento debe ser numérico de hasta 11 dígitos (ARCA no admite letras en DocNro). | 10015 |
| `DOC_TIPO_INVALIDO` | error | `client.tipoDoc` | El tipo de documento … no es válido (FEParamGetTiposDoc). | 10015 |
| `EMISOR_CUIT_INVALIDO` | error | `company.cuit` | El CUIT del emisor "…" no es válido. |  |
| `FCE_ANULACION_EN_FACTURA` | error | `opcionales` | El opcional 22 (Anulación) solo se informa en notas de crédito/débito FCE. |  |
| `FCE_ANULACION_INVALIDA` | error | `opcionales` | El opcional 22 (Anulación) debe ser "S" o "N". |  |
| `FCE_ANULACION_REQUERIDA` | error | `opcionales` | Las notas de crédito/débito FCE deben informar el opcional 22 (Anulación: S o N). |  |
| `FCE_CBTE_ASOC_CUIT` | error | `[i].cuit` | En FCE el comprobante asociado debe informar el CUIT del emisor. |  |
| `FCE_CBTE_ASOC_FECHA` | error | `[i].fecha` | En FCE el comprobante asociado debe informar su fecha. |  |
| `FCE_CBU_INVALIDO` | error | `opcionales` | El CBU "…" no es válido (22 dígitos con dígitos verificadores). |  |
| `FCE_CBU_REQUERIDO` | error | `opcionales` | La Factura de Crédito Electrónica MiPyME debe informar el CBU del emisor (opcional 2101). |  |
| `FCE_NOTA_VTO_PAGO` | error | `fechaVencimientoPago` | Las notas de crédito/débito FCE no deben informar fecha de vencimiento del pago. |  |
| `FCE_PERIODO_NO_PERMITIDO` | error | `periodoAsociado` | Las notas FCE deben asociar el comprobante (CbtesAsoc), no un período. |  |
| `FCE_RECEPTOR_CUIT` | error | `client.tipoDoc` | En Factura de Crédito Electrónica MiPyME el receptor debe identificarse con CUIT. |  |
| `FCE_TRANSFERENCIA_INVALIDA` | error | `opcionales` | El opcional 27 (Transferencia) debe ser "SCA" o "ADC". |  |
| `FCE_TRANSFERENCIA_REQUERIDA` | error | `opcionales` | La Factura de Crédito Electrónica MiPyME debe informar el opcional 27 (Transferencia: SCA o ADC). |  |
| `FCE_VTO_PAGO_REQUERIDO` | error | `fechaVencimientoPago` | La Factura de Crédito Electrónica MiPyME debe informar la fecha de vencimiento del pago. |  |
| `FECHAS_SERVICIO_INVERTIDAS` | error | `fechaServicioHasta` | La fecha de servicio "hasta" no puede ser anterior a la fecha "desde". | 10036 |
| `FECHAS_SERVICIO_NO_CORRESPONDEN` | error | `fechaServicioDesde` | Las fechas de servicio solo se informan cuando el concepto es Servicios o Productos y Servicios. |  |
| `FECHAS_SERVICIO_REQUERIDAS` | error | `fechaServicioDesde` | Para concepto Servicios o Productos y Servicios son obligatorias la fecha desde y hasta del servicio. | 10035 |
| `FECHA_FUERA_DE_RANGO` | error | `fechaEmision` | La fecha del comprobante (…) no puede diferir en más de … días de la fecha actual (…) para el concepto …. | 10016 |
| `FECHA_VTO_PAGO_ANTERIOR` | error | `fechaVencimientoPago` | La fecha de vencimiento del pago no puede ser anterior a la fecha del comprobante. | 10037 |
| `FECHA_VTO_PAGO_NO_CORRESPONDE` | error | `fechaVencimientoPago` | La fecha de vencimiento del pago solo se informa en servicios o en Facturas de Crédito Electrónicas MiPyME. |  |
| `FECHA_VTO_PAGO_REQUERIDA` | error | `fechaVencimientoPago` | Para concepto Servicios o Productos y Servicios es obligatoria la fecha de vencimiento del pago. | 10035 |
| `IMPORTE_DECIMALES` | error | `[i]` | Los importes admiten como máximo 2 decimales. Redondee o use InvoiceCalculator. |  |
| `IMPORTE_EXCEDIDO` | error | `[i]` | El importe supera el máximo admitido (13 enteros y 2 decimales). |  |
| `IMPORTE_NEGATIVO` | error | `[i]` | El importe no puede ser negativo. | 10047 |
| `ITEMS_NO_CUADRAN` | error | `[i]` | La suma de los ítems (…) no coincide con el importe del comprobante (…). Use InvoiceCalculator con detalle por ítem. |  |
| `ITEMS_REQUERIDOS` | error | `details` | … requiere el detalle de ítems del comprobante. |  |
| `ITEM_ALICUOTA` | error | `[i].alicuotaIva` | El ítem gravado debe indicar una alícuota de IVA válida. |  |
| `ITEM_CANTIDAD` | error | `[i].cantidad` | La cantidad del ítem debe ser mayor a 0. |  |
| `ITEM_DESCRIPCION` | error | `[i].descripcion` | El ítem debe tener descripción. |  |
| `ITEM_DESCRIPCION_LARGA` | error | `[i].descripcion` | La descripción del ítem admite hasta 4000 caracteres. |  |
| `ITEM_IMPORTES` | error | `[i]` | Falta el IVA/importe del ítem: calcule con InvoiceCalculator::calculate($invoice, true). |  |
| `ITEM_IVA_MAL_CALCULADO` | error | `[i].importeIva` | El IVA del ítem (…) no corresponde a la alícuota … (esperado …). |  |
| `ITEM_PRECIO` | error | `[i].precioUnitario` | El precio unitario no puede ser negativo. |  |
| `ITEM_UNIDAD` | error | `[i].codigoUnidadMedida` | Indique la unidad de medida del ítem (consultarUnidadesMedida). |  |
| `IVA_BASE_INVALIDA` | error | `[i].baseImp` | La base imponible de la alícuota debe ser mayor a 0. |  |
| `IVA_BASE_NO_CUADRA` | error | `impNeto` | La suma de las bases imponibles de IVA (…) debe ser igual al importe neto gravado (…). | 10053 |
| `IVA_IMPORTE_NEGATIVO` | error | `[i].importe` | El importe de IVA no puede ser negativo. |  |
| `IVA_MAL_CALCULADO` | error | `[i].importe` | El IVA … sobre … debe ser … (informado: …). | 10051 |
| `IVA_NO_CORRESPONDE` | error | `ivas` | Los comprobantes clase C (y bienes usados) no deben informar alícuotas de IVA. | 10071 |
| `IVA_REQUERIDO` | error | `ivas` | Si el importe neto gravado es mayor a 0 deben informarse las alícuotas de IVA. | 10070 |
| `IVA_SIN_NETO` | error | `ivas` | No deben informarse alícuotas de IVA si el importe neto gravado es 0. |  |
| `IVA_TOTAL_NO_CUADRA` | error | `impIva` | La suma de los importes de IVA (…) debe ser igual a ImpIVA (…). | 10052 |
| `LOTE_EXCEDIDO` | error | `invoices` | Se pueden enviar como máximo … comprobantes por solicitud (FECompTotXRequest). | 10002 |
| `LOTE_HETEROGENEO` | error | `[i]` | Todos los comprobantes del lote deben tener el mismo tipo y punto de venta. |  |
| `LOTE_NO_CORRELATIVO` | error | `[i].numero` | Los comprobantes del lote deben ser correlativos: se esperaba el número …. | 10016 |
| `LOTE_VACIO` | error | `invoices` | Debe enviar al menos un comprobante. |  |
| `MONEDA_DESCONOCIDA` | warning (error con catalogosEstrictos) | `moneda` | La moneda "…" no figura en el catálogo local; verifique con FEParamGetTiposMonedas. | 10119 |
| `MONEDA_INVALIDA` | error | `moneda` | El código de moneda "…" no es válido (3 caracteres, ver FEParamGetTiposMonedas). | 10119 |
| `MTXCA_ACTIVIDADES` | error | `actividades` | WSMTXCA no admite el detalle de actividades. |  |
| `MTXCA_OBSERVACIONES` | error | `observaciones` | Las observaciones admiten hasta 2000 caracteres. |  |
| `MTXCA_OPCIONALES` | error | `opcionales` | WSMTXCA no admite opcionales: use datos adicionales (setDatosAdicionales). |  |
| `MTXCA_RANGO` | error | `numeroHasta` | WSMTXCA autoriza un comprobante por solicitud (sin rangos). |  |
| `MTXCA_TIPO_NO_SOPORTADO` | error | `tipoComprobante` | WSMTXCA autoriza facturas y notas de crédito/débito clase A, B y M (incluida FCE). Para otros comprobantes use WSFEv1. |  |
| `NUMERO_HASTA_INVALIDO` | error | `numeroHasta` | El número "hasta" debe ser mayor o igual al número "desde". |  |
| `NUMERO_INVALIDO` | error | `numero` | El número de comprobante debe estar entre 1 y …. |  |
| `OPCIONAL_DESCONOCIDO` | warning (error con catalogosEstrictos) | `[i].id` | El opcional … no figura en el catálogo local; verifique con FEParamGetTiposOpcional. |  |
| `OPCIONAL_DUPLICADO` | error | `[i].id` | El opcional … está informado más de una vez. |  |
| `OPCIONAL_ID_REQUERIDO` | error | `[i].id` | Debe informar el Id del dato opcional. |  |
| `OPCIONAL_SOLO_FCE` | error | `opcionales` | El opcional … (…) solo corresponde a comprobantes FCE MiPyME. |  |
| `OPCIONAL_VALOR_REQUERIDO` | error | `[i].valor` | Debe informar el valor del opcional …. |  |
| `PERIODO_EN_FACTURA` | warning | `periodoAsociado` | El período asociado se utiliza en notas de crédito/débito. |  |
| `PERIODO_INVERTIDO` | error | `periodoAsociado.hasta` | La fecha "hasta" del período asociado no puede ser anterior a la fecha "desde". |  |
| `PERIODO_POSTERIOR` | error | `periodoAsociado.hasta` | El período asociado no puede terminar después de la fecha del comprobante. |  |
| `PTO_VTA_INVALIDO` | error | `puntoVenta` | El punto de venta debe estar entre 1 y …. | 10004 |
| `RANGO_NO_PERMITIDO` | error | `numeroHasta` | Solo pueden informarse rangos de comprobantes (CbteDesde ≠ CbteHasta) en clase B o C a consumidor final sin identificar (DocTipo 99). |  |
| `RECEPTOR_DOMICILIO_REQUERIDO` | error | `client.address` | Debe informar el domicilio del receptor. |  |
| `RECEPTOR_NOMBRE_REQUERIDO` | error | `client.razonSocial` | Debe informar la razón social del receptor. |  |
| `RECEPTOR_REQUERIDO` | error | `client` | Debe informar el receptor. Para consumidor final sin identificar use Client::consumidorFinal(). |  |
| `RECEPTOR_SIN_IDENTIFICAR` | warning | `client.tipoDoc` | Un receptor "…" normalmente debe identificarse con su documento. |  |
| `RG3668_INCOMPLETO` | error | `opcionales` | Para RG 3668 deben informarse juntos los opcionales 61 (tipo doc. firmante), 62 (nro. doc. firmante) y 7 (carácter del firmante). |  |
| `SUBTOTAL_IVA_ITEMS` | error | `ivas` | El subtotal de IVA … (…) debe ser igual a la suma del IVA de los ítems (…). |  |
| `TOTAL_CERO` | warning (error con catalogosEstrictos) | `impTotal` | El comprobante tiene importe total 0. |  |
| `TOTAL_NO_CUADRA` | error | `impTotal` | El importe total (…) debe ser igual a No gravado + Neto + Exento + Tributos + IVA (…). | 10048 |
| `TRIBUTOS_NO_CUADRAN` | error | `impTributos` | La suma de los tributos (…) debe ser igual a ImpTrib (…). | 10049 |
| `TRIBUTOS_REQUERIDOS` | error | `tributos` | Si ImpTrib es mayor a 0 debe informar el detalle de tributos. | 10049 |
| `TRIBUTO_DESC_LARGA` | error | `[i].descripcion` | La descripción del tributo admite hasta 80 caracteres. |  |
| `TRIBUTO_DESC_REQUERIDA` | error | `[i].descripcion` | Debe informar la descripción del tributo. |  |
| `TRIBUTO_INVALIDO` | error | `[i].id` | El tipo de tributo … no es válido (FEParamGetTiposTributos). |  |
| `TRIBUTO_MAL_CALCULADO` | warning | `[i].importe` | El importe del tributo (…) no coincide con base × alícuota (…). |  |
| `TRIBUTO_NEGATIVO` | error | `[i]` | La base, alícuota e importe del tributo no pueden ser negativos. |  |

## Verificaciones en línea (See)

| Código | Nivel | Campo | Descripción | ARCA |
|---|---|---|---|---|
| `CBTE_ASOC_INEXISTENTE` | error | `%scbtesAsociados[i]` | El comprobante asociado … …-… no existe como autorizado en ARCA. | 10041 |
| `CONDICION_IVA_PADRON` | error | `client.condicionIva` | La condición frente al IVA informada (…) no coincide con el padrón de ARCA (…). |  |
| `COTIZACION_FUERA_DE_RANGO` | error | `cotizacion` | La cotización informada (…) se desvía más de … %% de la oficial de ARCA (…). |  |
| `FCE_OBLIGATORIA` | error | `tipoComprobante` | El receptor está obligado a recibir FCE MiPyME desde $ …: corresponde emitir Factura de Crédito Electrónica (201/206/211). |  |
| `FCE_RECEPTOR_NO_OBLIGADO` | error | `tipoComprobante` | El receptor no está obligado ni optó por recibir Factura de Crédito Electrónica MiPyME: emita una factura común. | 150 |
| `FECHA_ANTERIOR_ULTIMO` | error | `fechaEmision` | La fecha del comprobante (…) no puede ser anterior a la del último comprobante autorizado …-… (…). | 10016 |
| `LOTE_EXCEDIDO` | error | `invoices` | Este servicio admite como máximo … comprobante(s) por solicitud. |  |
| `PTO_VTA_NO_HABILITADO` | error | `puntoVenta` | El punto de venta … fue dado de baja el …. | 10004 |
| `RECEPTOR_INACTIVO` | error | `client.numDoc` | El CUIT del receptor no está activo en ARCA (…). |  |
| `RECEPTOR_INEXISTENTE` | error | `client.numDoc` | El CUIT del receptor no existe en el padrón de ARCA. |  |

## WSFEX (ExportInvoiceValidator)

| Código | Nivel | Campo | Descripción | ARCA |
|---|---|---|---|---|
| `CAN_MIS_MON_EXT_NO_CORRESPONDE` | warning | `pagoMismaMoneda` | CanMisMonExt no se informa si la moneda es PES; no se enviará. |  |
| `CBTE_ASOC_CUIT` | error | `[i].cuit` | El CUIT del comprobante asociado no es válido. |  |
| `CBTE_ASOC_NUMERO` | error | `[i]` | El punto de venta y número del comprobante asociado deben ser mayores a 0. |  |
| `CBTE_ASOC_REQUERIDO` | error | `cbtesAsociados` | Las notas de crédito/débito de exportación deben informar el comprobante asociado. |  |
| `CBTE_ASOC_TIPO` | error | `[i].tipo` | El comprobante asociado debe ser de exportación (19, 20 o 21). |  |
| `CBTE_TIPO_INVALIDO` | error | `tipoComprobante` | El tipo de comprobante de exportación debe ser 19 (Factura E), 20 (Nota de Débito E) o 21 (Nota de Crédito E). |  |
| `CLIENTE_ID_REQUERIDO` | error | `cuitPaisCliente` | Debe informar el CUIT del país del cliente (FEXGetPARAM_DST_CUIT) o su identificador impositivo. |  |
| `CLIENTE_REQUERIDO` | error | `cliente` | Debe informar el nombre o razón social del cliente. |  |
| `COTIZACION_DECIMALES` | error | `cotizacion` | La cotización admite como máximo 6 decimales. |  |
| `COTIZACION_INVALIDA` | error | `cotizacion` | Para moneda PES la cotización debe ser 1. |  |
| `CUIT_PAIS_INVALIDO` | error | `cuitPaisCliente` | El CUIT de país "…" no es válido. |  |
| `DOMICILIO_REQUERIDO` | error | `domicilioCliente` | Debe informar el domicilio del cliente. |  |
| `FECHA_FUERA_DE_RANGO` | warning | `fechaEmision` | La fecha del comprobante difiere en más de … días de la fecha actual; ARCA podría rechazarla. |  |
| `FECHA_PAGO_REQUERIDA` | error | `fechaPago` | En exportación de servicios u otros debe informar la fecha de pago. |  |
| `FORMA_PAGO_LARGO` | error | `formaPago` | La forma de pago admite hasta 50 caracteres. |  |
| `IDIOMA_INVALIDO` | error | `idioma` | El idioma debe ser 1 (español), 2 (inglés) o 3 (portugués). |  |
| `ID_INVALIDO` | error | `id` | El Id de la solicitud debe ser mayor a 0. |  |
| `IMPORTE_DECIMALES` | error | `impTotal` | El importe total admite como máximo 2 decimales. |  |
| `INCOTERMS_DS_LARGO` | error | `incotermsDescripcion` | La descripción del Incoterm admite hasta 20 caracteres. |  |
| `INCOTERMS_INVALIDO` | error | `incoterms` | El Incoterm "…" no es válido (FEXGetPARAM_Incoterms). |  |
| `INCOTERMS_RECOMENDADO` | warning | `incoterms` | Se recomienda informar el Incoterm en exportaciones de bienes. |  |
| `ITEMS_REQUERIDOS` | error | `items` | El comprobante de exportación debe tener al menos un ítem. |  |
| `ITEM_BONIFICACION` | error | `[i]` | En ítems de bonificación (unidad 99) la cantidad y el precio deben ser 0 y el importe va en el total. |  |
| `ITEM_DESCRIPCION` | error | `[i].descripcion` | El ítem debe tener descripción. |  |
| `ITEM_DESCRIPCION_LARGA` | error | `[i].descripcion` | La descripción del ítem admite hasta 4000 caracteres. |  |
| `ITEM_NEGATIVO` | error | `[i]` | Cantidad, precio, bonificación y total del ítem no pueden ser negativos. |  |
| `ITEM_TOTAL` | error | `[i].total` | El total del ítem (…) debe ser cantidad × precio − bonificación (…). |  |
| `ITEM_UNIDAD_DESCONOCIDA` | warning | `[i].unidad` | La unidad de medida … no figura en el catálogo local (FEXGetPARAM_UMed). |  |
| `MONEDA_DESCONOCIDA` | warning | `moneda` | La moneda "…" no figura en el catálogo local. |  |
| `MONEDA_INVALIDA` | error | `moneda` | El código de moneda debe tener 3 caracteres (FEXGetPARAM_MON). |  |
| `NUMERO_INVALIDO` | error | `numero` | El número de comprobante debe estar entre 1 y 99999999. |  |
| `OBS_COMERCIALES_LARGO` | error | `observacionesComerciales` | Las observaciones comerciales admiten hasta 4000 caracteres. |  |
| `OBS_LARGO` | error | `observaciones` | Las observaciones admiten hasta 1000 caracteres. |  |
| `OPCIONAL_INCOMPLETO` | error | `opcionales[i]` | El opcional debe tener Id y valor. |  |
| `PAIS_DESTINO_REQUERIDO` | error | `paisDestino` | Debe informar el país de destino del comprobante (FEXGetPARAM_DST_pais). |  |
| `PERMISOS_NO_CORRESPONDEN` | error | `permisos` | No deben informarse permisos si Permiso_existente es "N". |  |
| `PERMISOS_REQUERIDOS` | error | `permisos` | Si existe permiso de embarque debe informar al menos un permiso. |  |
| `PERMISO_DESTINO` | error | `permisos[i].paisDestino` | Debe informar el país de destino de la mercadería. |  |
| `PERMISO_EXISTENTE_NO_CORRESPONDE` | error | `permisoExistente` | El indicador de permiso de embarque solo se informa en facturas de exportación de bienes. |  |
| `PERMISO_EXISTENTE_REQUERIDO` | error | `permisoExistente` | En facturas de exportación de bienes debe indicar si existe permiso de embarque ("S" o "N"). |  |
| `PERMISO_INVALIDO` | error | `permisos[i].id` | El permiso de embarque debe tener 16 caracteres alfanuméricos. |  |
| `PTO_VTA_INVALIDO` | error | `puntoVenta` | El punto de venta debe estar entre 1 y 99998. |  |
| `TIPO_EXPO_INVALIDO` | error | `tipoExportacion` | El tipo de exportación debe ser 1 (bienes), 2 (servicios) o 4 (otros). |  |
| `TOTAL_INVALIDO` | error | `impTotal` | El importe total debe ser mayor a 0. |  |
| `TOTAL_NO_CUADRA` | error | `impTotal` | El importe total (…) debe ser igual a la suma de los ítems menos bonificaciones (…). |  |
