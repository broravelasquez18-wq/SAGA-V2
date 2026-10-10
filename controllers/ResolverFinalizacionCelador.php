<?php
/**
 * El CELADOR aprueba o rechaza la solicitud de finalización del vocero.
 *   - Aprobar  -> la ocupación pasa a 'finalizado' (libera el ambiente si aplica).
 *   - Rechazar -> la ocupación queda intacta.
 * En ambos casos se notifica al vocero (campana + SMS).
 */
session_start();
require_once "../config/conexion.php";
require_once "../config/csrf.php";
require_once "../config/sendgrid.php";
require_once "../config/estados.php";

if (!isset($_SESSION['id']) || $_SESSION['rol'] != 'celador') {
    header("Location: ../views/home.php");
    exit();
}
if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    header("Location: ../views/celador/dashboard_celador.php");
    exit();
}
csrf_validate();

$con = conexion();
mysqli_set_charset($con, 'utf8mb4');

$celador_id   = intval($_SESSION['id']);
$celador_sede = intval($_SESSION['sede_id'] ?? 0);
$solicitud_id = intval($_POST['solicitud_id'] ?? 0);
$accion       = $_POST['accion'] ?? '';

$destino = "../views/celador/dashboard_celador.php";

if ($solicitud_id <= 0 || !in_array($accion, ['aprobar', 'rechazar'])) {
    header("Location: $destino?error=datos_invalidos");
    exit();
}

// La solicitud debe estar pendiente y su ocupación debe ser de la sede del celador
$q = mysqli_query($con, "
    SELECT sf.id, sf.estado, sf.ocupacion_id, sf.vocero_id,
           ho.ambiente_id, ho.fecha_fin,
           a.nombre AS ambiente_nombre, p.sede_id, f.codigo_ficha,
           v.email AS vocero_email, CONCAT_WS(' ', v.nombre, v.apellido) AS vocero_nombre
    FROM solicitudes_finalizacion sf
    JOIN historial_ocupacion ho ON sf.ocupacion_id = ho.id
    JOIN ambientes a ON ho.ambiente_id = a.id
    JOIN pisos p     ON a.piso_id = p.id
    LEFT JOIN fichas f ON ho.ficha_id = f.id
    LEFT JOIN usuarios v ON sf.vocero_id = v.id
    WHERE sf.id = $solicitud_id
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
if (intval($s['sede_id']) !== $celador_sede) {
    header("Location: $destino?error=otra_sede");
    exit();
}

$ocupacion_id = intval($s['ocupacion_id']);
$ambiente_id  = intval($s['ambiente_id']);
$ambiente     = $s['ambiente_nombre'] ?? 'el ambiente';
$ficha_cod    = $s['codigo_ficha'] ?? '';
$vocero_id    = intval($s['vocero_id']);
$ahora = date('Y-m-d H:i:s');
$nuevo_estado = $accion === 'aprobar' ? 'aprobada' : 'rechazada';
$con->begin_transaction();
try {
    mysqli_query($con, "UPDATE solicitudes_finalizacion
        SET estado='$nuevo_estado', resuelto_por=$celador_id, resuelto_en=NOW()
        WHERE id=$solicitud_id AND estado='pendiente'");
    if (mysqli_affected_rows($con) !== 1) throw new RuntimeException('Solicitud ya resuelta');
    if ($accion === 'aprobar') {
        mysqli_query($con, "UPDATE historial_ocupacion SET estado='finalizado', fecha_fin='$ahora'
            WHERE id=$ocupacion_id AND estado IN ('ocupado','proximo_a_desocupar') AND fecha_inicio <= '$ahora'");
        if (mysqli_affected_rows($con) !== 1) throw new RuntimeException('Ocupacion no finalizable');
    }
    $con->commit();
} catch (Throwable $e) {
    $con->rollback();
    header("Location: $destino?error=ocupacion_no_finalizable");
    exit;
}
sincronizar_estados_ambientes($con);

if ($accion === 'aprobar') {
    // Liberar el ambiente si no quedan otras ocupaciones activas/futuras
    $otras = mysqli_fetch_assoc(mysqli_query($con,
        "SELECT COUNT(*) AS total FROM historial_ocupacion
         WHERE ambiente_id = $ambiente_id
           AND estado IN ('ocupado','proximo_a_desocupar')
           AND fecha_fin > NOW()"
    ));
    if ($otras['total'] == 0) {
        mysqli_query($con, "UPDATE ambientes SET estado = 'disponible' WHERE id = $ambiente_id");
    }

    $nuevo_estado = 'aprobada';
    $noti_tipo    = 'finalizacion_aprobada';
    $noti_titulo  = 'Finalización aprobada';
    $noti_mensaje = "El celador aprobó finalizar el ambiente \"$ambiente\""
                  . ($ficha_cod ? " (ficha $ficha_cod)" : '') . ". La ocupación quedó finalizada.";
    $sms_texto    = "SAGA: El celador aprobo finalizar el ambiente \"$ambiente\". Ocupacion finalizada.";
} else {
    $nuevo_estado = 'rechazada';
    $noti_tipo    = 'finalizacion_rechazada';
    $noti_titulo  = 'Finalización rechazada';
    $noti_mensaje = "El celador rechazó finalizar el ambiente \"$ambiente\""
                  . ($ficha_cod ? " (ficha $ficha_cod)" : '') . ". La ocupación se mantiene.";
    $sms_texto    = "SAGA: El celador rechazo finalizar el ambiente \"$ambiente\". La ocupacion se mantiene.";
}

// ── Notificar al vocero (campana + correo) ──
$titulo_e  = mysqli_real_escape_string($con, $noti_titulo);
$mensaje_e = mysqli_real_escape_string($con, $noti_mensaje);
mysqli_query($con, "INSERT INTO notificaciones (usuario_id, tipo, titulo, mensaje, url)
                    VALUES ($vocero_id, '$noti_tipo', '$titulo_e', '$mensaje_e', 'calendario_vocero.php')");

if (!empty($s['vocero_email'])) {
    enviarEmail($s['vocero_email'], $s['vocero_nombre'] ?: 'Vocero/a', "$noti_titulo - SAGA",
        emailFinalizacionResuelta($s['vocero_nombre'] ?? '', $ambiente, $ficha_cod, $nuevo_estado === 'aprobada'));
}

header("Location: $destino?msg=finalizacion_$nuevo_estado");
exit();
