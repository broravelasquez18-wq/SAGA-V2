<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../config/conexion.php';
require __DIR__ . '/../config/password_reset.php';
if (!in_array(getenv('DB_HOST'), ['localhost', '127.0.0.1', '::1'], true)) {
    exit("Ejecutar solo contra MySQL local.\n");
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = conexion();
$db->set_charset('utf8mb4');
// Las tablas temporales ocultan las reales solo durante esta conexion.
$col = 'contrase' . chr(195) . chr(177) . 'a';
$db->query("CREATE TEMPORARY TABLE usuarios (id INT PRIMARY KEY, nombre VARCHAR(100), cedula VARCHAR(50), email VARCHAR(150), estado VARCHAR(20), `$col` VARCHAR(255)) ENGINE=InnoDB");
foreach (explode(';', file_get_contents(__DIR__ . '/../sql/migracion_recuperacion_password.sql')) as $sql) {
    if (trim($sql) !== '') $db->query(str_replace('CREATE TABLE IF NOT EXISTS', 'CREATE TEMPORARY TABLE', $sql));
}
$db->query("INSERT INTO usuarios VALUES (1, 'Prueba', 'test', 'prueba@example.test', 'activo', 'original')");
putenv('APP_URL=https://saga.example.test');
$enviados = [];
$correo = function ($u, $url) use (&$enviados) { $enviados[] = $url; return true; };
function comprobar(bool $ok, string $mensaje): void {
    if (!$ok) throw new RuntimeException('FALLO: ' . $mensaje);
    echo 'OK: ' . $mensaje . PHP_EOL;
}
function reiniciar_limites(mysqli $db): void { $db->query('DELETE FROM password_reset_limits'); }
recuperacion_solicitar($db, 'test', 'otro@example.test', '127.0.0.1', $correo);
comprobar(count($enviados) === 0, 'Correo incorrecto no emite enlace');
recuperacion_solicitar($db, 'test', 'prueba@example.test', '127.0.0.1', $correo);
parse_str(parse_url($enviados[0], PHP_URL_QUERY), $query);
$token = $query['token'];
$row = $db->query('SELECT * FROM password_resets')->fetch_assoc();
comprobar($row['token_hash'] === hash('sha256', $token), 'Solo se almacena el hash del token');
comprobar(recuperacion_usuario($db, $token) === 1, 'Enlace valido reconoce cuenta');
comprobar(recuperacion_usuario($db, str_repeat('0', 64)) === null, 'Token incorrecto rechazado');
recuperacion_solicitar($db, 'test', 'prueba@example.test', '127.0.0.1', $correo);
comprobar(count($enviados) === 1, 'Reenvio inmediato limitado');
comprobar(!recuperacion_cambiar($db, $token, 'corta'), 'Clave demasiado corta rechazada');
comprobar(!recuperacion_cambiar($db, $token, str_repeat(chr(195) . chr(177), 5)), 'Minimo cuenta caracteres y no bytes');
comprobar(!recuperacion_cambiar($db, $token, str_repeat('a', 73)), 'Clave que excede bcrypt rechazada');
$db->query('UPDATE password_resets SET expira_en=DATE_SUB(NOW(), INTERVAL 1 MINUTE)');
comprobar(!recuperacion_cambiar($db, $token, 'ClaveDePrueba123'), 'Enlace vencido rechazado');
$db->query('UPDATE password_resets SET expira_en=DATE_ADD(NOW(), INTERVAL 30 MINUTE)');
$db->query('UPDATE usuarios SET estado="inactivo"');
comprobar(!recuperacion_cambiar($db, $token, 'ClaveDePrueba123'), 'Cuenta inactiva rechazada');
$db->query('UPDATE usuarios SET estado="activo", email="nuevo@example.test"');
comprobar(!recuperacion_cambiar($db, $token, 'ClaveDePrueba123'), 'Cambio de correo invalida enlace anterior');
$db->query('UPDATE usuarios SET email="prueba@example.test"');
comprobar(recuperacion_cambiar($db, $token, 'ClaveDePrueba123'), 'Cambio de clave valido');
$hash = $db->query("SELECT `$col` FROM usuarios WHERE id=1")->fetch_row()[0];
comprobar(password_verify('ClaveDePrueba123', $hash), 'Nueva clave guardada con hash verificable');
comprobar(!recuperacion_cambiar($db, $token, 'OtraClaveDePrueba123'), 'Enlace consumido no puede reutilizarse');
reiniciar_limites($db);
recuperacion_solicitar($db, 'test', 'prueba@example.test', '127.0.0.1', fn() => false);
comprobar((int)$db->query('SELECT COUNT(*) FROM password_resets')->fetch_row()[0] === 0, 'Fallo de correo revoca token');
reiniciar_limites($db);
recuperacion_solicitar($db, 'test', 'prueba@example.test', '127.0.0.1', $correo);
parse_str(parse_url(end($enviados), PHP_URL_QUERY), $primero);
$db->query('UPDATE password_resets SET creado_en=DATE_SUB(NOW(), INTERVAL 2 MINUTE)');
recuperacion_solicitar($db, 'test', 'prueba@example.test', '127.0.0.1', $correo);
parse_str(parse_url(end($enviados), PHP_URL_QUERY), $segundo);
comprobar(recuperacion_usuario($db, $primero['token']) === null && recuperacion_usuario($db, $segundo['token']) === 1, 'Nuevo enlace invalida el anterior');
reiniciar_limites($db);
for ($i=0; $i<5; $i++) comprobar(recuperacion_limite($db, 'prueba', 5), 'Solicitud permitida ' . ($i+1));
comprobar(!recuperacion_limite($db, 'prueba', 5), 'Limite horario persistente');
$db->query('UPDATE password_reset_limits SET inicio=DATE_SUB(NOW(), INTERVAL 2 HOUR)');
comprobar(recuperacion_limite($db, 'prueba', 5), 'Ventana horaria se renueva');
putenv('APP_URL=http://malicioso.example');
try { recuperacion_url(); $rechazado=false; } catch (RuntimeException $e) { $rechazado=true; }
comprobar($rechazado, 'Enlaces externos requieren HTTPS');
echo "Pruebas completadas sin modificar cuentas reales ni enviar correos.\n";
