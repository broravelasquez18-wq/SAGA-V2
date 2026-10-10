<?php
session_start();
require_once "../config/conexion.php";
require_once "../config/csrf.php";
require_once "../config/sendgrid.php";

if(!isset($_SESSION['id']) || $_SESSION['rol'] != 'admin') {
    header("Location: ../views/home.php");
    exit();
}

$con = conexion();
mysqli_set_charset($con, 'utf8mb4'); // datos con tildes/ñ y la columna «contraseña» dependen de esto

if($_SERVER['REQUEST_METHOD'] != 'POST') {
    header("Location: ../views/admin/index_sedes.php");
    exit();
}

csrf_validate();

$sede_id = intval($_POST['sede_id'] ?? 0);
$accion  = $_POST['accion'] ?? 'crear';

if($sede_id <= 0) {
    header("Location: ../views/admin/index_sedes.php");
    exit();
}

$cedula        = mysqli_real_escape_string($con, trim($_POST['cedula']));
$nombre        = mysqli_real_escape_string($con, trim($_POST['nombre']));
$apellido      = mysqli_real_escape_string($con, trim($_POST['apellido'] ?? ''));
$apellido_val  = ($apellido !== '') ? "'" . $apellido . "'" : 'NULL';
$email         = mysqli_real_escape_string($con, trim($_POST['email']));
$telefono      = mysqli_real_escape_string($con, trim($_POST['telefono'] ?? ''));
$password      = $_POST['contrasena'] ?? '';
$nivel_estudio    = mysqli_real_escape_string($con, trim($_POST['nivel_estudio']    ?? ''));
$programa_estudio = mysqli_real_escape_string($con, trim($_POST['programa_estudio'] ?? ''));
$ficha_id         = intval($_POST['ficha_id'] ?? 0);
$fecha_inicio   = !empty($_POST['fecha_inicio_contrato']) ? mysqli_real_escape_string($con, $_POST['fecha_inicio_contrato']) : null;
$fecha_fin      = !empty($_POST['fecha_fin_contrato'])    ? mysqli_real_escape_string($con, $_POST['fecha_fin_contrato'])    : null;
$fecha_inicio_sql = $fecha_inicio ? "'$fecha_inicio'" : 'NULL';
$fecha_fin_sql    = $fecha_fin    ? "'$fecha_fin'"    : 'NULL';

function validarFichaVocero(mysqli $con, int $ficha_id, int $sede_id, int $vocero_actual_id = 0): array {
    if($ficha_id <= 0) {
        return ['ok' => true];
    }

    $q = mysqli_query($con, "SELECT id, vocero_id FROM fichas WHERE id = $ficha_id AND sede_id = $sede_id LIMIT 1");
    if(!$q || mysqli_num_rows($q) === 0) {
        return ['ok' => false, 'error' => 'ficha_invalida'];
    }

    $fila = mysqli_fetch_assoc($q);
    $vocero_asignado = isset($fila['vocero_id']) ? (int)$fila['vocero_id'] : 0;
    if($vocero_asignado > 0 && $vocero_asignado !== $vocero_actual_id) {
        return ['ok' => false, 'error' => 'ficha_ocupada'];
    }

    return ['ok' => true];
}

if(empty($email)) {
    header("Location: ../views/admin/voceros_admin.php?sede_id=$sede_id&error=email_vacio");
    exit();
}
if(!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header("Location: ../views/admin/voceros_admin.php?sede_id=$sede_id&error=email_invalido");
    exit();
}

