<?php
session_start();
require_once "../config/conexion.php";
require_once "../config/csrf.php";
require_once "../config/sendgrid.php";

$con = conexion();

// Verificar sesión de instructor
if(!isset($_SESSION['id']) || $_SESSION['rol'] != 'instructor') {
    header("Location: ../views/home.php");
    exit();
}

if($_SERVER['REQUEST_METHOD'] != 'POST') {
    header("Location: ../views/instructor/calendario_instructor.php");
    exit();
}

csrf_validate();

$instructor_id = $_SESSION['id'];
$ocupacion_id  = intval($_POST['id'] ?? 0);
$mes           = isset($_POST['mes'])  ? intval($_POST['mes'])  : date('n');
$anio          = isset($_POST['anio']) ? intval($_POST['anio']) : date('Y');

$ruta_base = "../views/instructor/calendario_instructor.php";
$params    = "?mes=$mes&anio=$anio";

if($ocupacion_id <= 0) {
    header("Location: $ruta_base{$params}&error=datos_invalidos");
    exit();
}

// Obtener la ocupación con datos del instructor y ambiente para el SMS
$result = mysqli_query($con,
    "SELECT ho.id, ho.ambiente_id, ho.fecha_inicio, ho.fecha_fin, ho.estado,
            a.nombre AS ambiente_nombre,
            u.nombre AS instructor_nombre, u.apellido AS instructor_apellido,
            u.telefono AS instructor_telefono, u.email AS instructor_email
     FROM historial_ocupacion ho
     LEFT JOIN ambientes a ON ho.ambiente_id = a.id
     LEFT JOIN usuarios u ON ho.usuario_id = u.id
     WHERE ho.id = $ocupacion_id
       AND ho.usuario_id = $instructor_id
       AND ho.estado IN ('ocupado', 'proximo_a_desocupar')"
);

if(mysqli_num_rows($result) == 0) {
    header("Location: $ruta_base{$params}&error=ocupacion_no_encontrada");
    exit();
}

$ocupacion            = mysqli_fetch_assoc($result);
$ambiente_id          = $ocupacion['ambiente_id'];
$fecha_fin_programada = $ocupacion['fecha_fin'];
$instructor_telefono  = $ocupacion['instructor_telefono']  ?? '';
$instructor_email     = $ocupacion['instructor_email']     ?? '';
$ambiente_nombre      = $ocupacion['ambiente_nombre']      ?? '';
$instructor_nombre_s  = $ocupacion['instructor_nombre']    ?? '';
$instructor_apellido_s= $ocupacion['instructor_apellido']  ?? '';
$instructor_nombre    = trim("$instructor_nombre_s $instructor_apellido_s");

// Calcular fecha_fin real
$ahora = date('Y-m-d H:i:s');
$nueva_fecha_fin = (strtotime($ahora) > strtotime($fecha_fin_programada))
    ? $ahora
    : $fecha_fin_programada;

// Finalizar la ocupación
$update = "UPDATE historial_ocupacion
           SET estado = 'finalizado', fecha_fin = '$nueva_fecha_fin'
           WHERE id = $ocupacion_id";

if(mysqli_query($con, $update)) {
    // Si no quedan ocupaciones activas en el ambiente hoy, liberarlo
    $otras = mysqli_fetch_assoc(mysqli_query($con,
        "SELECT COUNT(*) as total FROM historial_ocupacion
         WHERE ambiente_id = $ambiente_id
           AND DATE(fecha_inicio) = CURDATE()
           AND estado IN ('ocupado', 'proximo_a_desocupar')"
    ));

    if($otras['total'] == 0) {
        mysqli_query($con, "UPDATE ambientes SET estado = 'disponible' WHERE id = $ambiente_id");
    }

    // Notificar por email
    if(!empty($instructor_email)) {
        enviarEmail(
            $instructor_email,
            $instructor_nombre,
            'Ocupación finalizada - SAGA',
            emailOcupacionFinalizada($instructor_nombre_s, $instructor_apellido_s, $ambiente_nombre, $nueva_fecha_fin)
        );
    }

    header("Location: $ruta_base{$params}&msg=finalizado");
} else {
    header("Location: $ruta_base{$params}&error=finalizar_fallido");
}

mysqli_close($con);
?>
