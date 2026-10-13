<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once "../config/conexion.php";
require_once "../config/sendgrid.php";
require_once "../config/csrf.php";
require_once "../config/estados.php";

$con = conexion();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Esta accion requiere POST.');
}
csrf_validate();

// Verificar sesión activa
if(!isset($_SESSION['id'])) {
    header("Location: ../views/home.php");
    exit();
}

// ⭐ DETECTAR ROL DEL USUARIO
$rol = $_SESSION['rol'] ?? '';

// Obtener ID de la ocupación
$ocupacion_id = intval($_POST['id'] ?? 0);

// ⭐ OBTENER SEDE_ID
$sede_id = intval($_POST['sede_id'] ?? $_SESSION['sede_id'] ?? 0);
exigir_sede($sede_id);

// ⭐ NUEVO: Detectar si viene del calendario
$return = $_POST['return'] ?? 'ocupaciones';
$mes = intval($_POST['mes'] ?? date('n'));
$anio = intval($_POST['anio'] ?? date('Y'));

// ⭐ DEFINIR RUTA DE REDIRECCIÓN SEGÚN ROL Y ORIGEN
$ruta_base = '';
if($rol == 'admin') {
    if($return == 'calendario') {
        $ruta_base = "../views/admin/calendario_ocupaciones.php";
    } else {
        $ruta_base = "../views/admin/ocupaciones_admin.php";
    }
} elseif($rol == 'celador') {
    $ruta_base = "../views/celador/ocupaciones_celador.php";
} else {
    header("Location: ../views/home.php");
    exit();
}

if($sede_id <= 0 || empty($ocupacion_id)) {
    // ⭐ Redirigir con parámetros de calendario si aplica
    if($return == 'calendario' && $rol == 'admin') {
        header("Location: $ruta_base?sede_id=$sede_id&error=datos_invalidos&mes=$mes&anio=$anio");
    } else {
        header("Location: $ruta_base?sede_id=$sede_id&error=datos_invalidos");
    }
    exit();
}

// Obtener datos de la ocupación, instructor y ambiente para el SMS
$get_query = "SELECT ho.ambiente_id, ho.fecha_inicio, ho.fecha_fin, ho.usuario_id, ho.jornada, ho.ficha_id,
              a.nombre AS ambiente_nombre,
              u.nombre AS instructor_nombre, u.apellido AS instructor_apellido,
              u.telefono AS instructor_telefono, u.email AS instructor_email
              FROM historial_ocupacion ho
              LEFT JOIN ambientes a ON ho.ambiente_id = a.id
              LEFT JOIN pisos p ON a.piso_id = p.id
              LEFT JOIN usuarios u ON ho.usuario_id = u.id
              WHERE ho.id = $ocupacion_id AND p.sede_id = $sede_id";
              
$result = mysqli_query($con, $get_query);

if(mysqli_num_rows($result) == 0) {
    // ⭐ Redirigir con parámetros de calendario si aplica
    if($return == 'calendario' && $rol == 'admin') {
        header("Location: $ruta_base?sede_id=$sede_id&error=ocupacion_no_encontrada&mes=$mes&anio=$anio");
    } else {
        header("Location: $ruta_base?sede_id=$sede_id&error=ocupacion_no_encontrada");
    }
    exit();
}

$ocupacion = mysqli_fetch_assoc($result);
$ambiente_id          = $ocupacion['ambiente_id'];
$fecha_inicio         = $ocupacion['fecha_inicio'];
$fecha_fin_programada = $ocupacion['fecha_fin'];
$instructor_telefono = $ocupacion['instructor_telefono'] ?? '';
$instructor_email    = $ocupacion['instructor_email']    ?? '';
$ambiente_nombre     = $ocupacion['ambiente_nombre']     ?? '';
$instructor_nombre_s = $ocupacion['instructor_nombre']   ?? '';
$instructor_apellido_s = $ocupacion['instructor_apellido'] ?? '';
$instructor_nombre   = trim("$instructor_nombre_s $instructor_apellido_s");

// Si la clase YA empezó se FINALIZA (se cierra ahora); si aún NO empieza se
// CANCELA (no ocurrió). Así el admin/celador puede liberar el ambiente ante
// una emergencia del instructor, aunque la clase sea más tarde ese día.
$ahora = date('Y-m-d H:i:s');
$ya_inicio = strtotime($fecha_inicio) <= time();
$nuevo_estado = $ya_inicio ? 'finalizado' : 'cancelado';
$nueva_fecha_fin = $ya_inicio ? $ahora : $fecha_fin_programada;

