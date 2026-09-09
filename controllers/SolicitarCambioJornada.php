<?php
/**
 * El instructor SOLICITA cambiar la jornada de una ocupación futura (no la cambia).
 * Crea una solicitud 'pendiente' que el admin debe aprobar o rechazar.
 */
session_start();
require_once "../config/conexion.php";
require_once "../config/csrf.php";
require_once "../config/sendgrid.php";

if (!isset($_SESSION['id']) || $_SESSION['rol'] != 'instructor') {
    header("Location: ../views/home.php");
    exit();
}
if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    header("Location: ../views/instructor/mis_ocupaciones_instructor.php");
    exit();
}
csrf_validate();

$con = conexion();
mysqli_set_charset($con, 'utf8mb4');

$instructor_id = intval($_SESSION['id']);
$ocupacion_id  = intval($_POST['id'] ?? 0);
$jornada_nueva = trim($_POST['jornada_nueva'] ?? '');
$motivo        = trim($_POST['motivo'] ?? '');
$origen = $_POST['origen'] ?? '';
$destino = match($origen) {
    'dashboard'  => "../views/instructor/dashboard_instructor.php",
    'calendario' => "../views/instructor/calendario_instructor.php",
    default      => "../views/instructor/mis_ocupaciones_instructor.php",
};

$jornadas_validas = ['mañana', 'tarde', 'noche'];

if ($ocupacion_id <= 0 || $motivo === '') {
    header("Location: $destino?error=cambio_motivo");
    exit();
}
if (!in_array($jornada_nueva, $jornadas_validas, true)) {
    header("Location: $destino?error=jornada_invalida");
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
    header("Location: $destino?error=no_cambiable");
    exit();
}
if (in_array($o['estado'], ['finalizado', 'cancelado'])) {
    header("Location: $destino?error=no_cambiable");
    exit();
}
if (strcasecmp($o['jornada'], $jornada_nueva) === 0) {
    header("Location: $destino?error=misma_jornada");
    exit();
}

// Evitar solicitudes de cambio duplicadas pendientes para la misma ocupación
$dup = mysqli_query($con, "SELECT id FROM solicitudes_cambio_jornada
                           WHERE ocupacion_id = $ocupacion_id AND estado = 'pendiente'");
if ($dup && mysqli_num_rows($dup) > 0) {
    header("Location: $destino?msg=solicitud_ya_existe");
    exit();
}

$jornada_actual_e = mysqli_real_escape_string($con, $o['jornada']);
$jornada_nueva_e  = mysqli_real_escape_string($con, $jornada_nueva);
$motivo_e         = mysqli_real_escape_string($con, $motivo);

$ins = mysqli_query($con, "INSERT INTO solicitudes_cambio_jornada
        (ocupacion_id, instructor_id, jornada_actual, jornada_nueva, motivo)
        VALUES ($ocupacion_id, $instructor_id, '$jornada_actual_e', '$jornada_nueva_e', '$motivo_e')");

if ($ins) {
    // ── Notificar a los administradores (campana + correo) ──
    $sol_instructor = $o['instructor_nombre'] ?: 'Un instructor';
    $sol_ambiente   = $o['ambiente_nombre']   ?: 'un ambiente';
    $sol_fecha      = !empty($o['fecha_inicio']) ? date('d/m/Y', strtotime($o['fecha_inicio'])) : '';
    $sol_sede_id    = intval($o['sede_id'] ?? 0);

    $mensaje = "$sol_instructor solicita cambiar la jornada de $sol_ambiente"
             . ($sol_fecha ? " del $sol_fecha" : '')
             . " de {$o['jornada']} a $jornada_nueva. Motivo: $motivo";
    if (mb_strlen($mensaje) > 480) $mensaje = mb_substr($mensaje, 0, 477) . '...';

    $titulo_e  = mysqli_real_escape_string($con, 'Nueva solicitud de cambio de jornada');
    $mensaje_e = mysqli_real_escape_string($con, $mensaje);
    $url_e     = mysqli_real_escape_string($con, "solicitudes_admin.php?sede_id=$sol_sede_id");

    $admins = mysqli_query($con, "SELECT id, email, CONCAT_WS(' ', nombre, apellido) AS nombre
                                  FROM usuarios WHERE rol = 'admin' AND estado = 'activo'");
    if ($admins) {
        while ($ad = mysqli_fetch_assoc($admins)) {
            $admin_id = intval($ad['id']);
            mysqli_query($con, "INSERT INTO notificaciones (usuario_id, tipo, titulo, mensaje, url)
                                VALUES ($admin_id, 'cambio_jornada_nueva', '$titulo_e', '$mensaje_e', '$url_e')");
            if (!empty($ad['email'])) {
                enviarEmail($ad['email'], $ad['nombre'] ?: 'Administrador', 'Solicitud de cambio de jornada - SAGA',
                    emailSolicitudCambioJornada($ad['nombre'] ?: 'Administrador', $sol_instructor, $sol_ambiente,
                        $o['fecha_inicio'] ?? date('Y-m-d'), $o['jornada'], $jornada_nueva, $motivo));
            }
        }
    }
}

header("Location: $destino?" . ($ins ? "msg=cambio_enviado" : "error=error_sistema"));
exit();
