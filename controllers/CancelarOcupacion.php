<?php
session_start();
require_once "../config/conexion.php";
require_once "../config/csrf.php";
require_once "../config/sendgrid.php";

$con = conexion();

// ⭐ DETECTAR ROL DEL USUARIO
$rol = $_SESSION['rol'] ?? '';
$usuario_id = $_SESSION['id'] ?? 0;

// Los instructores YA NO cancelan directamente: deben pasar por el flujo de
// solicitud de cancelación (SolicitarCancelacionOcupacion.php -> aprobación del admin).
if ($rol == 'instructor') {
    header("Location: ../views/instructor/mis_ocupaciones_instructor.php?error=usar_solicitud");
    exit();
}

// Instructor/vocero usan POST+CSRF; admin/celador usan GET
if($rol == 'instructor' || $rol == 'vocero') {
    if($_SERVER['REQUEST_METHOD'] != 'POST') {
        header("Location: ../views/home.php");
        exit();
    }
    csrf_validate();
}

// Obtener ID de la ocupación
$ocupacion_id = ($rol == 'instructor' || $rol == 'vocero')
    ? intval($_POST['id'] ?? 0)
    : (isset($_GET['id']) ? intval($_GET['id']) : 0);

// ⭐ DEFINIR RUTA DE REDIRECCIÓN SEGÚN ROL
$ruta_error = '';
$ruta_exito = '';

if($rol == 'admin') {
    $sede_id = isset($_GET['sede_id']) ? intval($_GET['sede_id']) : 0;
    $ruta_error = "../views/admin/ocupaciones_admin.php?sede_id=$sede_id&";
    $ruta_exito = "../views/admin/ocupaciones_admin.php?sede_id=$sede_id&msg=ocupacion_finalizada";
} elseif($rol == 'celador') {
    $ruta_error = "../views/celador/ocupaciones_celador.php?";
    $ruta_exito = "../views/celador/ocupaciones_celador.php?msg=ocupacion_finalizada";
} elseif($rol == 'instructor') {
    $ruta_error = "../views/instructor/dashboard_instructor.php?";
    $ruta_exito = "../views/instructor/dashboard_instructor.php?msg=cancelado";
} elseif($rol == 'vocero') {
    $ruta_error = "../views/vocero/calendario_vocero.php?";
    $ruta_exito = "../views/vocero/calendario_vocero.php?msg=cancelado";
} else {
    header("Location: ../views/home.php");
    exit();
}

if(empty($ocupacion_id)) {
    header("Location: {$ruta_error}error=datos_invalidos");
    exit();
}

