<?php
/**
 * El instructor SOLICITA la cancelación de una ocupación futura (no la cancela).
 * Crea una solicitud 'pendiente' que el admin debe aprobar o rechazar.
 */
session_start();
require_once "../config/conexion.php";
require_once "../config/csrf.php";

if (!isset($_SESSION['id']) || $_SESSION['rol'] != 'instructor') {
    header("Location: ../views/home.php");
    exit();
}
if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    header("Location: ../views/instructor/dashboard_instructor.php");
    exit();
}
csrf_validate();

$con = conexion();
mysqli_set_charset($con, 'utf8mb4');

$instructor_id = intval($_SESSION['id']);
$ocupacion_id  = intval($_POST['id'] ?? 0);
$motivo        = trim($_POST['motivo'] ?? '');
$origen        = ($_POST['origen'] ?? '') === 'mis_ocupaciones' ? 'mis_ocupaciones' : 'dashboard';
$destino = $origen === 'mis_ocupaciones'
    ? "../views/instructor/mis_ocupaciones_instructor.php"
    : "../views/instructor/dashboard_instructor.php";

if ($ocupacion_id <= 0 || $motivo === '') {
    header("Location: $destino?error=motivo_requerido");
    exit();
}

// La ocupación debe ser del instructor, futura y no finalizada/cancelada
$q = mysqli_query($con, "SELECT ho.fecha_inicio, ho.estado, ho.jornada,
                                a.nombre AS ambiente_nombre, p.sede_id,
                                CONCAT_WS(' ', u.nombre, u.apellido) AS instructor_nombre
                         FROM historial_ocupacion ho
                         LEFT JOIN ambientes a ON ho.ambiente_id = a.id
                         LEFT JOIN pisos p ON a.piso_id = p.id
                         LEFT JOIN usuarios u ON ho.usuario_id = u.id
                         WHERE ho.id = $ocupacion_id AND ho.usuario_id = $instructor_id");
if (!$q || mysqli_num_rows($q) == 0) {
    header("Location: $destino?error=no_encontrada");
    exit();
}
$o = mysqli_fetch_assoc($q);
if (date('Y-m-d', strtotime($o['fecha_inicio'])) <= date('Y-m-d')) {
    header("Location: $destino?error=no_cancelable");
    exit();
}
if (in_array($o['estado'], ['finalizado', 'cancelado'])) {
    header("Location: $destino?error=no_cancelable");
    exit();
}

// Evitar solicitudes duplicadas pendientes para la misma ocupación
$dup = mysqli_query($con, "SELECT id FROM solicitudes_cancelacion
                           WHERE ocupacion_id = $ocupacion_id AND estado = 'pendiente'");
if ($dup && mysqli_num_rows($dup) > 0) {
    header("Location: $destino?msg=solicitud_ya_existe");
    exit();
}

// ── Adjunto opcional (imagen o PDF, máx 5 MB) ──
$adjunto_nombre = null;
if (isset($_FILES['adjunto']) && $_FILES['adjunto']['error'] !== UPLOAD_ERR_NO_FILE) {
    $f = $_FILES['adjunto'];

    if ($f['error'] !== UPLOAD_ERR_OK) {
        header("Location: $destino?error=adjunto_error");
        exit();
    }
    if ($f['size'] > 5 * 1024 * 1024) {
        header("Location: $destino?error=adjunto_grande");
        exit();
    }

    // Validar tipo real por contenido, no solo por extensión
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $f['tmp_name']);
    finfo_close($finfo);

    $permitidos = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];
    if (!isset($permitidos[$mime])) {
        header("Location: $destino?error=adjunto_tipo");
        exit();
    }

    $ext = $permitidos[$mime];
    $dir = __DIR__ . '/../uploads/solicitudes/';
    $adjunto_nombre = 'sol_' . $instructor_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;

    if (!move_uploaded_file($f['tmp_name'], $dir . $adjunto_nombre)) {
        header("Location: $destino?error=error_sistema");
        exit();
    }
}

$motivo_e   = mysqli_real_escape_string($con, $motivo);
$adjunto_sql = $adjunto_nombre === null ? 'NULL' : "'" . mysqli_real_escape_string($con, $adjunto_nombre) . "'";
$ins = mysqli_query($con, "INSERT INTO solicitudes_cancelacion (ocupacion_id, instructor_id, motivo, adjunto)
                           VALUES ($ocupacion_id, $instructor_id, '$motivo_e', $adjunto_sql)");

// Si el INSERT falla y ya se subió un archivo, eliminarlo para no dejar huérfanos
if (!$ins && $adjunto_nombre !== null) {
    @unlink(__DIR__ . '/../uploads/solicitudes/' . $adjunto_nombre);
}

// ── Notificar in-app a los administradores ──
if ($ins) {
    $sol_instructor = $o['instructor_nombre'] ?: 'Un instructor';
    $sol_ambiente   = $o['ambiente_nombre']   ?: 'un ambiente';
    $sol_fecha      = !empty($o['fecha_inicio']) ? date('d/m/Y', strtotime($o['fecha_inicio'])) : '';
    $sol_jornada    = $o['jornada'] ?? '';
    $sol_sede_id    = intval($o['sede_id'] ?? 0);

    $detalle = trim("$sol_ambiente" . ($sol_fecha ? " del $sol_fecha" : '') . ($sol_jornada ? " ($sol_jornada)" : ''));
    $mensaje = "$sol_instructor solicitó cancelar $detalle. Motivo: $motivo";
    if (mb_strlen($mensaje) > 480) $mensaje = mb_substr($mensaje, 0, 477) . '...';

    $titulo_e  = mysqli_real_escape_string($con, 'Nueva solicitud de cancelación');
    $mensaje_e = mysqli_real_escape_string($con, $mensaje);
    $url       = "solicitudes_admin.php?sede_id=$sol_sede_id";
    $url_e     = mysqli_real_escape_string($con, $url);

    $admins = mysqli_query($con, "SELECT id FROM usuarios WHERE rol = 'admin'");
    if ($admins) {
        while ($ad = mysqli_fetch_assoc($admins)) {
            $admin_id = intval($ad['id']);
            mysqli_query($con, "INSERT INTO notificaciones (usuario_id, tipo, titulo, mensaje, url)
                                VALUES ($admin_id, 'solicitud_nueva', '$titulo_e', '$mensaje_e', '$url_e')");
        }
    }
}

header("Location: $destino?" . ($ins ? "msg=solicitud_enviada" : "error=error_sistema"));
exit();
