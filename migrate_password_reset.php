<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/config/conexion.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = conexion();
$db->set_charset('utf8mb4');
foreach (explode(';', file_get_contents(__DIR__ . '/sql/migracion_recuperacion_password.sql')) as $sql) {
    if (trim($sql) !== '') $db->query($sql);
}
echo "Recuperacion de contrasenas instalada.\n";