$query = "UPDATE historial_ocupacion
          SET estado = '$nuevo_estado',
              fecha_fin = '$nueva_fecha_fin'
          WHERE id = $ocupacion_id
            AND estado IN ('ocupado', 'proximo_a_desocupar')";

if(mysqli_query($con, $query) && mysqli_affected_rows($con) === 1) {
    sincronizar_estados_ambientes($con);
    // Verificar si hay otras ocupaciones activas o futuras en este ambiente
    $check_otras = "SELECT COUNT(*) as total
                    FROM historial_ocupacion
                    WHERE ambiente_id = $ambiente_id
                    AND estado IN ('ocupado', 'proximo_a_desocupar')
                    AND fecha_fin > NOW()";
    
    $result_otras = mysqli_query($con, $check_otras);
    $otras = mysqli_fetch_assoc($result_otras);
    
    // Si no hay otras ocupaciones activas hoy, marcar ambiente como disponible
    if($otras['total'] == 0) {
        $update_ambiente = "UPDATE ambientes SET estado = 'disponible' WHERE id = $ambiente_id";
        mysqli_query($con, $update_ambiente);
    }
    
    // Notificar al instructor/vocero por email
    if(!empty($instructor_email)) {
        if ($ya_inicio) {
            enviarEmail($instructor_email, $instructor_nombre, 'Ocupación finalizada - SAGA',
                emailOcupacionFinalizada($instructor_nombre_s, $instructor_apellido_s, $ambiente_nombre, $nueva_fecha_fin));
        } else {
            enviarEmail($instructor_email, $instructor_nombre, 'Ocupación cancelada - SAGA',
                emailOcupacionCancelada($instructor_nombre_s, $instructor_apellido_s, $ambiente_nombre, $fecha_inicio, (string)($ocupacion['jornada'] ?? '')));
        }
    }

    // Si se eliminó, cerrar las solicitudes pendientes sobre esa clase para que
    // no queden colgando en el panel del admin.
    if (!$ya_inicio) {
        $resolvio = intval($_SESSION['id']);
        mysqli_query($con, "UPDATE solicitudes_cancelacion
                            SET estado = 'aprobada', resuelto_por = $resolvio, resuelto_en = NOW(),
                                respuesta = 'La ocupación fue eliminada directamente por el administrador.'
                            WHERE ocupacion_id = $ocupacion_id AND estado = 'pendiente'");
        mysqli_query($con, "UPDATE solicitudes_cambio_jornada
                            SET estado = 'rechazada', resuelto_por = $resolvio, resuelto_en = NOW(),
                                respuesta = 'La ocupación fue eliminada.'
                            WHERE ocupacion_id = $ocupacion_id AND estado = 'pendiente'");
    }

    // Si se canceló una clase de una ficha, avisar a su vocero/a (campana)
    if (!$ya_inicio && !empty($ocupacion['ficha_id'])) {
        $fid = intval($ocupacion['ficha_id']);
        $vq = mysqli_query($con, "SELECT vocero_id, codigo_ficha FROM fichas WHERE id = $fid AND vocero_id IS NOT NULL");
        if ($vq && ($vr = mysqli_fetch_assoc($vq))) {
            $vid  = intval($vr['vocero_id']);
            $vmsg = mysqli_real_escape_string($con, "La clase de la ficha {$vr['codigo_ficha']} del "
                  . date('d/m/Y H:i', strtotime($fecha_inicio)) . " en $ambiente_nombre fue cancelada.");
            mysqli_query($con, "INSERT INTO notificaciones (usuario_id, tipo, titulo, mensaje, url)
                                VALUES ($vid, 'clase_cancelada', 'Clase cancelada', '$vmsg', 'calendario_vocero.php')");
        }
    }

    // ⭐ REDIRIGIR SEGÚN ROL Y ORIGEN
    $msg_ok = $ya_inicio ? 'ocupacion_finalizada' : 'ocupacion_cancelada';
    if($return == 'calendario' && $rol == 'admin') {
        header("Location: $ruta_base?sede_id=$sede_id&msg=$msg_ok&mes=$mes&anio=$anio");
    } else {
        header("Location: $ruta_base?sede_id=$sede_id&msg=$msg_ok");
    }
} else {
    // ⭐ Error al finalizar
    if($return == 'calendario' && $rol == 'admin') {
        header("Location: $ruta_base?sede_id=$sede_id&error=finalizar_fallido&mes=$mes&anio=$anio");
    } else {
        header("Location: $ruta_base?sede_id=$sede_id&error=finalizar_fallido");
    }
}

mysqli_close($con);
?>
