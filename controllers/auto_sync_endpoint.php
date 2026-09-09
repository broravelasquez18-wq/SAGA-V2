<?php
/**
 * Endpoint de auto-sincronización en segundo plano.
 * Lo llama la propia página (fetch) al cargarse. Está limitado por frecuencia
 * (no re-sincroniza si ya se hizo hace poco), así que es seguro llamarlo en
 * cada carga. Devuelve JSON con lo que hizo.
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['id']) || $_SESSION['rol'] != 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'no_autorizado']);
    exit();
}

require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../cron/lib/auto_sync.php';

$sede_id = isset($_GET['sede_id']) ? intval($_GET['sede_id']) : 0;
$maxEdad = isset($_GET['max']) ? max(30, intval($_GET['max'])) : 600;

$con = conexion();
mysqli_set_charset($con, 'utf8mb4');

$res = auto_sync_sede($con, $sede_id, $maxEdad);

$cambios = 0;
foreach ($res as $v) { if (is_int($v) && $v > 0) $cambios += $v; }

echo json_encode([
    'ok'       => true,
    'fichas'   => $res,
    'cambios'  => $cambios,   // >0 si trajo/actualizó ocupaciones
]);
mysqli_close($con);
