# Recuperacion de contrasena

El login permite solicitar un enlace usando cedula/usuario y correo registrado.
La respuesta no confirma si la cuenta existe. Los enlaces duran 30 minutos,
son de un solo uso y quedan invalidados al emitir uno nuevo. Solo se recuperan
cuentas activas. Una cuenta sin correo valido requiere ayuda del administrador.

## Activacion en Dokploy

1. Desplegar los archivos nuevos.
2. En la terminal del contenedor SAGA ejecutar:

```sh
php /var/www/html/migrate_password_reset.php
```

3. En las variables de entorno de la aplicacion configurar:

```text
APP_URL=https://saga-v2.broa18.online
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=correo-del-servicio
MAIL_PASSWORD=contrasena-de-aplicacion-del-servicio
MAIL_FROM=correo-del-servicio
```

Usar los valores reales del servicio de correo, no los ejemplos. El envio utiliza
la configuracion SMTP ya existente de SAGA con STARTTLS. Guardar las variables y
aplicarlas al contenedor mediante el despliegue correspondiente. APP_URL no se
obtiene del encabezado Host de una peticion.

4. Probar con una cuenta propia que tenga correo registrado. Comprobar recepcion,
cambio de clave, inicio de sesion y rechazo del mismo enlace al usarlo otra vez.

No importar el volcado completo de la base ni reemplazar usuarios. El instalador
crea solamente las dos tablas de recuperacion si faltan y se puede repetir.
Los enlaces de recuperacion no deben compartirse ni publicarse en registros.
Las sesiones abiertas se invalidan en su siguiente acceso a la BD al cambiar la
clave, desactivar la cuenta o cambiar sus permisos. El despliegue de la firma de
sesion exige iniciar sesion de nuevo tambien a las sesiones antiguas.

## Limites y mantenimiento

Cada IP tiene un limite de 10 solicitudes por hora y cada identificador de 5.
Se permite un correo por minuto por cuenta. Se usa REMOTE_ADDR, nunca un encabezado
reenviado sin validacion. Si el proxy agrupa clientes bajo una misma IP, configurar
la IP real en Apache exclusivamente para los proxies de confianza del despliegue.

Como mantenimiento periodico pueden eliminarse registros antiguos:

```sql
DELETE FROM password_reset_limits WHERE inicio < DATE_SUB(NOW(), INTERVAL 1 DAY);
DELETE FROM password_resets WHERE expira_en < DATE_SUB(NOW(), INTERVAL 1 DAY);
```

## Pruebas locales

```sh
php tests/password_reset.php
```

Las pruebas usan tablas temporales y un envio simulado: no modifican usuarios
reales ni envian correos. Se requiere MySQL local y permisos para tablas temporales.
