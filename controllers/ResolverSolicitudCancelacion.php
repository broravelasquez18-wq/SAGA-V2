<?php
/**
 * El admin APRUEBA o RECHAZA una solicitud de cancelación del instructor.
 *   - Aprobar  -> la ocupación pasa a estado 'cancelado' y la solicitud a 'aprobada'.
 *   - Rechazar -> la ocupación queda intacta y la solicitud pasa a 'rechazada'.
 */
session_start();
require_once "../config/conexion.php";
require_once "../config/csrf.php";
require_once "../config/sendgrid.php";

if (!isset($_SESSION['id']) || $_SESSION['rol'] != 'admin') {
    header("Location: ../views/home.php");
    exit();
}
if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    header("Location: ../views/admin/index_sedes.php");
    exit();
}
csrf_validate();

$con = conexion();
mysqli_set_charset($con, 'utf8mb4');

$admin_id     = intval($_SESSION['id']);
$solicitud_id = intval($_POST['solicitud_id'] ?? 0);
$accion       = $_POST['accion'] ?? '';
$sede_id      = intval($_POST['sede_id'] ?? 0);
$respuesta    = trim($_POST['respuesta'] ?? '');

$destino = "../views/admin/solicitudes_admin.php?sede_id=$sede_id";

if ($solicitud_id <= 0 || !in_array($accion, ['aprobar', 'rechazar'])) {
    header("Location: {$destino}&error=datos_invalidos");
    exit();
}

