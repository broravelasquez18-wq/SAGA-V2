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

// Verificar sesión de instructor o vocero
if(!isset($_SESSION['id']) || $_SESSION['rol'] !== 'instructor') {
    header("Location: ../views/home.php");
    exit();
}

$ruta_base = $_SESSION['rol'] == 'vocero'
    ? "../views/vocero/calendario_vocero.php"
    : "../views/instructor/calendario_instructor.php";

$instructor_id = $_SESSION['id'];

// Obtener datos del formulario
$ambiente_id   = intval($_POST['ambiente_id'] ?? 0);
$jornada       = mysqli_real_escape_string($con, $_POST['jornada'] ?? '');
$observaciones = mysqli_real_escape_string($con, $_POST['observaciones'] ?? '');

// Validar ambiente
if($ambiente_id <= 0) {
    header("Location: $ruta_base?error=campos_vacios");
    exit();
}

// Determinar fechas a procesar
$fechas_a_registrar = [];

$fechas_json = $_POST['fechas_seleccionadas'] ?? '';
if(!empty($fechas_json)) {
    // Modo múltiple: JSON array de fechas
    $decoded = json_decode($fechas_json, true);
    if(is_array($decoded) && count($decoded) > 0) {
        $fechas_a_registrar = $decoded;
    }
}

if(empty($fechas_a_registrar)) {
    // Modo simple: una sola fecha
    $fecha_simple = trim($_POST['fecha'] ?? '');
    if(!empty($fecha_simple)) {
        $fechas_a_registrar[] = $fecha_simple;
    }
}

if(empty($fechas_a_registrar)) {
    header("Location: $ruta_base?error=sin_fechas");
    exit();
}

// Validar jornada y determinar horarios
$hora_inicio = '';
$hora_fin    = '';

switch($jornada) {
    case 'mañana':
        $hora_inicio = '06:00';
        $hora_fin    = '12:00';
        break;
    case 'tarde':
        $hora_inicio = '12:00';
        $hora_fin    = '18:00';
        break;
    case 'noche':
        $hora_inicio = '18:00';
        $hora_fin    = '22:00';
        break;
    case 'otro':
        $hora_inicio = $_POST['hora_inicio'] ?? '';
        $hora_fin    = $_POST['hora_fin']    ?? '';

        if(empty($hora_inicio) || empty($hora_fin)) {
            header("Location: $ruta_base?error=campos_vacios");
            exit();
        }
        if($hora_inicio < '06:00' || $hora_inicio > '22:00' ||
           $hora_fin   < '06:00' || $hora_fin   > '22:00') {
            header("Location: $ruta_base?error=horario_fuera");
            exit();
        }
        if($hora_fin <= $hora_inicio) {
            header("Location: $ruta_base?error=hora_invalida");
            exit();
        }
        $jornada = 'personalizado';
        break;
    default:
        header("Location: $ruta_base?error=campos_vacios");
        exit();
}

// Verificar que el ambiente exista
$check = mysqli_query($con, "SELECT a.id, p.sede_id FROM ambientes a JOIN pisos p ON p.id=a.piso_id WHERE a.id = $ambiente_id");
if(mysqli_num_rows($check) == 0) {
    header("Location: $ruta_base?error=ambiente_invalido");
    exit();
}

$ambiente = mysqli_fetch_assoc($check);
exigir_sede((int) $ambiente['sede_id']);

