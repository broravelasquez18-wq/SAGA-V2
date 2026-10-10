<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../config/conexion.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
if (!in_array(getenv('DB_HOST'), ['localhost','127.0.0.1','::1'], true)) exit('Solo local');
$source = conexion();
$source->set_charset('utf8mb4');
$audit = 'saga_audit_20261004';
if (($argv[1] ?? '') === '--reset') $source->query("DROP DATABASE IF EXISTS `$audit`");
$source->query("CREATE DATABASE `$audit` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$db = new mysqli(getenv('DB_HOST'), getenv('DB_USER'), getenv('DB_PASS'), $audit);
$db->set_charset('utf8mb4');
$db->query('SET FOREIGN_KEY_CHECKS=0');
foreach ($source->query('SHOW TABLES') as $r) {
    $table = array_values($r)[0];
    $schema = $source->query("SHOW CREATE TABLE `$table`")->fetch_row()[1];
    $db->query(preg_replace('/AUTO_INCREMENT=\d+/', 'AUTO_INCREMENT=1', $schema));
}
$db->query('SET FOREIGN_KEY_CHECKS=1');
$db->query("INSERT INTO sedes (id,nombre,ciudad) VALUES (1,'QA Sede Uno','Pruebas'),(2,'QA Sede Dos','Pruebas')");
$db->query("INSERT INTO pisos (id,sede_id,nombre) VALUES (1,1,'Piso QA Uno'),(2,2,'Piso QA Dos')");
$db->query("INSERT INTO ambientes (id,piso_id,nombre) VALUES (1,1,'QA Aula Uno'),(2,1,'QA Aula Dos'),(3,2,'QA Aula Otra Sede')");
$col='contrase'.chr(195).chr(177).'a';
$hash=password_hash('AuditOnly!2026',PASSWORD_DEFAULT);
foreach ([[1,'admin',1],[2,'instructor',1],[3,'celador',1],[4,'vocero',1],[5,'instructor',2],[6,'vocero',2],[7,'celador',2]] as [$id,$rol,$sede]) {
    $cedula='9900000'.$id;
    $email=$cedula.'@example.test';
    $stmt=$db->prepare("INSERT INTO usuarios (id,cedula,email,nombre,apellido,`$col`,rol,tipo_contrato,sede_id) VALUES (?,?,?,'QA','Pruebas',?,?,'planta',?)");
    $stmt->bind_param('issssi',$id,$cedula,$email,$hash,$rol,$sede);
    $stmt->execute();
}
$db->query("INSERT INTO fichas (id,codigo_ficha,programa,ambiente_id,sede_id,vocero_id,jornada) VALUES (1,'QA1001','Programa QA',1,1,4,'mañana'),(2,'QA2001','Programa QA Dos',3,2,6,'tarde')");
$db->query("INSERT INTO ficha_instructores (ficha_id,nombre_excel,usuario_id,cedula,origen) VALUES (1,'QA Pruebas',2,'qa2','manual'),(2,'QA Otro',5,'qa5','manual')");
echo "Base aislada creada: $audit; cuentas 99000001 a 99000007; sin datos personales copiados.\n";
