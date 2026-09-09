<?php
/**
 * El VOCERO solicita ocupar AHORA el ambiente fijo de su ficha (cuando el
 * instructor se demora). No ocupa: crea una solicitud 'pendiente' que el
 * CELADOR o el ADMIN aprueban. Al aprobar se crea la ocupación real.
 */
session_start();
require_once "../config/conexion.php";
require_once "../config/csrf.php";
require_once "../config/sendgrid.php";

if (!isset($_SESSION['id']) || $_SESSION['rol'] != 'vocero') {
    header("Location: ../views/home.php");
    exit();
}
if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    header("Location: ../views/vocero/calendario_vocero.php");
    exit();
}
csrf_validate();

$con = conexion();
mysqli_set_charset($con, 'utf8mb4');

$vocero_id = intval($_SESSION['id']);
$motivo    = trim($_POST['motivo'] ?? '');
$fecha_in  = trim($_POST['fecha'] ?? '');   // YYYY-MM-DD del día elegido
$jornada   = trim($_POST['jornada'] ?? '');
$destino   = "../views/vocero/calendario_vocero.php";

// El motivo es obligatorio
if ($motivo === '') {
    header("Location: $destino?error=motivo_requerido");
    exit();
}

// La fecha debe ser válida y no estar en el pasado
$hoy = date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_in) || $fecha_in < $hoy) {
    header("Location: $destino?error=fecha_invalida");
    exit();
}

// La jornada debe ser válida
$horarios = ['mañana' => ['06:00:00','12:00:00'], 'tarde' => ['12:00:00','18:00:00'], 'noche' => ['18:00:00','22:00:00']];
if (!isset($horarios[$jornada])) {
    header("Location: $destino?error=jornada_invalida");
    exit();
}
[$ini_h, $fin_h] = $horarios[$jornada];