$q = mysqli_query($con, "SELECT sc.ocupacion_id, sc.estado, sc.instructor_id,
                                a.nombre AS ambiente_nombre, ho.fecha_inicio, ho.jornada
                         FROM solicitudes_cancelacion sc
                         JOIN historial_ocupacion ho ON sc.ocupacion_id = ho.id
                         LEFT JOIN ambientes a ON ho.ambiente_id = a.id
                         WHERE sc.id = $solicitud_id");
if (!$q || mysqli_num_rows($q) == 0) {
    header("Location: {$destino}&error=no_encontrada");
    exit();
}
$s = mysqli_fetch_assoc($q);
if ($s['estado'] != 'pendiente') {
    header("Location: {$destino}&error=ya_resuelta");
    exit();
}
$ocupacion_id  = intval($s['ocupacion_id']);
$instructor_id = intval($s['instructor_id']);
$respuesta_e   = mysqli_real_escape_string($con, $respuesta);

// Datos para el texto de la notificación
$sol_ambiente = $s['ambiente_nombre'] ?? 'ambiente';
$sol_fecha    = !empty($s['fecha_inicio']) ? date('d/m/Y', strtotime($s['fecha_inicio'])) : '';
$sol_jornada  = $s['jornada'] ?? '';

if ($accion == 'aprobar') {
    // Solo cancelar si la ocupación aún no está finalizada
    mysqli_query($con, "UPDATE historial_ocupacion SET estado = 'cancelado'
                        WHERE id = $ocupacion_id AND estado NOT IN ('finalizado', 'cancelado')");
    $nuevo_estado = 'aprobada';

    // Avisar al vocero/a de la ficha (si la ocupación pertenece a una ficha con vocero asignado)
    $vq = mysqli_query($con, "
        SELECT v.id AS vocero_id, f.codigo_ficha, a.nombre AS ambiente, ho.fecha_inicio, ho.jornada,
               CONCAT_WS(' ', vi.nombre, vi.apellido) AS instructor_nombre,
               v.email AS vocero_email, CONCAT_WS(' ', v.nombre, v.apellido) AS vocero_nombre
        FROM historial_ocupacion ho
        LEFT JOIN ambientes a  ON ho.ambiente_id = a.id
        LEFT JOIN usuarios  vi ON ho.usuario_id  = vi.id
        LEFT JOIN fichas    f  ON ho.ficha_id     = f.id
        LEFT JOIN usuarios  v  ON f.vocero_id     = v.id
        WHERE ho.id = $ocupacion_id");
    if ($vq && ($vd = mysqli_fetch_assoc($vq)) && !empty($vd['vocero_id'])) {
        $v_fecha_txt = !empty($vd['fecha_inicio']) ? date('d/m/Y', strtotime($vd['fecha_inicio'])) : $sol_fecha;
        $v_ficha     = $vd['codigo_ficha']     ?: '';
        $v_ambiente  = $vd['ambiente']         ?: '';
        $v_jornada   = $vd['jornada']          ?: '';
        $v_instr     = $vd['instructor_nombre'] ?: 'El instructor';

        // Notificación in-app (campana)
        $v_titulo  = "No hay clase el $v_fecha_txt";
        $v_mensaje = "El $v_fecha_txt no hay clase" . ($v_ficha ? " de la ficha $v_ficha" : '')
                   . ($v_ambiente ? " en $v_ambiente" : '') . ($v_jornada ? " ($v_jornada)" : '')
                   . ": $v_instr canceló la ocupación.";
        $v_titulo_e  = mysqli_real_escape_string($con, $v_titulo);
        $v_mensaje_e = mysqli_real_escape_string($con, $v_mensaje);
        $v_id        = intval($vd['vocero_id']);

        // URL al día exacto en el calendario del vocero (resalta y hace scroll a ese día)
        if (!empty($vd['fecha_inicio'])) {
            $ts    = strtotime($vd['fecha_inicio']);
            $v_url = 'calendario_vocero.php?mes=' . date('n', $ts) . '&anio=' . date('Y', $ts) . '&dia=' . date('j', $ts);
        } else {
            $v_url = 'calendario_vocero.php';
        }
        $v_url_e = mysqli_real_escape_string($con, $v_url);

        mysqli_query($con, "INSERT INTO notificaciones (usuario_id, tipo, titulo, mensaje, url)
                            VALUES ($v_id, 'clase_cancelada', '$v_titulo_e', '$v_mensaje_e', '$v_url_e')");

        // Correo (si el vocero tiene email)
        if (!empty($vd['vocero_email'])) {
            enviarEmail(
                $vd['vocero_email'],
                $vd['vocero_nombre'] ?: 'Vocero/a',
                'Clase cancelada - SAGA',
                emailCancelacionVocero($vd['vocero_nombre'], $vd['codigo_ficha'] ?? '', $vd['ambiente'] ?? '',
                                       $vd['fecha_inicio'] ?? date('Y-m-d'), $vd['jornada'] ?? '', $vd['instructor_nombre'])
            );
        }
    }
} else {
    $nuevo_estado = 'rechazada';
}

mysqli_query($con, "UPDATE solicitudes_cancelacion
                    SET estado = '$nuevo_estado', respuesta = '$respuesta_e',
                        resuelto_por = $admin_id, resuelto_en = NOW()
                    WHERE id = $solicitud_id");

// ── Notificación in-app para el instructor ──
$detalle = trim("$sol_ambiente" . ($sol_fecha ? " del $sol_fecha" : '') . ($sol_jornada ? " ($sol_jornada)" : ''));
if ($accion == 'aprobar') {
    $noti_tipo    = 'solicitud_aprobada';
    $noti_titulo  = 'Cancelación aprobada';
    $noti_mensaje = "Tu solicitud de cancelación de $detalle fue APROBADA. La ocupación quedó cancelada.";
} else {
    $noti_tipo    = 'solicitud_rechazada';
    $noti_titulo  = 'Cancelación rechazada';
    $noti_mensaje = "Tu solicitud de cancelación de $detalle fue RECHAZADA. La ocupación se mantiene.";
}
if ($respuesta !== '') {
    $noti_mensaje .= " Comentario del administrador: \"$respuesta\"";
}
$noti_titulo_e  = mysqli_real_escape_string($con, $noti_titulo);
$noti_mensaje_e = mysqli_real_escape_string($con, $noti_mensaje);
$noti_url       = 'mis_ocupaciones_instructor.php';
mysqli_query($con, "INSERT INTO notificaciones (usuario_id, tipo, titulo, mensaje, url)
                    VALUES ($instructor_id, '$noti_tipo', '$noti_titulo_e', '$noti_mensaje_e', '$noti_url')");

header("Location: {$destino}&msg=solicitud_$nuevo_estado");
exit();
