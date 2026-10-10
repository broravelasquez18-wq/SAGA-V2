# Auditoria funcional de SAGA V2

Este documento conserva los hallazgos iniciales. Ver `CORRECCIONES.md` para el
estado posterior de las soluciones y las decisiones confirmadas por el usuario.

Fecha: 2026-10-04. Resultado: el sistema tiene flujos funcionales, pero no esta listo para declararse libre de errores. Priorizar permisos antes de ampliar su uso.

## Alcance y evidencia

Se reviso el codigo local, incluyendo cambios aun sin commit. Se creo la base local `saga_audit_20261004` copiando solamente la estructura de la BD existente e insertando datos sinteticos. No se copiaron personas reales, no se modificaron cuentas reales y no se enviaron correos. Servidor de prueba: PHP 8.2.12 en `127.0.0.1:8097`. No se audito el VPS ni se verifico que tenga esta misma version o esquema.

Se inicio sesion y se navego en navegador con admin, instructor, celador y vocero. Adicionalmente se consultaron las 29 paginas PHP principales de sus carpetas por HTTP con sesion de cada rol. Cargar una pagina no demuestra que todos sus botones funcionen: los flujos ejecutados se detallan abajo.

| Comprobacion | Resultado |
| --- | --- |
| Sintaxis de PHP, excluyendo vendor | 122 archivos, 0 errores |
| Pruebas HTTP/integracion y reglas de negocio | 86 casos: 74 pasan, 12 no cumplen lo esperado |
| Recuperacion de contrasena, con tablas temporales y correo simulado | 24 comprobaciones pasan |
| Pantallas incluidas en los 86 casos | 29 responden HTTP 200 sin avisos/errores PHP detectados |

Los 12 casos fallidos no equivalen a 12 causas independientes. Una regla sobre cancelacion el mismo dia requiere confirmar la politica de negocio. Los hallazgos adicionales de migraciones e interfaz se identificaron por inspeccion, no estan incluidos en ese conteo. No es un porcentaje de confiabilidad del sistema.

Evidencia reproducible: `tests/auditoria_resultados.json`, `tests/auditoria_extra_resultados.json`, `tests/auditoria_http.php`, `tests/auditoria_extra.php`, `tests/auditoria_setup.php`. Las capturas de esta carpeta muestran exclusivamente datos sinteticos.

## Hallazgos prioritarios

### P1. Un vocero puede crear ocupaciones sin aprobacion

Archivo: `controllers/RegistrarOcupacionMultiple.php:19`, insercion en linea 261. El controlador permite instructor y vocero; las validaciones de ficha solo se ejecutan para instructor. Una peticion autenticada de vocero con CSRF valido, ambiente de otra sede, fecha futura y jornada crea directamente una ocupacion. Prueba: `vocero no ocupa directamente sin aprobacion`. La interfaz normal de solicitudes funciona, pero el servidor permite saltarla.

Recomendacion: reservar ese controlador para instructores autorizados; el vocero debe usar exclusivamente solicitudes y validar su ficha/sede en el servidor.

### P1. El aislamiento entre sedes puede saltarse

Archivos: `controllers/RegistrarOcupacion.php:22`, `controllers/FinalizarOcupacion.php:21`, `controllers/RegistrarOcupacionMultiple.php:117`. Se comprobo que un celador de sede 1 puede registrar y finalizar ocupaciones de sede 2 cambiando identificadores de la peticion. Tambien un instructor de planta de sede 1 puede registrar en sede 2 usando su ficha. No basta validar que ambiente y sede enviados coincidan: deben estar autorizados por la sesion y la BD.

Ademas, finalizar permite modificar datos mediante GET sin CSRF. No se ejecutaron enlaces contra cuentas reales. Recomendacion: permisos centralizados por usuario, rol, contrato, sede y recurso; POST con CSRF para cualquier cambio de estado.

### P1. El hash de una contrasena sirve como contrasena

Archivo: `controllers/loginController.php:35`. La condicion `password_verify(...) || ($password === $hash)` acepta literalmente el hash almacenado. Se reprodujo con el hash de una cuenta sintetica. Esto NO significa que se haya encontrado una forma de leer hashes remotamente, pero convierte una eventual filtracion en acceso inmediato sin descifrarlos.

