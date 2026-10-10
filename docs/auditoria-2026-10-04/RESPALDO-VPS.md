# Respaldo previo al despliegue

Fecha: 4 de octubre de 2026, 16:00:16, America/Bogota (21:00:16 UTC).

## Origen y archivo

- VPS: 2.25.77.168, proyecto SAGA-V2, entorno production.
- Servicio MySQL: `sagav2-sagav2-zrexa6`, imagen configurada `mysql:8`.
- Base: `saga_v2`. Se observaron 14 tablas InnoDB, 2 con datos exportados y 1 usuario en origen.
- Volumen persistente configurado: `sagav2-sagav2-zrexa6-data`, montado en `/var/lib/mysql`.
- Archivo dentro del contenedor MySQL: `/var/lib/mysql/.saga-backups/saga_v2-predeploy-20261004-160016.sql.gz`.
- Archivo de verificacion: `/var/lib/mysql/.saga-backups/saga_v2-predeploy-20261004-160016.sql.gz.sha256`.
- Tamano: 3028 bytes. Directorio privado 700, archivo 600, propietario root.
- SHA-256: `70d7b6404a5fe941710ba15fd410e733f7b9b89de8b55637ad84894a6dbdad3b`.

Se creo mediante `mysqldump --single-transaction --quick --routines --events --triggers --hex-blob --set-gtid-purged=OFF --no-tablespaces --databases saga_v2`, comprimido con gzip. Las credenciales se tomaron del entorno del contenedor sin mostrarlas. Se utilizo pipefail y un archivo parcial antes de confirmar el resultado.

El archivo de nombre sencillo es un enlace fisico al volcado original dentro de una subcarpeta `predeploy-20261004-160016-*`. Ambos nombres corresponden al mismo archivo, NO son dos respaldos independientes.

## Comprobaciones realizadas

- El proceso de volcado termino correctamente y el SQL contiene su marcador final de completitud.
- La cantidad de CREATE TABLE coincide con las 14 tablas del origen.
- Se identificaron INSERT para 2 tablas, sin imprimir su contenido ni datos personales.
- `gzip -t` termino correctamente.
- `sha256sum -c` confirmo integridad; se repitio la comprobacion.
- No se alteraron registros, permisos de acceso a MySQL, puertos ni configuracion de despliegue. No se ejecutaron migraciones, push ni despliegues.

## Limites importantes

Es una copia logica de la BD, no de los archivos subidos por los usuarios ni de las cuentas globales/permisos del servidor MySQL. No se ensayo una restauracion completa en otra base. La integridad del archivo no reemplaza esa prueba.

La copia esta en el MISMO VPS y volumen persistente que MySQL. Protege frente a cambios de esquema/datos si ese almacenamiento sigue disponible; no protege frente a perdida del VPS/volumen. Dokploy no tenia destino S3 configurado y el acceso SSH del equipo y de la terminal general no autenticaron. No se agregaron claves ni se debilito la seguridad para acceder.

La operacion se realizo por la terminal Docker de la sesion existente de Dokploy. No se descargaron datos de la BD al equipo local ni se enviaron a proveedores externos. Se recomienda otra copia fuera del VPS antes de operaciones mayores.

## Verificacion posterior

Desde la terminal del contenedor MySQL:

```sh
cd /var/lib/mysql/.saga-backups
gzip -t saga_v2-predeploy-20261004-160016.sql.gz
sha256sum -c saga_v2-predeploy-20261004-160016.sql.gz.sha256
```

No importar este archivo en una BD de pruebas sin adaptar previamente su destino: el volcado incluye CREATE DATABASE/USE para `saga_v2`. Una restauracion requiere confirmar el destino y planificarla aparte; no se ejecuta como parte de la verificacion.

Evidencia visual local: `respaldo-vps.png`.