// Ficha: liga la clase manual a una ficha para poder avisar a su vocero al cancelarse.
// Solo aplica a instructores; se exige cuando el instructor tiene fichas asignadas.
$ficha_id = intval($_POST['ficha_id'] ?? 0);
if($_SESSION['rol'] == 'instructor') {
    $tf = mysqli_query($con, "SELECT COUNT(*) c FROM ficha_instructores WHERE usuario_id = $instructor_id");
    $tiene_fichas = ($tf && ($rf = mysqli_fetch_assoc($tf))) ? (int)$rf['c'] : 0;
    if($tiene_fichas > 0) {
        if($ficha_id <= 0) {
            header("Location: $ruta_base?error=ficha_requerida");
            exit();
        }
        $vf = mysqli_query($con, "SELECT 1 FROM ficha_instructores
                                  WHERE usuario_id = $instructor_id AND ficha_id = $ficha_id LIMIT 1");
        if(!$vf || mysqli_num_rows($vf) == 0) {
            header("Location: $ruta_base?error=ficha_invalida");
            exit();
        }
    } else {
        $ficha_id = 0; // instructor sin fichas: no se exige
    }
} else {
    $ficha_id = 0; // voceros u otros roles: no se liga por este selector
}
$ficha_sql = $ficha_id > 0 ? $ficha_id : 'NULL';

// Procesar cada fecha
$hoy = date('Y-m-d');
$registros_exitosos   = 0;
$registros_fallidos   = 0;
$jornada_pasada_count = 0;
$fecha_pasada_count   = 0;
$dia_no_habil_count   = 0;
$cancel_aprobada_count = 0;
$primer_mes  = null;
$primer_anio = null;

mysqli_begin_transaction($con);

foreach($fechas_a_registrar as $fecha) {
    $fecha = trim($fecha);

    // Validar formato básico
    if(!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
        $registros_fallidos++;
        continue;
    }

    // Guardar primer mes/año para redirección
    if($primer_mes === null) {
        $parts = explode('-', $fecha);
        $primer_anio = $parts[0];
        $primer_mes  = intval($parts[1]);
    }

    // No registrar en domingos ni festivos (validación autoritativa del servidor)
    if(es_dia_no_habil($fecha)) {
        $registros_fallidos++;
        $dia_no_habil_count++;
        continue;
    }

    // No registrar fechas pasadas
    if($fecha < $hoy) {
        $registros_fallidos++;
        $fecha_pasada_count++;
        continue;
    }

    // Si es hoy, verificar que la jornada no haya terminado completamente
    if($fecha == $hoy && $hora_fin <= date('H:i')) {
        $registros_fallidos++;
        $jornada_pasada_count++;
        continue;
    }

    $fecha_inicio_dt = $fecha . ' ' . $hora_inicio . ':00';
    $fecha_fin_dt    = $fecha . ' ' . $hora_fin    . ':00';

    // Verificar conflicto de ambiente
    $q_amb = "SELECT id FROM historial_ocupacion
              WHERE ambiente_id = $ambiente_id
              AND DATE(fecha_inicio) = '$fecha'
              AND estado != 'finalizado'
              AND estado != 'cancelado'
              AND (fecha_inicio < '$fecha_fin_dt' AND fecha_fin > '$fecha_inicio_dt')";

    if(mysqli_num_rows(mysqli_query($con, $q_amb)) > 0) {
        $registros_fallidos++;
        continue;
    }

    // Verificar conflicto del instructor en ese horario
    $q_inst = "SELECT id FROM historial_ocupacion
               WHERE usuario_id = $instructor_id
               AND DATE(fecha_inicio) = '$fecha'
               AND estado != 'finalizado'
               AND estado != 'cancelado'
               AND (fecha_inicio < '$fecha_fin_dt' AND fecha_fin > '$fecha_inicio_dt')";

    if(mysqli_num_rows(mysqli_query($con, $q_inst)) > 0) {
        $registros_fallidos++;
        continue;
    }

    // Bloquear re-registro de un slot cuya cancelación fue APROBADA para este mismo
    // usuario (mismo ambiente + día + jornada). Evita recuperar un espacio ya liberado
    // mediante el flujo de solicitud/aprobación.
    $jornada_chk = mysqli_real_escape_string($con, $jornada);
    $q_cancel = "SELECT h.id
                 FROM historial_ocupacion h
                 JOIN solicitudes_cancelacion sc ON sc.ocupacion_id = h.id AND sc.estado = 'aprobada'
                 WHERE h.usuario_id = $instructor_id
                   AND h.ambiente_id = $ambiente_id
                   AND DATE(h.fecha_inicio) = '$fecha'
                   AND h.jornada = '$jornada_chk'";
    if($jornada === 'personalizado') {
        $q_cancel .= " AND h.fecha_inicio = '$fecha_inicio_dt' AND h.fecha_fin = '$fecha_fin_dt'";
    }
    $q_cancel .= " LIMIT 1";

    if(mysqli_num_rows(mysqli_query($con, $q_cancel)) > 0) {
        $registros_fallidos++;
        $cancel_aprobada_count++;
        continue;
    }

    // Determinar estado inicial
    $estado_inicial = 'ocupado';
    if($fecha == $hoy) {
        $ahora         = new DateTime();
        $hora_inicio_obj = new DateTime($fecha_inicio_dt);
        $hora_fin_obj    = new DateTime($fecha_fin_dt);
        $hora_30_antes   = clone $hora_fin_obj;
        $hora_30_antes->modify('-30 minutes');

        if($ahora >= $hora_fin_obj) {
            $estado_inicial = 'disponible';
        } elseif($ahora >= $hora_30_antes) {
            $estado_inicial = 'proximo_a_desocupar';
        } else {
            $estado_inicial = 'ocupado';
        }
    }

    $jornada_db = mysqli_real_escape_string($con, $jornada);

    $insert = "INSERT INTO historial_ocupacion
               (ambiente_id, usuario_id, ficha_id, fecha_inicio, fecha_fin, estado, observaciones, jornada)
               VALUES
               ($ambiente_id, $instructor_id, $ficha_sql, '$fecha_inicio_dt', '$fecha_fin_dt', '$estado_inicial', '$observaciones', '$jornada_db')";

    if(mysqli_query($con, $insert)) {
        $registros_exitosos++;

        if($estado_inicial == 'ocupado') {
            mysqli_query($con, "UPDATE ambientes SET estado = 'ocupado' WHERE id = $ambiente_id");
        }
    } else {
        $registros_fallidos++;
    }
}

if($registros_exitosos > 0) {
    mysqli_commit($con);

    // Notificar al instructor/vocero por email
    $uq = mysqli_query($con, "SELECT nombre, apellido, email FROM usuarios WHERE id = $instructor_id");
    $aq = mysqli_query($con, "SELECT nombre FROM ambientes WHERE id = $ambiente_id");
    if($ur = mysqli_fetch_assoc($uq)) {
        $amb_nombre    = ($ar = mysqli_fetch_assoc($aq)) ? $ar['nombre'] : '';
        $primera_fecha = ($primer_anio && $primer_mes)
            ? sprintf('%04d-%02d-%02d', $primer_anio, $primer_mes, intval($parts[2] ?? 1))
            : date('Y-m-d');
        enviarEmail(
            $ur['email'],
            $ur['nombre'] . ' ' . $ur['apellido'],
            'Ocupación registrada - SAGA',
            emailNuevaOcupacion($ur['nombre'], $ur['apellido'], $amb_nombre, $primera_fecha, $jornada, $hora_inicio, $hora_fin, $registros_exitosos)
        );
    }
} else {
    mysqli_rollback($con);
}

mysqli_close($con);

// Redirigir con resultado
$params = "?mes=" . ($primer_mes ?? date('n')) . "&anio=" . ($primer_anio ?? date('Y'));

if($registros_exitosos > 0) {
    header("Location: $ruta_base{$params}&msg=exito&registrados=$registros_exitosos");
} else {
    if($fecha_pasada_count > 0 && $fecha_pasada_count == $registros_fallidos) {
        $error_code = 'fecha_pasada';
    } elseif($jornada_pasada_count > 0 && $jornada_pasada_count == $registros_fallidos) {
        $error_code = 'jornada_pasada';
    } elseif($dia_no_habil_count > 0 && $dia_no_habil_count == $registros_fallidos) {
        $error_code = 'dia_no_habil';
    } elseif($cancel_aprobada_count > 0 && $cancel_aprobada_count == $registros_fallidos) {
        $error_code = 'cancelacion_aprobada';
    } else {
        $error_code = 'conflicto';
    }
    header("Location: $ruta_base{$params}&error=$error_code");
}
?>
