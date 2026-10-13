<?php
/**
 * Devuelve (JSON) las clases de HOY de un instructor en la sede del celador.
 * Lo usa el modal "Nueva Ocupación": al identificar al instructor (carnet o
 * buscador), si ya tiene clase programada se ofrece "Registrar llegada" en vez
 * de crear una ocupación nueva (que chocaría con la existente).
 *
 * GET: usuario_id
 */
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/llegada.php';

$rol = $_SESSION['rol'] ?? '';
if (!isset($_SESSION['id']) || !in_array($rol, ['celador', 'admin'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false]);
    exit();
}

$con = conexion();
mysqli_set_charset($con, 'utf8mb4');

$usuario_id = intval($_GET['usuario_id'] ?? 0);
$sede_id    = $rol === 'celador' ? intval($_SESSION['sede_id'] ?? 0) : intval($_GET['sede_id'] ?? 0);
$hoy        = date('Y-m-d');

$clases = [];
if ($usuario_id > 0 && $sede_id > 0) {
    $q = mysqli_query($con, "
        SELECT ho.id, ho.fecha_inicio, ho.fecha_fin, ho.estado, ho.llegada_en, ho.origen,
               u.rol AS usuario_rol, a.nombre AS ambiente, p.nombre AS piso, f.codigo_ficha
        FROM historial_ocupacion ho
        JOIN usuarios u  ON u.id = ho.usuario_id
        JOIN ambientes a ON a.id = ho.ambiente_id
        JOIN pisos p     ON p.id = a.piso_id
        LEFT JOIN fichas f ON f.id = ho.ficha_id
        WHERE ho.usuario_id = $usuario_id AND p.sede_id = $sede_id
          AND DATE(ho.fecha_inicio) = '$hoy'
          AND ho.estado IN ('ocupado','proximo_a_desocupar')
        ORDER BY ho.fecha_inicio");
    while ($q && ($o = mysqli_fetch_assoc($q))) {
        $estado = llegada_estado($o);
        $clases[] = [
            'id'          => intval($o['id']),
            'ambiente'    => $o['piso'] . ' - ' . $o['ambiente'],
            'horario'     => date('H:i', strtotime($o['fecha_inicio'])) . ' - ' . date('H:i', strtotime($o['fecha_fin'])),
            'ficha'       => $o['codigo_ficha'],
            'sincronizada'=> $o['origen'] === 'sync',
            'llegada'     => $estado ? ['clase' => $estado[0], 'texto' => $estado[1]] : null,
            'registrable' => llegada_registrable($o),
        ];
    }
}

echo json_encode(['ok' => true, 'clases' => $clases]);