// PARA INSTRUCTOR/VOCERO: validar que sea su ocupación y que sea futura
if($rol == 'instructor' || $rol == 'vocero') {
    $hoy = date('Y-m-d');
    
    // Obtener ocupación y validar
    $query = "SELECT * FROM historial_ocupacion WHERE id = $ocupacion_id AND usuario_id = $usuario_id";
    $result = mysqli_query($con, $query);
    
    if(mysqli_num_rows($result) == 0) {
        header("Location: {$ruta_error}error=no_encontrada");
        exit();
    }
    
    $ocupacion = mysqli_fetch_assoc($result);
    $fecha_ocupacion = date('Y-m-d', strtotime($ocupacion['fecha_inicio']));
    
    // Solo puede cancelar ocupaciones FUTURAS (no hoy ni pasadas)
    if($fecha_ocupacion <= $hoy) {
        header("Location: {$ruta_error}error=no_cancelable");
        exit();
    }
    
    // No cancelar ocupaciones ya finalizadas
    if($ocupacion['estado'] == 'finalizado') {
        header("Location: {$ruta_error}error=ya_finalizada");
        exit();
    }
    
    // Eliminar la ocupación
    $delete_query = "DELETE FROM historial_ocupacion WHERE id = $ocupacion_id";
    
    if(mysqli_query($con, $delete_query)) {
        header("Location: $ruta_exito");
    } else {
        header("Location: {$ruta_error}error=error_sistema");
    }
    
} else {
    // PARA ADMIN Y CELADOR: finalizar ocupación
    $sede_id = isset($_GET['sede_id']) ? intval($_GET['sede_id']) : 0;

    // Obtener datos de la ocupación incluyendo info del usuario para el email
    $get_query = "SELECT ho.ambiente_id, ho.fecha_inicio, ho.fecha_fin, ho.jornada,
                  a.nombre AS ambiente_nombre,
                  u.nombre AS usuario_nombre, u.apellido AS usuario_apellido, u.email AS usuario_email
                  FROM historial_ocupacion ho
                  LEFT JOIN ambientes a ON ho.ambiente_id = a.id
                  LEFT JOIN usuarios u ON ho.usuario_id = u.id";

    if($rol == 'admin' && $sede_id > 0) {
        $get_query .= " LEFT JOIN pisos p ON a.piso_id = p.id
                        WHERE ho.id = $ocupacion_id AND p.sede_id = $sede_id";
    } else {
        $get_query .= " WHERE ho.id = $ocupacion_id";
    }
    
    $result = mysqli_query($con, $get_query);
    
    if(mysqli_num_rows($result) == 0) {
        header("Location: {$ruta_error}error=ocupacion_no_encontrada");
        exit();
    }
    
    $ocupacion = mysqli_fetch_assoc($result);
    $ambiente_id          = $ocupacion['ambiente_id'];
    $fecha_fin_programada = $ocupacion['fecha_fin'];
    $cancel_nombre        = $ocupacion['usuario_nombre']   ?? '';
    $cancel_apellido      = $ocupacion['usuario_apellido'] ?? '';
    $cancel_email         = $ocupacion['usuario_email']    ?? '';
    $cancel_ambiente      = $ocupacion['ambiente_nombre']  ?? '';
    $cancel_fecha         = $ocupacion['fecha_inicio']     ?? '';
    $cancel_jornada       = $ocupacion['jornada']          ?? '';
    
    // Calcular fecha_fin correcta
    $ahora = date('Y-m-d H:i:s');
    $nueva_fecha_fin = (strtotime($ahora) > strtotime($fecha_fin_programada)) ? $ahora : $fecha_fin_programada;
    
    // Finalizar la ocupación
    $query = "UPDATE historial_ocupacion 
              SET estado = 'finalizado', 
                  fecha_fin = '$nueva_fecha_fin' 
              WHERE id = $ocupacion_id";
    
    if(mysqli_query($con, $query)) {
        // Verificar si hay otras ocupaciones activas en este ambiente HOY
        $check_otras = "SELECT COUNT(*) as total 
                        FROM historial_ocupacion 
                        WHERE ambiente_id = $ambiente_id 
                        AND DATE(fecha_inicio) = CURDATE()
                        AND estado IN ('ocupado', 'proximo_a_desocupar')";
        
        $result_otras = mysqli_query($con, $check_otras);
        $otras = mysqli_fetch_assoc($result_otras);
        
        // Si no hay otras ocupaciones activas hoy, marcar ambiente como disponible
        if($otras['total'] == 0) {
            $update_ambiente = "UPDATE ambientes SET estado = 'disponible' WHERE id = $ambiente_id";
            mysqli_query($con, $update_ambiente);
        }
        
        if(!empty($cancel_email)) {
            enviarEmail(
                $cancel_email,
                "$cancel_nombre $cancel_apellido",
                'Ocupación cancelada - SAGA',
                emailOcupacionCancelada($cancel_nombre, $cancel_apellido, $cancel_ambiente, $cancel_fecha, $cancel_jornada)
            );
        }
        header("Location: $ruta_exito");
    } else {
        header("Location: {$ruta_error}error=finalizar_fallido");
    }
}

mysqli_close($con);
?>