<?php
/**
 * El CELADOR o el ADMIN aprueban/rechazan la solicitud de ocupación del vocero.
 *   - Aprobar  -> se crea la ocupación (historial_ocupacion) y el ambiente pasa a 'ocupado'.
 *   - Rechazar -> no se crea nada.
 * En ambos casos se notifica al vocero (campana + SMS).
 */
session_start();
require_once "../config/conexion.php";
require_once "../config/csrf.php";
require_once "../config/sendgrid.php";

$rol = $_SESSION['rol'] ?? '';
if (!isset($_SESSION['id']) || !in_array($rol, ['celador', 'admin'])) {
    header("Location: ../views/home.php");
    exit();
}
if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    header("Location: ../views/home.php");
    exit();
}
csrf_validate();

$con = conexion();
mysqli_set_charset($con, 'utf8mb4');

$usuario_id   = intval($_SESSION['id']);
$solicitud_id = intval($_POST['solicitud_id'] ?? 0);
$accion       = $_POST['accion'] ?? '';

$destino = $rol === 'admin'
    ? "../views/admin/solicitudes_ocupacion_admin.php"
    : "../views/celador/dashboard_celador.php";

if ($solicitud_id <= 0 || !in_array($accion, ['aprobar', 'rechazar'])) {
    header("Location: $destino?error=datos_invalidos");
    exit();
}

$q = mysqli_query($con, "
    SELECT so.id, so.estado, so.ficha_id, so.vocero_id, so.ambiente_id,
           so.jornada, so.fecha_inicio, so.fecha_fin, so.motivo,
           a.nombre AS ambiente_nombre, p.sede_id, f.codigo_ficha,
           v.email AS vocero_email, CONCAT_WS(' ', v.nombre, v.apellido) AS vocero_nombre
    FROM solicitudes_ocupacion so
    JOIN ambientes a ON so.ambiente_id = a.id
    JOIN pisos p     ON a.piso_id = p.id
    LEFT JOIN fichas f ON so.ficha_id = f.id
    LEFT JOIN usuarios v ON so.vocero_id = v.id
    WHERE so.id = $solicitud_id
");
if (!$q || mysqli_num_rows($q) == 0) {
    header("Location: $destino?error=no_encontrada");
    exit();
}
$s = mysqli_fetch_assoc($q);

if ($s['estado'] !== 'pendiente') {
    header("Location: $destino?error=ya_resuelta");
    exit();
}
// El celador solo puede resolver solicitudes de su sede
if ($rol === 'celador' && intval($s['sede_id']) !== intval($_SESSION['sede_id'] ?? 0)) {
    header("Location: $destino?error=otra_sede");
    exit();
}

$ambiente_id = intval($s['ambiente_id']);
$ambiente    = $s['ambiente_nombre'] ?? 'el ambiente';
$ficha_cod   = $s['codigo_ficha'] ?? '';
$vocero_id   = intval($s['vocero_id']);
$ficha_id    = intval($s['ficha_id']);

if ($accion === 'aprobar') {
    $fi_e = mysqli_real_escape_string($con, $s['fecha_inicio']);
    $ff_e = mysqli_real_escape_string($con, $s['fecha_fin']);

    // Conflicto: ¿hay ya una ocupación activa que se solape en ese ambiente?
    $conf = mysqli_fetch_assoc(mysqli_query($con, "
        SELECT COUNT(*) AS total FROM historial_ocupacion
        WHERE ambiente_id = $ambiente_id
          AND estado IN ('ocupado','proximo_a_desocupar')
          AND fecha_inicio < '$ff_e' AND fecha_fin > '$fi_e'
    "));
    if ($conf['total'] > 0) {
        header("Location: $destino?error=ambiente_ocupado");
        exit();
    }

    $jornada_e = mysqli_real_escape_string($con, $s['jornada']);
    $obs_sql   = ($s['motivo'] === null || $s['motivo'] === '')
               ? 'NULL' : "'" . mysqli_real_escape_string($con, $s['motivo']) . "'";
    $ficha_sql = $ficha_id > 0 ? $ficha_id : 'NULL';

    $ins = mysqli_query($con, "
        INSERT INTO historial_ocupacion
            (ambiente_id, usuario_id, ficha_id, fecha_inicio, fecha_fin, estado, observaciones, jornada, origen)
        VALUES
            ($ambiente_id, $vocero_id, $ficha_sql, '$fi_e', '$ff_e', 'ocupado', $obs_sql, '$jornada_e', 'manual')
    ");
    if (!$ins) {
        header("Location: $destino?error=error_sistema");
        exit();
    }
    $ocupacion_id = intval(mysqli_insert_id($con));

    mysqli_query($con, "UPDATE ambientes SET estado = 'ocupado' WHERE id = $ambiente_id");

    mysqli_query($con, "UPDATE solicitudes_ocupacion
                        SET estado = 'aprobada', resuelto_por = $usuario_id, resuelto_en = NOW(), ocupacion_id = $ocupacion_id
                        WHERE id = $solicitud_id");

    $noti_tipo    = 'ocupacion_aprobada';
    $noti_titulo  = 'Ocupación aprobada';
    $noti_mensaje = "Tu solicitud para ocupar el ambiente \"$ambiente\""
                  . ($ficha_cod ? " (ficha $ficha_cod)" : '') . " fue APROBADA. Ya puedes usar el ambiente.";
    $sms_texto    = "SAGA: Tu solicitud para ocupar el ambiente \"$ambiente\" fue APROBADA. Ya puedes usarlo.";
    $nuevo_estado = 'aprobada';
} else {
    mysqli_query($con, "UPDATE solicitudes_ocupacion
                        SET estado = 'rechazada', resuelto_por = $usuario_id, resuelto_en = NOW()
                        WHERE id = $solicitud_id");

    $noti_tipo    = 'ocupacion_rechazada';
    $noti_titulo  = 'Ocupación rechazada';
    $noti_mensaje = "Tu solicitud para ocupar el ambiente \"$ambiente\""
                  . ($ficha_cod ? " (ficha $ficha_cod)" : '') . " fue RECHAZADA.";
    $sms_texto    = "SAGA: Tu solicitud para ocupar el ambiente \"$ambiente\" fue RECHAZADA.";
    $nuevo_estado = 'rechazada';
}

// ── Notificar al vocero (campana + correo) ──
$titulo_e  = mysqli_real_escape_string($con, $noti_titulo);
$mensaje_e = mysqli_real_escape_string($con, $noti_mensaje);
mysqli_query($con, "INSERT INTO notificaciones (usuario_id, tipo, titulo, mensaje, url)
                    VALUES ($vocero_id, '$noti_tipo', '$titulo_e', '$mensaje_e', 'calendario_vocero.php')");
if (!empty($s['vocero_email'])) {
    enviarEmail($s['vocero_email'], $s['vocero_nombre'] ?: 'Vocero/a', "$noti_titulo - SAGA",
        emailOcupacionResuelta($s['vocero_nombre'] ?? '', $ambiente, $ficha_cod, $nuevo_estado === 'aprobada'));
}

header("Location: $destino?msg=ocupacion_$nuevo_estado");
exit();
