<?php
/**
 * El VOCERO solicita finalizar la ocupación de hoy de su ficha.
 * No la finaliza: crea una solicitud 'pendiente' que el CELADOR (presente) aprueba.
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

$vocero_id    = intval($_SESSION['id']);
$ocupacion_id = intval($_POST['id'] ?? 0);
$destino = ($_POST['origen'] ?? '') === 'mis_ocupaciones'
    ? "../views/vocero/mis_ocupaciones_vocero.php"
    : "../views/vocero/calendario_vocero.php";

if ($ocupacion_id <= 0) {
    header("Location: $destino?error=datos_invalidos");
    exit();
}

// La ocupación debe ser de hoy, de la ficha de este vocero, y estar en curso
$q = mysqli_query($con, "
    SELECT ho.id, p.sede_id, f.codigo_ficha,
           a.nombre AS ambiente_nombre,
           CONCAT_WS(' ', v.nombre, v.apellido) AS vocero_nombre
    FROM historial_ocupacion ho
    JOIN ambientes a ON ho.ambiente_id = a.id
    JOIN pisos p     ON a.piso_id = p.id
    JOIN fichas f    ON ho.ficha_id = f.id
    JOIN usuarios v  ON f.vocero_id = v.id
    WHERE ho.id = $ocupacion_id
      AND f.vocero_id = $vocero_id
      AND DATE(ho.fecha_inicio) = CURDATE()
      AND ho.estado IN ('ocupado','proximo_a_desocupar')
");
if (!$q || mysqli_num_rows($q) == 0) {
    header("Location: $destino?error=no_finalizable");
    exit();
}
$o = mysqli_fetch_assoc($q);

// Evitar solicitudes duplicadas pendientes
$dup = mysqli_query($con, "SELECT id FROM solicitudes_finalizacion
                           WHERE ocupacion_id = $ocupacion_id AND estado = 'pendiente'");
if ($dup && mysqli_num_rows($dup) > 0) {
    header("Location: $destino?msg=solicitud_ya_existe");
    exit();
}

$ins = mysqli_query($con, "INSERT INTO solicitudes_finalizacion (ocupacion_id, vocero_id)
                           VALUES ($ocupacion_id, $vocero_id)");
if (!$ins) {
    header("Location: $destino?error=error_sistema");
    exit();
}

// ── Notificar a los celadores activos de la sede (campana + SMS) ──
$sede_id     = intval($o['sede_id']);
$ambiente    = $o['ambiente_nombre'] ?? 'un ambiente';
$ficha_cod   = $o['codigo_ficha'] ?? '';
$voc_nombre  = $o['vocero_nombre'] ?: 'El vocero';

$noti_titulo  = 'Solicitud de finalización';
$noti_mensaje = "$voc_nombre solicita finalizar el ambiente \"$ambiente\""
              . ($ficha_cod ? " (ficha $ficha_cod)" : '') . ". Apruébalo si estás de acuerdo.";
$titulo_e  = mysqli_real_escape_string($con, $noti_titulo);
$mensaje_e = mysqli_real_escape_string($con, $noti_mensaje);
$email_subject = 'Solicitud de finalización - SAGA';

$cels = mysqli_query($con, "SELECT id, email, CONCAT_WS(' ', nombre, apellido) AS nombre FROM usuarios
                            WHERE rol = 'celador' AND estado = 'activo' AND sede_id = $sede_id");
if ($cels) {
    while ($c = mysqli_fetch_assoc($cels)) {
        $cid = intval($c['id']);
        mysqli_query($con, "INSERT INTO notificaciones (usuario_id, tipo, titulo, mensaje, url)
                            VALUES ($cid, 'finalizacion_solicitud', '$titulo_e', '$mensaje_e', 'dashboard_celador.php')");
        if (!empty($c['email'])) {
            enviarEmail($c['email'], $c['nombre'] ?: 'Celador', $email_subject,
                emailSolicitudFinalizacion($c['nombre'] ?: 'Celador', $voc_nombre, $ficha_cod, $ambiente));
        }
    }
}

header("Location: $destino?msg=solicitud_finalizacion_enviada");
exit();