Recomendacion: eliminar el fallback de texto plano y migrar las cuentas antiguas de forma controlada.

### P2. Un vocero puede consultar informacion de instructores de otra sede

Archivo: `controllers/ObtenerInstructor.php:7`. Solo exige una sesion iniciada. La prueba con un vocero obtuvo `success=true` para el instructor de otra sede. Revisar tambien endpoints equivalentes de celadores y voceros, que tienen la misma validacion debil. Restringir el rol y el alcance; devolver solo campos necesarios.

### P2. Desactivar una cuenta no bloquea su sesion existente

Referencia: `views/instructor/dashboard_instructor.php:5`. Se desactivo la cuenta sintetica en BD y su sesion siguio accediendo al dashboard. El login comprueba el estado inicial, pero esta pagina no lo revalida. La recuperacion de contrasena tampoco revoca sesiones anteriores. Centralizar la validacion de estado y una version de sesion revocable.

### P2. Finalizar antes de tiempo conserva una hora futura

Archivos: `controllers/FinalizarOcupacionInstructor.php:66`, `controllers/FinalizarOcupacion.php:89`; revisar tambien `controllers/ResolverFinalizacionCelador.php`. Se finalizo una ocupacion activa que terminaba una hora despues y se guardo la hora programada futura como `fecha_fin`. Los historiales no representan la finalizacion real. Separar fin programado y real o guardar el instante efectivo segun el modelo acordado.

### P2. Se aprueba una cancelacion aunque ya no se pueda cancelar

Archivo: `controllers/ResolverSolicitudCancelacion.php:64`. Para una ocupacion ya finalizada, el UPDATE no cambia ninguna fila, pero la solicitud pasa a aprobada. Puede generar avisos de cancelacion incorrectos. Validar estado actual, filas afectadas y resolver solicitud/ocupacion en una transaccion.

### P2. Vencer una ocupacion no sincroniza el estado del ambiente

Archivo: `config/estados.php:19`. Se preparo un ambiente aislado, ocupado, con una unica ocupacion vencida. La funcion marco el historial como finalizado pero dejo `ambientes.estado='ocupado'`. Ese campo se consume en `views/admin/Ambientes_piso.php:56` y `views/celador/dashboard_celador.php:41`, por lo que los contadores pueden contradecir el historial. La prueba ejecuta la funcion de vencimiento, no certifica el cron instalado en el VPS.

### P2. Falta una migracion para adjuntos de cancelacion

Archivo: `sql/migracion_solicitudes.sql:9`. Crea `solicitudes_cancelacion` sin `adjunto`; `controllers/SolicitarCancelacionOcupacion.php:109` inserta esa columna y `views/admin/solicitudes_admin.php:22` la consulta. No se encontro otra migracion que la agregue. La BD local si la tiene, por eso las pruebas locales pasan. Una instalacion basada solamente en esos scripts queda incompleta. No se comprobo el esquema actual del VPS.

### P2/P3. Reglas de calendario incompletas o por aclarar

`controllers/SolicitarCancelacionOcupacion.php:50` rechaza toda cancelacion el mismo dia aunque falten horas para iniciar. Se reprodujo con una reserva para las 21:00 antes de esa hora. Si la politica exige un dia de anticipacion, documentarla en la interfaz; si basta no haber iniciado, comparar fecha y hora completas. Caso condicionado a la politica, no decidirla automaticamente.

`config/calendario_habil.php:18` termina su lista en 2027; `es_dia_no_habil('2028-01-01')` devuelve falso. Ampliar o calcular el calendario y agregar pruebas por ano. No afecta al ano 2026 de la prueba.

### P3. Restos de nivel de estudio en gestion de voceros

El formulario ya muestra ficha y no pide nivel, pero `views/admin/voceros_admin.php:208` conserva "Todos los niveles" y la linea 271 muestra "Sin nivel asignado". Retirar el filtro y el texto si el concepto dejo de existir. En movil el calendario de vocero no produjo desbordamiento horizontal en 390x844, aunque las etiquetas de ocupacion se recortan mucho.

## Recorrido por rol

