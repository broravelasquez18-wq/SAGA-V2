# Correcciones de la auditoria

## Decisiones confirmadas

- El instructor solo puede solicitar cancelacion hasta el dia anterior.
- Finalizar reemplaza la hora de fin programada por la hora real; no se agrega una segunda columna de horario.
- Las contrasenas antiguas se conservan y se protegen con hash, sin obligar a cambiarlas.
- Las migraciones se ejecutan automaticamente en cada despliegue.

## Soluciones locales

- Se impide al vocero crear ocupaciones por el controlador alternativo de instructores.
- Celadores e instructores de planta no pueden registrar en sedes ajenas. Finalizar verifica sede, rol, POST y CSRF; sus botones usan formularios. El endpoint antiguo de admin/celador aplica los mismos controles.
- Se mantiene el acceso multisede existente del instructor contratista. Registrar para otra persona valida que sea instructor activo autorizado para la sede.
- Consultas de datos para editar usuarios quedan limitadas al administrador.
- Los hashes almacenados no sirven como contrasenas. Un primer login de una cuenta legacy valida solo texto no reconocido como hash y lo convierte inmediatamente a hash con actualizacion condicional. La migracion CLI permite proteger las cuentas restantes de una sola vez.
- Altas, ediciones e importaciones de instructores/celadores guardan hashes; voceros no altera caracteres especiales antes de generar el hash. Editar con clave vacia conserva la anterior.
- Las sesiones se invalidan al cambiar clave, estado, rol, contrato o sede. El despliegue requiere iniciar sesion de nuevo. La validacion se ejecuta al conectar a la BD; las paginas privadas redirigen al login, los controladores devuelven 401.
- Finalizar registra la hora actual, rechaza reservas futuras y evita repetir la misma finalizacion. Las resoluciones de cancelacion/finalizacion comprueban filas afectadas y usan transacciones antes de notificar.
- Las ocupaciones vencidas liberan el estado del ambiente cuando no hay otra activa. Las peticiones autenticadas refrescan los estados; cron sigue siendo necesario para actualizarlos sin visitas. El contador disponible del admin usa ambientes y no registros historicos.
- La migracion agrega `adjunto` donde falta y crea las tablas de notificaciones/recuperacion si no existen. Es idempotente y no cambia de base mediante USE.
- Los festivos se calculan por ano en PHP y JavaScript; se comprobo su equivalencia para 2025-2035. Se corrige ademas la omision del 1 de mayo de 2027 de la lista antigua. Fuente para las reglas: [Ley 51 de 1983, Funcion Publica](https://www.funcionpublica.gov.co/eva/gestornormativo/norma.php?i=4954).
- Se retiraron filtro, badges y texto de nivel de estudio de voceros. La ficha sigue disponible. La cedula se muestra como texto, preservando ceros iniciales.
- Se excluyen pruebas/documentos internos de la imagen Docker y se bloquea su acceso mediante Apache, junto con SQL y cron. El servidor PHP integrado no interpreta .htaccess: se usa solo en localhost para pruebas.

## Verificacion

Base aislada `saga_audit_20261004`, datos sinteticos, correo externo desactivado. No se migraron usuarios de la base operativa ni se accedio al VPS.

- Suite original ajustada a la politica confirmada, mas comprobacion de hashes importados: 70 + 18 casos.
- Regresion adicional de migracion repetible, legacy, permisos, finalizacion, altas/ediciones, sesiones y festivos: 25 casos en `tests/correcciones_auditoria.php`.
- Recuperacion con tablas temporales y envio simulado: 24 comprobaciones.
- Paridad de calendario: `node tests/calendario_habil.cjs`, 4017 fechas.
- Revision en navegador: login admin, ficha/busqueda de voceros sin niveles y finalizacion de una reserva sintetica mediante el nuevo formulario. La pantalla muestra la hora real y un ambiente disponible. Capturas: `voceros-corregido.png` y `finalizacion-corregida.png`.

Los JSON de tests contienen los resultados de la ultima ejecucion, no los fallos historicos. No son una garantia de ausencia de todos los bugs ni de funcionamiento del VPS.

Se validaron PHP y JavaScript localmente. No hay Docker disponible en esta maquina,
por lo que no se ejecuto un build/arranque de la imagen; el entrypoint queda pendiente
de comprobacion en Dokploy. La migracion que invoca si se ejecuto dos veces con exito.

## Instalacion pendiente

1. Hacer y verificar un respaldo de la BD del VPS fuera del directorio publico. No usar `sql/saga_v2.sql` para actualizar: contiene operaciones de recreacion.
2. Ejecutar `php migrate_auditoria.php` sin opciones para revisar base destino y cantidad de cuentas legacy. No imprime contrasenas.
3. Desplegar usando el Dockerfile del proyecto. `docker/saga-entrypoint.sh` ejecuta `php migrate_auditoria.php --apply` antes de Apache en cada arranque. Un bloqueo MySQL serializa replicas. No se debe sobrescribir el entrypoint desde Dokploy. La BD debe existir, estar accesible y permitir CREATE/ALTER/UPDATE. Si falla, el nuevo contenedor no inicia la aplicacion; revisar logs, no eludir el error.
4. Volver a iniciar sesion y probar un usuario por rol y la recuperacion con una cuenta autorizada. Si hay una credencial de formato no migrable, el script se detiene para revision; no intenta adivinarla. No se borra ni recrea la BD. El respaldo sigue siendo necesario antes del despliegue; el entrypoint no lo realiza.
5. Comprobar SMTP/APP_URL, Google Sheets, scheduler, volumen persistente de uploads y respaldo/restauracion. Verificar autodeploy con commit y log del VPS. No se modificaron esas integraciones ni se hizo push.

La prueba de dobles resoluciones es secuencial; falta carga concurrente en el entorno de produccion. Las aprobaciones corregidas son atomicas, pero los otros flujos de reserva requieren una prueba de concurrencia aparte. La clave privada expuesta anteriormente debe revocarse si aun existe; no se utilizo.
