<?php
session_start();
require_once "../config/conexion.php";
require_once "../config/csrf.php";
require_once "../config/sendgrid.php";
require_once "../config/calendario_habil.php";

$con = conexion();

// Verificar método POST
if($_SERVER['REQUEST_METHOD'] != 'POST') {
    header("Location: ../views/home.php");
    exit();
}

csrf_validate();

// DETECTAR ROL DEL USUARIO
$rol = $_SESSION['rol'] ?? '';

// OBTENER SEDE_ID
$sede_id = isset($_POST['sede_id']) ? intval($_POST['sede_id']) : 0;
exigir_sede($sede_id);

if($sede_id <= 0) {
    header("Location: ../views/home.php");
    exit();
}

// Obtener datos del formulario
$ambiente_id = intval($_POST['ambiente_id']);
$usuario_id = intval($_POST['usuario_id']);
$jornada = mysqli_real_escape_string($con, $_POST['jornada']);
$observaciones = mysqli_real_escape_string($con, $_POST['observaciones']);

// ⭐ DETECTAR MODO: SIMPLE O MÚLTIPLE
$fechas_multiples = $_POST['fechas_multiples'] ?? '';
$modo_multiple = !empty($fechas_multiples);

// Determinar fechas a procesar
$fechas_a_registrar = [];
if($modo_multiple) {
    $fechas_a_registrar = explode(',', $fechas_multiples);
} else {
    $fecha_simple = mysqli_real_escape_string($con, $_POST['fecha'] ?? '');
    if(!empty($fecha_simple)) {
        $fechas_a_registrar[] = $fecha_simple;
    }
}

$return = $_POST['return'] ?? 'ocupaciones';

// DEFINIR RUTA DE REDIRECCIÓN
$ruta_base = '';
if($rol == 'admin') {
    if($return == 'calendario') {
        $ruta_base = "../views/admin/calendario_ocupaciones.php";
    } else {
        $ruta_base = "../views/admin/ocupaciones_admin.php";
    }
} elseif($rol == 'celador') {
    if($return == 'calendario') {
        $ruta_base = "../views/celador/calendario_celador.php";
    } else {
        $ruta_base = "../views/celador/ocupaciones_celador.php";
    }
} else {
    header("Location: ../views/home.php");
    exit();
}

// Validar campos obligatorios
if(empty($ambiente_id) || empty($usuario_id) || empty($jornada) || empty($fechas_a_registrar)) {
    header("Location: $ruta_base?sede_id=$sede_id&error=campos_vacios");
    exit();
}