// ── CREAR ────────────────────────────────────────────────────────
if($accion == 'crear') {
    $validacion_ficha = validarFichaVocero($con, $ficha_id, $sede_id);
    if(!$validacion_ficha['ok']) {
        header("Location: ../views/admin/voceros_admin.php?sede_id=$sede_id&error=" . $validacion_ficha['error']);
        exit();
    }

    $dup_email = mysqli_query($con, "SELECT id FROM usuarios WHERE email='$email'");
    if(mysqli_num_rows($dup_email) > 0) {
        header("Location: ../views/admin/voceros_admin.php?sede_id=$sede_id&error=email_duplicado");
        exit();
    }
    $dup_cedula = mysqli_query($con, "SELECT id FROM usuarios WHERE cedula='$cedula'");
    if(mysqli_num_rows($dup_cedula) > 0) {
        header("Location: ../views/admin/voceros_admin.php?sede_id=$sede_id&error=cedula_exists");
        exit();
    }
    if(empty($password)) {
        header("Location: ../views/admin/voceros_admin.php?sede_id=$sede_id&error=password_required");
        exit();
    }
    $password_hash = proteger_password($password);

    mysqli_begin_transaction($con);
    $sql = "INSERT INTO usuarios (cedula,email,nombre,apellido,contraseña,telefono,rol,nivel_estudio,programa_estudio,tipo_contrato,fecha_inicio_contrato,fecha_fin_contrato,estado,sede_id)
            VALUES ('$cedula','$email','$nombre',$apellido_val,'$password_hash','$telefono','vocero','$nivel_estudio','$programa_estudio','planta',$fecha_inicio_sql,$fecha_fin_sql,'activo',$sede_id)";

    if(mysqli_query($con, $sql)) {
        $nuevo_vocero_id = (int)mysqli_insert_id($con);
        if($ficha_id > 0) {
            if(!mysqli_query($con, "UPDATE fichas SET vocero_id = $nuevo_vocero_id WHERE id = $ficha_id AND sede_id = $sede_id")) {
                mysqli_rollback($con);
                header("Location: ../views/admin/voceros_admin.php?sede_id=$sede_id&error=update_failed");
                exit();
            }
        }
        mysqli_commit($con);
        enviarEmail($email, "$nombre $apellido", 'Bienvenido/a a SAGA', emailBienvenida($nombre, $apellido, 'vocero'));
        header("Location: ../views/admin/voceros_admin.php?sede_id=$sede_id&msg=vocero_creado");
    } else {
        mysqli_rollback($con);
        header("Location: ../views/admin/voceros_admin.php?sede_id=$sede_id&error=create_failed");
    }

// ── EDITAR ───────────────────────────────────────────────────────
} else {
    $vocero_id = intval($_POST['vocero_id'] ?? 0);
    if($vocero_id <= 0) {
        header("Location: ../views/admin/voceros_admin.php?sede_id=$sede_id&error=invalid_id");
        exit();
    }

    $validacion_ficha = validarFichaVocero($con, $ficha_id, $sede_id, $vocero_id);
    if(!$validacion_ficha['ok']) {
        header("Location: ../views/admin/voceros_admin.php?sede_id=$sede_id&error=" . $validacion_ficha['error']);
        exit();
    }

    $dup_email = mysqli_query($con, "SELECT id FROM usuarios WHERE email='$email' AND id != $vocero_id");
    if(mysqli_num_rows($dup_email) > 0) {
        header("Location: ../views/admin/voceros_admin.php?sede_id=$sede_id&error=email_duplicado");
        exit();
    }
    $dup_cedula = mysqli_query($con, "SELECT id FROM usuarios WHERE cedula='$cedula' AND id != $vocero_id");
    if(mysqli_num_rows($dup_cedula) > 0) {
        header("Location: ../views/admin/voceros_admin.php?sede_id=$sede_id&error=cedula_exists");
        exit();
    }

    $sql = "UPDATE usuarios SET cedula='$cedula',email='$email',nombre='$nombre',apellido=$apellido_val,
            telefono='$telefono',nivel_estudio='$nivel_estudio',programa_estudio='$programa_estudio',
            tipo_contrato='planta',fecha_inicio_contrato=$fecha_inicio_sql,fecha_fin_contrato=$fecha_fin_sql
            WHERE id=$vocero_id";
    if(!empty($password)) {
        $password_hash = proteger_password($password);
        $sql = str_replace("WHERE id", ", contraseña='$password_hash' WHERE id", $sql);
    }

    mysqli_begin_transaction($con);
    if(mysqli_query($con, $sql)) {
        if(!mysqli_query($con, "UPDATE fichas SET vocero_id = NULL WHERE vocero_id = $vocero_id")) {
            mysqli_rollback($con);
            header("Location: ../views/admin/voceros_admin.php?sede_id=$sede_id&error=update_failed");
            exit();
        }
        if($ficha_id > 0) {
            if(!mysqli_query($con, "UPDATE fichas SET vocero_id = $vocero_id WHERE id = $ficha_id AND sede_id = $sede_id")) {
                mysqli_rollback($con);
                header("Location: ../views/admin/voceros_admin.php?sede_id=$sede_id&error=update_failed");
                exit();
            }
        }
        mysqli_commit($con);
        header("Location: ../views/admin/voceros_admin.php?sede_id=$sede_id&msg=updated");
    } else {
        mysqli_rollback($con);
        header("Location: ../views/admin/voceros_admin.php?sede_id=$sede_id&error=update_failed");
    }
}

mysqli_close($con);
exit();
?>
