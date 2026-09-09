<?php
/**
 * El admin APRUEBA o RECHAZA una solicitud de cambio de jornada del instructor.
 *   - Aprobar  -> la ocupación cambia de jornada y horario (mismo día). Solicitud 'aprobada'.
 *   - Rechazar -> la ocupación queda intacta. Solicitud 'rechazada'.
 * En ambos casos se notifica al instructor (y al vocero de la ficha si aplica).
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

$q = mysqli_query($con, "
    SELECT scj.ocupacion_id, scj.estado, scj.jornada_actual, scj.jornada_nueva, scj.instructor_id,
           ho.ambiente_id, ho.fecha_inicio, ho.ficha_id,
           a.nombre AS ambiente_nombre,
           ui.email AS instr_email, CONCAT_WS(' ', ui.nombre, ui.apellido) AS instr_nombre,
           f.codigo_ficha, v.id AS vocero_id, v.email AS vocero_email, CONCAT_WS(' ', v.nombre, v.apellido) AS vocero_nombre
    FROM solicitudes_cambio_jornada scj
    JOIN historial_ocupacion ho ON scj.ocupacion_id = ho.id
    LEFT JOIN ambientes a ON ho.ambiente_id = a.id
    LEFT JOIN usuarios ui ON scj.instructor_id = ui.id
    LEFT JOIN fichas f ON ho.ficha_id = f.id
    LEFT JOIN usuarios v ON f.vocero_id = v.id
    WHERE scj.id = $solicitud_id");
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
$ambiente_id   = intval($s['ambiente_id']);
$ambiente      = $s['ambiente_nombre'] ?: 'ambiente';
$jornada_nueva = $s['jornada_nueva'];
$jornada_actual= $s['jornada_actual'];
$fecha_txt     = !empty($s['fecha_inicio']) ? date('d/m/Y', strtotime($s['fecha_inicio'])) : '';
$respuesta_e   = mysqli_real_escape_string($con, $respuesta);

$horarios = ['mañana' => ['06:00:00','12:00:00'], 'tarde' => ['12:00:00','18:00:00'], 'noche' => ['18:00:00','22:00:00']];

if ($accion == 'aprobar') {
    if (!isset($horarios[$jornada_nueva])) {
        header("Location: {$destino}&error=jornada_invalida");
        exit();
    }
    $dia = date('Y-m-d', strtotime($s['fecha_inicio']));
    [$ini_h, $fin_h] = $horarios[$jornada_nueva];
    $nueva_ini = "$dia $ini_h";
    $nueva_fin = "$dia $fin_h";
    $ni_e = mysqli_real_escape_string($con, $nueva_ini);
    $nf_e = mysqli_real_escape_string($con, $nueva_fin);

    // Conflicto: ¿otra ocupación activa del mismo ambiente se solapa con la nueva jornada?
    $conf = mysqli_fetch_assoc(mysqli_query($con, "
        SELECT COUNT(*) AS total FROM historial_ocupacion
        WHERE ambiente_id = $ambiente_id
          AND id <> $ocupacion_id
          AND estado IN ('ocupado','proximo_a_desocupar')
          AND fecha_inicio < '$nf_e' AND fecha_fin > '$ni_e'"));
    if ($conf['total'] > 0) {
        header("Location: {$destino}&error=ambiente_ocupado");
        exit();
    }

    $jn_e = mysqli_real_escape_string($con, $jornada_nueva);
    mysqli_query($con, "UPDATE historial_ocupacion
                        SET jornada = '$jn_e', fecha_inicio = '$ni_e', fecha_fin = '$nf_e'
                        WHERE id = $ocupacion_id AND estado NOT IN ('finalizado','cancelado')");
    $nuevo_estado = 'aprobada';
} else {
    $nuevo_estado = 'rechazada';
}

mysqli_query($con, "UPDATE solicitudes_cambio_jornada
                    SET estado = '$nuevo_estado', respuesta = '$respuesta_e', resuelto_por = $admin_id, resuelto_en = NOW()
                    WHERE id = $solicitud_id");

// ── Notificar al instructor (campana + correo) ──
$detalle = trim("$ambiente" . ($fecha_txt ? " del $fecha_txt" : ''));
if ($accion == 'aprobar') {
    $noti_tipo    = 'cambio_jornada_aprobada';
    $noti_titulo  = 'Cambio de jornada aprobado';
    $noti_mensaje = "Tu solicitud de cambio de jornada de $detalle (de $jornada_actual a $jornada_nueva) fue APROBADA.";
} else {
    $noti_tipo    = 'cambio_jornada_rechazada';
    $noti_titulo  = 'Cambio de jornada rechazado';
    $noti_mensaje = "Tu solicitud de cambio de jornada de $detalle (de $jornada_actual a $jornada_nueva) fue RECHAZADA. Se mantiene $jornada_actual.";
}
if ($respuesta !== '') $noti_mensaje .= " Comentario del administrador: \"$respuesta\"";

$noti_titulo_e  = mysqli_real_escape_string($con, $noti_titulo);
$noti_mensaje_e = mysqli_real_escape_string($con, $noti_mensaje);
mysqli_query($con, "INSERT INTO notificaciones (usuario_id, tipo, titulo, mensaje, url)
                    VALUES ($instructor_id, '$noti_tipo', '$noti_titulo_e', '$noti_mensaje_e', 'mis_ocupaciones_instructor.php')");
if (!empty($s['instr_email'])) {
    enviarEmail($s['instr_email'], $s['instr_nombre'] ?: 'Instructor', "$noti_titulo - SAGA",
        emailCambioJornadaResuelta($s['instr_nombre'] ?? '', $ambiente, $fecha_txt, $jornada_actual, $jornada_nueva, $accion == 'aprobar', $respuesta));
}

// ── Si se aprobó y la ocupación tiene ficha con vocero, avisarle ──
if ($accion == 'aprobar' && !empty($s['vocero_id'])) {
    $v_id = intval($s['vocero_id']);
    $v_msg = "La clase" . ($s['codigo_ficha'] ? " de la ficha {$s['codigo_ficha']}" : '')
           . ($fecha_txt ? " del $fecha_txt" : '') . " cambió de jornada: ahora es en $jornada_nueva.";
    $v_titulo_e  = mysqli_real_escape_string($con, 'Cambio de jornada');
    $v_mensaje_e = mysqli_real_escape_string($con, $v_msg);
    mysqli_query($con, "INSERT INTO notificaciones (usuario_id, tipo, titulo, mensaje, url)
                        VALUES ($v_id, 'cambio_jornada_aprobada', '$v_titulo_e', '$v_mensaje_e', 'calendario_vocero.php')");
    if (!empty($s['vocero_email'])) {
        enviarEmail($s['vocero_email'], $s['vocero_nombre'] ?: 'Vocero/a', 'Cambio de jornada - SAGA',
            emailCambioJornadaVocero($s['vocero_nombre'] ?? '', $s['codigo_ficha'] ?? '', $ambiente, $fecha_txt, $jornada_nueva));
    }
}

header("Location: {$destino}&msg=cambio_$nuevo_estado");
exit();