| Rol | Comprobado | Pendiente o problema |
| --- | --- | --- |
| Admin | 19 pantallas; alta de sede, piso, ambiente, ficha y vocero con ficha; reserva y rechazo de solapamiento; aprobacion de cambio de jornada y cancelacion; cuatro cargas CSV; cuatro plantillas; ocho exportaciones PDF/XLSX | Migracion de adjuntos, cancelaciones obsoletas, estado de ambientes; no se ejecutaron todas las ediciones/eliminaciones |
| Instructor | 4 pantallas; registro mediante formulario y calendario; solicitud de cambio de jornada y cancelacion; bloqueo de panel admin | Cruce de sede, hora real, cancelacion de hoy, sesion de cuenta desactivada |
| Celador | 4 pantallas; dashboard, calendario, historial/lista; aprobacion de ocupacion y de finalizacion del vocero; bloqueo de panel admin | Puede actuar sobre otra sede mediante peticiones directas; finalizacion GET sin CSRF |
| Vocero | 2 pantallas; calendario y detalle de ficha; solicitud normal de ocupacion y finalizacion; finalizacion futura rechazada; campana y vista movil; bloqueo de panel admin | Controlador alterno permite ocupacion directa; acceso a datos de instructor ajeno |
| Acceso/recuperacion | Login por los 4 roles, mostrar/ocultar contrasena, enlace de recuperacion; token valido/invalido/vencido/usado; limites; cuenta inactiva; cambio de correo; hash de nueva clave | Entrega real de correo no probada; no hay revocacion de sesiones previas; fallback inseguro del login |

Las exportaciones se verificaron por respuesta HTTP, contenido no vacio y firma PDF/ZIP; no se reviso cada celda, total ni pagina renderizada. Las cargas fueron CSV pequeno valido; no cubren todos los errores ni cargas grandes. No se verifico cada combinacion de permisos de contratistas.

## Que falta para una validacion de produccion

1. Corregir y repetir primero los casos P1 y los controles de acceso P2.
2. Consolidar migraciones versionadas y probar instalacion limpia/restauracion sin ejecutar scripts destructivos sobre datos reales. Confirmar en el VPS notificaciones, solicitudes, adjuntos y recuperacion.
3. Probar SMTP real, recuperacion de extremo a extremo y notificaciones externas con una cuenta de prueba autorizada. No inferir envio por una alerta de exito.
4. Verificar Google Sheets con un documento de prueba, reconciliacion de cambios y fallos de conexion; no se sincronizaron documentos externos.
5. Verificar scheduler/cron, zona horaria, persistencia de uploads, respaldos y restauracion en Docker/Linux. El Dockerfile por si solo no demuestra que haya cron instalado externamente.
6. Validar autodeploy con un commit identificable, entrega de webhook, log exitoso y version visible en el VPS. Esta auditoria no hizo push ni deploy.
7. Probar peticiones simultaneas sobre el mismo ambiente, dobles aprobaciones, carga y rendimiento. El servidor PHP local de esta prueba no valida concurrencia de produccion.
8. Completar edicion/eliminacion por modulo, rechazos de solicitudes, archivos adjuntos, XLSX importado y navegadores/dispositivos adicionales.
9. Revocar la clave privada compartida anteriormente si aun no se hizo. No se utilizo para esta auditoria.

## Repetir las pruebas sin tocar produccion

Los scripts requieren CLI y host MySQL local. `auditoria_setup.php --reset` borra SOLO `saga_audit_20261004`; no cambiar ese nombre ni apuntar las pruebas a la base operativa. El servidor de prueba debe iniciarse explicitamente con `DB_NAME=saga_audit_20261004`, `APP_URL=http://127.0.0.1:8097`, `MAIL_PASSWORD=xxxx`, `MAIL_USERNAME` vacio y `TWILIO_ACCOUNT_SID` vacio antes de ejecutar los clientes HTTP. PHP carga las otras variables desde la configuracion local.

Orden: setup con `--reset`, servidor PHP local en puerto 8097, `tests/auditoria_http.php`, `tests/auditoria_extra.php`, `tests/password_reset.php`. La ultima suite usa tablas temporales. Las cuentas 99000001 a 99000007 y su clave en el fixture son sinteticas, nunca deben importarse a produccion. Los casos con fechas dependen del dia/hora de ejecucion; la prueba de cancelacion de hoy presupone ejecutarse antes de las 21:00.

Se conservan scripts, resultados y capturas para corregir y repetir. No se modifico la logica de la aplicacion durante esta auditoria.