// Ficha del vocero + su ambiente fijo y sede
$fq = mysqli_query($con, "
    SELECT f.id AS ficha_id, f.codigo_ficha, f.ambiente_id,
           a.nombre AS ambiente_nombre, p.sede_id,
           CONCAT_WS(' ', v.nombre, v.apellido) AS vocero_nombre
    FROM fichas f
    JOIN usuarios v ON f.vocero_id = v.id
    LEFT JOIN ambientes a ON f.ambiente_id = a.id
    LEFT JOIN pisos p ON a.piso_id = p.id
    WHERE f.vocero_id = $vocero_id AND f.activa = 1
    LIMIT 1
");
if (!$fq || mysqli_num_rows($fq) == 0) {
    header("Location: $destino?error=sin_ficha");
    exit();
}
$f = mysqli_fetch_assoc($fq);

if (empty($f['ambiente_id'])) {
    header("Location: $destino?error=ficha_sin_ambiente");
    exit();
}

// Calcular el horario de la ocupación solicitada
if ($fecha_in === $hoy) {
    // Si la jornada de hoy ya terminó, no se puede
    if (strtotime("$hoy $fin_h") <= time()) {
        header("Location: $destino?error=jornada_pasada");
        exit();
    }
    // Empieza ahora si la jornada ya inició; si no, en su hora de inicio
    $fecha_inicio = (time() > strtotime("$hoy $ini_h")) ? date('Y-m-d H:i:s') : "$hoy $ini_h";
    $fecha_fin    = "$hoy $fin_h";
} else {
    $fecha_inicio = "$fecha_in $ini_h";
    $fecha_fin    = "$fecha_in $fin_h";
}

$ficha_id    = intval($f['ficha_id']);
$ambiente_id = intval($f['ambiente_id']);
$sede_id     = intval($f['sede_id']);

// Evitar solicitudes duplicadas pendientes para la ficha en ese día
$fecha_e = mysqli_real_escape_string($con, $fecha_in);
$dup = mysqli_query($con, "SELECT id FROM solicitudes_ocupacion
                           WHERE ficha_id = $ficha_id AND estado = 'pendiente'
                             AND DATE(fecha_inicio) = '$fecha_e'");
if ($dup && mysqli_num_rows($dup) > 0) {
    header("Location: $destino?msg=solicitud_ya_existe");
    exit();
}

$jornada_e  = mysqli_real_escape_string($con, $jornada);
$motivo_sql = "'" . mysqli_real_escape_string($con, $motivo) . "'";

$ins = mysqli_query($con, "
    INSERT INTO solicitudes_ocupacion (ficha_id, vocero_id, ambiente_id, jornada, fecha_inicio, fecha_fin, motivo)
    VALUES ($ficha_id, $vocero_id, $ambiente_id, '$jornada_e', '$fecha_inicio', '$fecha_fin', $motivo_sql)
");
if (!$ins) {
    header("Location: $destino?error=error_sistema");
    exit();
}

// ── Notificar: celadores de la sede y admins (campana + correo) ──
$ambiente   = $f['ambiente_nombre'] ?? 'un ambiente';
$ficha_cod  = $f['codigo_ficha'] ?? '';
$voc_nombre = $f['vocero_nombre'] ?: 'El vocero';
$hi = date('H:i', strtotime($fecha_inicio));
$hf = date('H:i', strtotime($fecha_fin));

$noti_titulo  = 'Solicitud de ocupación';
$noti_mensaje = "$voc_nombre solicita ocupar el ambiente \"$ambiente\""
              . ($ficha_cod ? " (ficha $ficha_cod)" : '') . " en jornada $jornada. Apruébalo si procede.";
$titulo_e  = mysqli_real_escape_string($con, $noti_titulo);
$mensaje_e = mysqli_real_escape_string($con, $noti_mensaje);
$email_subject = 'Nueva solicitud de ocupación - SAGA';

// Celadores activos de la sede (campana + correo)
$cels = mysqli_query($con, "SELECT id, email, CONCAT_WS(' ', nombre, apellido) AS nombre FROM usuarios
                            WHERE rol = 'celador' AND estado = 'activo' AND sede_id = $sede_id");
if ($cels) {
    while ($c = mysqli_fetch_assoc($cels)) {
        $cid = intval($c['id']);
        mysqli_query($con, "INSERT INTO notificaciones (usuario_id, tipo, titulo, mensaje, url)
                            VALUES ($cid, 'ocupacion_solicitud', '$titulo_e', '$mensaje_e', 'dashboard_celador.php')");
        if (!empty($c['email'])) {
            enviarEmail($c['email'], $c['nombre'] ?: 'Celador', $email_subject,
                emailSolicitudOcupacion($c['nombre'] ?: 'Celador', $voc_nombre, $ficha_cod, $ambiente, $jornada, $hi, $hf, $motivo));
        }
    }
}
// Admins (campana + correo)
$admins = mysqli_query($con, "SELECT id, email, CONCAT_WS(' ', nombre, apellido) AS nombre FROM usuarios
                              WHERE rol = 'admin' AND estado = 'activo'");
if ($admins) {
    while ($ad = mysqli_fetch_assoc($admins)) {
        $aid = intval($ad['id']);
        mysqli_query($con, "INSERT INTO notificaciones (usuario_id, tipo, titulo, mensaje, url)
                            VALUES ($aid, 'ocupacion_solicitud', '$titulo_e', '$mensaje_e', 'solicitudes_ocupacion_admin.php')");
        if (!empty($ad['email'])) {
            enviarEmail($ad['email'], $ad['nombre'] ?: 'Administrador', $email_subject,
                emailSolicitudOcupacion($ad['nombre'] ?: 'Administrador', $voc_nombre, $ficha_cod, $ambiente, $jornada, $hi, $hf, $motivo));
        }
    }
}

header("Location: $destino?msg=solicitud_ocupacion_enviada");
exit();
