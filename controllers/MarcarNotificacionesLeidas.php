<?php
/**
 * Marca notificaciones del usuario en sesión como leídas.
 *  - Sin 'id'  -> marca TODAS las no leídas del usuario.
 *  - Con 'id'  -> marca solo esa (validando que sea del usuario).
 * Responde JSON. Requiere POST + CSRF.
 */
session_start();
require_once "../config/conexion.php";
require_once "../config/csrf.php";

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'no_sesion']);
    exit();
}
if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'metodo']);
    exit();
}
csrf_validate();

$con = conexion();
mysqli_set_charset($con, 'utf8mb4');

$usuario_id = intval($_SESSION['id']);
$noti_id    = isset($_POST['id']) ? intval($_POST['id']) : 0;

if ($noti_id > 0) {
    mysqli_query($con, "UPDATE notificaciones SET leida = 1
                        WHERE id = $noti_id AND usuario_id = $usuario_id");
} else {
    mysqli_query($con, "UPDATE notificaciones SET leida = 1
                        WHERE usuario_id = $usuario_id AND leida = 0");
}

echo json_encode(['ok' => true]);
exit();