// VALIDAR QUE EL AMBIENTE PERTENEZCA A LA SEDE
$check_ambiente = mysqli_query($con, "SELECT a.id FROM ambientes a 
                                      LEFT JOIN pisos p ON a.piso_id = p.id 
                                      WHERE a.id = $ambiente_id AND p.sede_id = $sede_id");

if(mysqli_num_rows($check_ambiente) == 0) {
    header("Location: $ruta_base?sede_id=$sede_id&error=ambiente_invalido");
    exit();
}

// DETERMINAR HORARIOS SEGÚN JORNADA O PERSONALIZADO
$instructor = mysqli_query($con, "SELECT id FROM usuarios WHERE id=$usuario_id
    AND rol='instructor' AND estado='activo'
    AND (sede_id=$sede_id OR tipo_contrato='contratista')");
if (mysqli_num_rows($instructor) !== 1) {
    http_response_code(403);
    exit('Instructor no autorizado para esta sede.');
}

$hora_inicio = '';
$hora_fin = '';
$jornada_bd = '';

if($jornada == 'otro') {
    $hora_inicio = mysqli_real_escape_string($con, $_POST['hora_inicio'] ?? '');
    $hora_fin = mysqli_real_escape_string($con, $_POST['hora_fin'] ?? '');
    
    if(empty($hora_inicio) || empty($hora_fin)) {
        header("Location: $ruta_base?sede_id=$sede_id&error=campos_vacios");
        exit();
    }
    
    if($hora_fin <= $hora_inicio) {
        header("Location: $ruta_base?sede_id=$sede_id&error=hora_invalida");
        exit();
    }
    
    if($hora_inicio < '06:00' || $hora_inicio > '22:00' || $hora_fin < '06:00' || $hora_fin > '22:00') {
        header("Location: $ruta_base?sede_id=$sede_id&error=horario_fuera");
        exit();
    }
    
    $jornada_bd = 'personalizado';
} else {
    switch($jornada) {
        case 'mañana':
            $hora_inicio = '06:00';
            $hora_fin = '12:00';
            break;
        case 'tarde':
            $hora_inicio = '12:00';
            $hora_fin = '18:00';
            break;
        case 'noche':
            $hora_inicio = '18:00';
            $hora_fin = '22:00';
            break;
        default:
            header("Location: $ruta_base?sede_id=$sede_id&error=jornada_invalida");
            exit();
    }
    $jornada_bd = $jornada;
}

// ═══════════════════════════════════════════════════════════════
// PROCESAR CADA FECHA
// ═══════════════════════════════════════════════════════════════

$hoy = date('Y-m-d');
$registros_exitosos   = 0;
$registros_fallidos   = 0;
$jornada_pasada_count = 0;
$fecha_pasada_count   = 0;
$dia_no_habil_count   = 0;
$primer_mes  = null;
$primer_anio = null;
$primer_dia  = null;

foreach($fechas_a_registrar as $fecha) {
    $fecha = trim($fecha);

    if($primer_mes === null) {
        $fecha_parts = explode('-', $fecha);
        $primer_anio = $fecha_parts[0];
        $primer_mes  = intval($fecha_parts[1]);
        $primer_dia  = intval($fecha_parts[2]);
    }

    // No registrar en domingos ni festivos (validación autoritativa del servidor)
    if(es_dia_no_habil($fecha)) {
        $registros_fallidos++;
        $dia_no_habil_count++;
        continue;
    }

    // Validar fecha no sea pasada
    if($fecha < $hoy) {
        $registros_fallidos++;
        $fecha_pasada_count++;
        continue;
    }

    // Validar que la jornada no haya terminado completamente si es HOY
    if($fecha == $hoy && $hora_fin <= date('H:i')) {
        $registros_fallidos++;
        $jornada_pasada_count++;
        continue;
    }

    $fecha_inicio     = $fecha . ' ' . $hora_inicio . ':00';
    $fecha_fin_dt_str = $fecha . ' ' . $hora_fin    . ':00';

    // VERIFICAR CONFLICTO DE AMBIENTE
    $check_ambiente_ocupado = "SELECT id FROM historial_ocupacion
                               WHERE ambiente_id = $ambiente_id
                               AND DATE(fecha_inicio) = '$fecha'
                               AND estado != 'finalizado'
                               AND estado != 'cancelado'
                               AND (fecha_inicio < '$fecha_fin_dt_str' AND fecha_fin > '$fecha_inicio')";
    if(mysqli_num_rows(mysqli_query($con, $check_ambiente_ocupado)) > 0) {
        $registros_fallidos++;
        continue;
    }

    // VERIFICAR CONFLICTO DE INSTRUCTOR
    $check_instructor_ocupado = "SELECT id FROM historial_ocupacion
                                 WHERE usuario_id = $usuario_id
                                 AND DATE(fecha_inicio) = '$fecha'
                                 AND estado != 'finalizado'
                                 AND estado != 'cancelado'
                                 AND (fecha_inicio < '$fecha_fin_dt_str' AND fecha_fin > '$fecha_inicio')";
    if(mysqli_num_rows(mysqli_query($con, $check_instructor_ocupado)) > 0) {
        $registros_fallidos++;
        continue;
    }

    // DETERMINAR ESTADO INICIAL
    $estado_inicial = 'ocupado';
    if($fecha == $hoy) {
        $ahora         = new DateTime();
        $hora_fin_obj  = new DateTime($fecha_fin_dt_str);
        $hora_ini_obj  = new DateTime($fecha_inicio);
        $hora_30_antes = clone $hora_fin_obj;
        $hora_30_antes->modify('-30 minutes');

        if($ahora < $hora_ini_obj)        $estado_inicial = 'ocupado';
        elseif($ahora >= $hora_fin_obj)   $estado_inicial = 'disponible';
        elseif($ahora >= $hora_30_antes)  $estado_inicial = 'proximo_a_desocupar';
        else                              $estado_inicial = 'ocupado';
    }

    // INSERTAR OCUPACIÓN
    $query = "INSERT INTO historial_ocupacion
              (ambiente_id, usuario_id, fecha_inicio, fecha_fin, estado, observaciones, jornada)
              VALUES
              ($ambiente_id, $usuario_id, '$fecha_inicio', '$fecha_fin_dt_str', '$estado_inicial', '$observaciones', '$jornada_bd')";

    if(mysqli_query($con, $query)) {
        $registros_exitosos++;
        // Si el CELADOR registra una clase que está por empezar o en curso, el
        // instructor está presente (lo identificó con su carnet): su llegada
        // queda registrada de una vez (ventana: desde 1 h antes hasta el fin).
        if ($rol === 'celador'
            && time() >= strtotime($fecha_inicio) - 3600
            && time() <  strtotime($fecha_fin_dt_str)) {
            $nuevo_id = intval(mysqli_insert_id($con));
            // Si llegó antes de la hora de inicio, cuenta la hora programada.
            $ahora_ll = date('Y-m-d H:i:s', max(time(), strtotime($fecha_inicio)));
            mysqli_query($con, "UPDATE historial_ocupacion SET llegada_en = '$ahora_ll', llegada_por = " . intval($_SESSION['id']) . " WHERE id = $nuevo_id");
        }
        if($estado_inicial == 'ocupado') {
            mysqli_query($con, "UPDATE ambientes SET estado='ocupado' WHERE id=$ambiente_id");
        }
    } else {
        $registros_fallidos++;
    }
}

// Notificar al usuario asignado si hubo registros exitosos
if($registros_exitosos > 0) {
    $uq = mysqli_query($con, "SELECT nombre, apellido, email FROM usuarios WHERE id = $usuario_id");
    $aq = mysqli_query($con, "SELECT nombre FROM ambientes WHERE id = $ambiente_id");
    if($ur = mysqli_fetch_assoc($uq)) {
        $amb_nombre = ($ar = mysqli_fetch_assoc($aq)) ? $ar['nombre'] : '';
        $primera_fecha = sprintf('%04d-%02d-%02d', $primer_anio, $primer_mes, $primer_dia);
        enviarEmail(
            $ur['email'],
            $ur['nombre'] . ' ' . $ur['apellido'],
            'Nueva ocupación registrada - SAGA',
            emailNuevaOcupacion($ur['nombre'], $ur['apellido'], $amb_nombre, $primera_fecha, $jornada_bd, $hora_inicio, $hora_fin, $registros_exitosos)
        );
    }
}

mysqli_close($con);

// REDIRIGIR CON MENSAJE
if($registros_exitosos > 0) {
    if($return == 'calendario') {
        header("Location: $ruta_base?sede_id=$sede_id&msg=ocupaciones_multiples&total=$registros_exitosos&mes=$primer_mes&anio=$primer_anio&dia=$primer_dia");
    } else {
        header("Location: $ruta_base?sede_id=$sede_id&msg=ocupaciones_multiples&total=$registros_exitosos");
    }
} else {
    if($fecha_pasada_count > 0 && $fecha_pasada_count == $registros_fallidos) {
        $error_code = 'fecha_pasada';
    } elseif($jornada_pasada_count > 0 && $jornada_pasada_count == $registros_fallidos) {
        $error_code = 'jornada_pasada';
    } elseif($dia_no_habil_count > 0 && $dia_no_habil_count == $registros_fallidos) {
        $error_code = 'dia_no_habil';
    } else {
        $error_code = 'registro_fallido';
    }

    if($return == 'calendario') {
        header("Location: $ruta_base?sede_id=$sede_id&error=$error_code&mes=$primer_mes&anio=$primer_anio&dia=$primer_dia");
    } else {
        header("Location: $ruta_base?sede_id=$sede_id&error=$error_code");
    }
}
?>
