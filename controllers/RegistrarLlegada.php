<?php
/**
 * El CELADOR (o el admin) registra que el instructor llegó a su clase.
 * No crea una ocupación: marca llegada_en / llegada_por en la que ya existe
 * (sincronizada desde Google Sheets o registrada manualmente).
 *
 * POST: csrf_token, id (ocupación), origen (dashboard|ocupaciones|calendario),
 *       sede_id (admin), ajax=1 para responder JSON (lo usa el modal del escáner).
 */
session_start();
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/llegada.php';

$rol  = $_SESSION['rol'] ?? '';
$ajax = ($_POST['ajax'] ?? '') === '1';

function responder(bool $ok, string $codigo, string $destino, bool $ajax, array $extra = []): void
{
    if ($ajax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $ok, 'codigo' => $codigo] + $extra);
    } else {
        $sep = strpos($destino, '?') === false ? '?' : '&';
        header("Location: $destino{$sep}" . ($ok ? 'msg=' : 'error=') . $codigo);
    }
    exit();
}

if (!isset($_SESSION['id']) || !in_array($rol, ['celador', 'admin'], true)) {
    if ($ajax) { http_response_code(403); }
    responder(false, 'no_autorizado', '../views/home.php', $ajax);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Esta acción requiere POST.');
}
csrf_validate();

$con = conexion();
mysqli_set_charset($con, 'utf8mb4');

$ocupacion_id = intval($_POST['id'] ?? 0);
$origen       = $_POST['origen'] ?? 'dashboard';
$sede_id      = $rol === 'celador' ? intval($_SESSION['sede_id'] ?? 0) : intval($_POST['sede_id'] ?? 0);

if ($rol === 'admin') {
    $destino = "../views/admin/ocupaciones_admin.php?sede_id=$sede_id";
} else {
    $destino = match ($origen) {
        'ocupaciones' => '../views/celador/ocupaciones_celador.php',
        'calendario'  => '../views/celador/calendario_celador.php',
        default       => '../views/celador/dashboard_celador.php',
    };
}

if ($ocupacion_id <= 0 || $sede_id <= 0) responder(false, 'llegada_invalida', $destino, $ajax);

$q = mysqli_query($con, "
    SELECT ho.id, ho.fecha_inicio, ho.fecha_fin, ho.estado, ho.llegada_en,
           u.rol AS usuario_rol, CONCAT_WS(' ', u.nombre, u.apellido) AS instructor
    FROM historial_ocupacion ho
    JOIN usuarios u  ON u.id = ho.usuario_id
    JOIN ambientes a ON a.id = ho.ambiente_id
    JOIN pisos p     ON p.id = a.piso_id
    WHERE ho.id = $ocupacion_id AND p.sede_id = $sede_id");
$o = $q ? mysqli_fetch_assoc($q) : null;

if (!$o) responder(false, 'llegada_invalida', $destino, $ajax);
if (!empty($o['llegada_en'])) responder(false, 'llegada_ya_registrada', $destino, $ajax);
if (!llegada_registrable($o)) responder(false, 'llegada_fuera_horario', $destino, $ajax);

$ahora = date('Y-m-d H:i:s');
$uid   = intval($_SESSION['id']);
mysqli_query($con, "UPDATE historial_ocupacion
                    SET llegada_en = '$ahora', llegada_por = $uid
                    WHERE id = $ocupacion_id AND llegada_en IS NULL");
if (mysqli_affected_rows($con) !== 1) responder(false, 'llegada_ya_registrada', $destino, $ajax);

$o['llegada_en'] = $ahora;
$estado = llegada_estado($o);
$tarde  = $estado && $estado[0] === 'tarde';
responder(true, $tarde ? 'llegada_tarde' : 'llegada_ok', $destino, $ajax, [
    'texto'      => $estado ? $estado[1] : 'Llegada registrada',
    'instructor' => $o['instructor'],
]);
