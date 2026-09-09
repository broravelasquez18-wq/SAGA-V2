<?php
/**
 * Devuelve en JSON las notificaciones del usuario en sesión (para polling en vivo).
 * Solo lectura: no requiere CSRF, pero sí sesión válida.
 */
session_start();
require_once "../config/conexion.php";
require_once "../config/notificaciones_helper.php";

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!isset($_SESSION['id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'no_sesion']);
    exit();
}

$con = conexion();
mysqli_set_charset($con, 'utf8mb4');

$uid = intval($_SESSION['id']);

$no_leidas = 0;
$rc = mysqli_query($con, "SELECT COUNT(*) AS t FROM notificaciones WHERE usuario_id = $uid AND leida = 0");
if ($rc && ($rr = mysqli_fetch_assoc($rc))) $no_leidas = (int)$rr['t'];

$items = [];
$rl = mysqli_query($con, "SELECT id, tipo, titulo, mensaje, url, leida, creado_en
                          FROM notificaciones
                          WHERE usuario_id = $uid
                          ORDER BY creado_en DESC
                          LIMIT 20");
if ($rl) {
    while ($n = mysqli_fetch_assoc($rl)) {
        [$icono, $clase] = camp_icono($n['tipo']);
        $items[] = [
            'id'      => (int)$n['id'],
            'titulo'  => $n['titulo'],
            'mensaje' => $n['mensaje'],
            'url'     => $n['url'] ?? '',
            'leida'   => (int)$n['leida'],
            'tiempo'  => camp_tiempo($n['creado_en']),
            'icono'   => $icono,
            'clase'   => $clase,
        ];
    }
}

echo json_encode(['ok' => true, 'no_leidas' => $no_leidas, 'items' => $items]);
exit();
