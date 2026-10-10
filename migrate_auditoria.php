<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/config/conexion.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = conexion();
$apply = in_array('--apply', $argv, true);
if ($apply) {
    // Serializa el arranque de replicas para evitar carreras en ALTER TABLE.
    $lock = 'saga-migrate-' . substr(hash('sha256', getenv('DB_NAME')), 0, 48);
    $s = $db->prepare('SELECT GET_LOCK(?, 60)');
    $s->bind_param('s', $lock);
    $s->execute();
    if ((int)$s->get_result()->fetch_row()[0] !== 1) {
        throw new RuntimeException('Otra instancia esta migrando la base. Reintentar el despliegue.');
    }
}
$column = 'contrase' . chr(195) . chr(177) . 'a';
$legacy = [];
foreach ($db->query("SELECT id, `$column` AS clave FROM usuarios") as $row) {
    if (password_get_info($row['clave'])['algoName'] === 'unknown') {
        if (strlen($row['clave']) > 72 || str_starts_with($row['clave'], '$') || str_contains($row['clave'], "\0")) {
            throw new RuntimeException('Hay credenciales no migrables automaticamente. Revisar antes de continuar; no se modificaron.');
        }
        $legacy[] = $row;
    }
}
echo 'Base: ' . $db->query('SELECT DATABASE()')->fetch_row()[0] . PHP_EOL;
echo 'Cuentas legacy por proteger: ' . count($legacy) . PHP_EOL;
if (!$apply) exit("Simulacion. Respalda la BD y ejecuta con --apply para instalar tablas/adjunto y proteger claves legacy.\n");

// DDL idempotente, sin USE ni DROP: siempre sobre la base configurada.
foreach (['migracion_solicitudes.sql','migracion_notificaciones.sql','migracion_recuperacion_password.sql'] as $file) {
    $sql = preg_replace('/^USE\s+`?saga_v2`?\s*;/mi', '', file_get_contents(__DIR__ . '/sql/' . $file));
    $sql = preg_replace('/^\s*--[^\r\n]*/m', '', $sql);
    foreach (explode(';', $sql) as $query) {
        if (trim($query) !== '') $db->query($query);
    }
}
if (!$db->query("SHOW COLUMNS FROM solicitudes_cancelacion LIKE 'adjunto'")->num_rows) {
    $db->query('ALTER TABLE solicitudes_cancelacion ADD COLUMN adjunto VARCHAR(255) NULL AFTER motivo');
}
$db->begin_transaction();
try {
    $s = $db->prepare("UPDATE usuarios SET `$column`=? WHERE id=? AND BINARY `$column`=BINARY ?");
    foreach ($legacy as $row) {
        $hash = password_hash($row['clave'], PASSWORD_DEFAULT);
        $s->bind_param('sis', $hash, $row['id'], $row['clave']);
        $s->execute();
        if ($s->affected_rows !== 1) throw new RuntimeException('Una cuenta cambio durante la migracion. Reintentar.');
    }
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}
echo "Migracion terminada. Las claves originales se conservan mediante hashes; las sesiones previas deben renovarse.\n";
$db->close(); // Libera tambien el bloqueo de migracion.
