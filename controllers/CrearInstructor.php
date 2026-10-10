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

// ⭐ OBTENER SEDE_ID Y ACCIÓN
$sede_id = isset($_POST['sede_id']) ? intval($_POST['sede_id']) : 0;
$accion = isset($_POST['accion']) ? $_POST['accion'] : 'crear';

if($sede_id <= 0) {
    header("Location: ../views/admin/index_sedes.php");
    exit();
}

// Obtener datos del formulario
$cedula = mysqli_real_escape_string($con, trim($_POST['cedula']));
$nombre = mysqli_real_escape_string($con, trim($_POST['nombre'])); // nombre completo en un solo campo
$apellido = mysqli_real_escape_string($con, trim($_POST['apellido'] ?? '')); // opcional (legacy)
$email = mysqli_real_escape_string($con, trim($_POST['email']));

// Valor SQL para apellido (NULL cuando viene vacío) y nombre para mostrar en mensajes
$apellido_val   = ($apellido !== '') ? "'$apellido'" : 'NULL';
$nombre_display = trim("$nombre $apellido");
$telefono = mysqli_real_escape_string($con, trim($_POST['telefono'] ?? ''));
$nivel_estudio = mysqli_real_escape_string($con, $_POST['nivel_estudio']);
$password = proteger_password($_POST['contrasena'] ?? '');
$tipo_contrato = mysqli_real_escape_string($con, $_POST['tipo_contrato']);

// ⭐ VALIDACIÓN 1: Email obligatorio
if(empty($email)) {
    header("Location: ../views/admin/instructores_admin.php?sede_id=$sede_id&error=email_vacio");
    exit();
}

// ⭐ VALIDACIÓN 2: Formato de email válido
if(!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header("Location: ../views/admin/instructores_admin.php?sede_id=$sede_id&error=email_invalido");
    exit();
}

// ═══════════════════════════════════════════════════════════════
// ACCIÓN: CREAR
// ═══════════════════════════════════════════════════════════════
if($accion == 'crear') {
    
    // Validar email único
    $verificar_email = mysqli_query($con, "SELECT id FROM usuarios WHERE email='$email'");
    if(mysqli_num_rows($verificar_email) > 0) {
        header("Location: ../views/admin/instructores_admin.php?sede_id=$sede_id&error=email_duplicado");
        exit();
    }
    
    // Verificar cédula única
    $verificar_cedula = mysqli_query($con, "SELECT id FROM usuarios WHERE cedula='$cedula'");
    if(mysqli_num_rows($verificar_cedula) > 0){
        header("Location: ../views/admin/instructores_admin.php?sede_id=$sede_id&error=cedula_exists");
        exit();
    }
    
    // Contraseña obligatoria al crear
    if(empty($password)) {
        header("Location: ../views/admin/instructores_admin.php?sede_id=$sede_id&error=password_required");
        exit();
    }
    
    // Insertar según tipo de contrato
    if($tipo_contrato == 'planta') {
        $sql = "INSERT INTO usuarios
        (cedula, email, nombre, apellido, telefono, nivel_estudio, contraseña, rol, tipo_contrato, fecha_inicio_contrato, fecha_fin_contrato, estado, sede_id)
        VALUES
        ('$cedula', '$email', '$nombre', $apellido_val, '$telefono', '$nivel_estudio', '$password', 'instructor', '$tipo_contrato', NULL, NULL, 'activo', $sede_id)";

    } else if($tipo_contrato == 'contratista') {
        $fecha_inicio = mysqli_real_escape_string($con, $_POST['fecha_inicio_contrato']);
        $fecha_fin = mysqli_real_escape_string($con, $_POST['fecha_fin_contrato']);

        $sql = "INSERT INTO usuarios
        (cedula, email, nombre, apellido, telefono, nivel_estudio, contraseña, rol, tipo_contrato, fecha_inicio_contrato, fecha_fin_contrato, estado, sede_id)
        VALUES
        ('$cedula', '$email', '$nombre', $apellido_val, '$telefono', '$nivel_estudio', '$password', 'instructor', '$tipo_contrato', '$fecha_inicio', '$fecha_fin', 'activo', NULL)";
    } else {
        header("Location: ../views/admin/instructores_admin.php?sede_id=$sede_id&error=tipo_invalido");
        exit();
    }

    $resultado = mysqli_query($con, $sql);

    if($resultado){
        enviarEmail($email, $nombre_display, 'Bienvenido/a a SAGA', emailBienvenida($nombre, $apellido, 'instructor'));
        header("Location: ../views/admin/instructores_admin.php?sede_id=$sede_id&msg=instructor_creado");
    } else {
        header("Location: ../views/admin/instructores_admin.php?sede_id=$sede_id&error=create_failed");
    }
}

// ═══════════════════════════════════════════════════════════════
// ACCIÓN: EDITAR
// ═══════════════════════════════════════════════════════════════
else if($accion == 'editar') {
    
    $instructor_id = isset($_POST['instructor_id']) ? intval($_POST['instructor_id']) : 0;
    
    if($instructor_id <= 0) {
        header("Location: ../views/admin/instructores_admin.php?sede_id=$sede_id&error=invalid_id");
        exit();
    }
    
    // ⭐ Validar email único (EXCLUYENDO el instructor actual)
    $verificar_email = mysqli_query($con, "SELECT id FROM usuarios WHERE email='$email' AND id != $instructor_id");
    if(mysqli_num_rows($verificar_email) > 0) {
        header("Location: ../views/admin/instructores_admin.php?sede_id=$sede_id&error=email_duplicado");
        exit();
    }
    
    // ⭐ Verificar cédula única (EXCLUYENDO el instructor actual)
    $verificar_cedula = mysqli_query($con, "SELECT id FROM usuarios WHERE cedula='$cedula' AND id != $instructor_id");
    if(mysqli_num_rows($verificar_cedula) > 0){
        header("Location: ../views/admin/instructores_admin.php?sede_id=$sede_id&error=cedula_exists");
        exit();
    }
    
    // Actualizar según tipo de contrato
    if($tipo_contrato == 'planta') {
        $sql = "UPDATE usuarios SET
        cedula = '$cedula',
        email = '$email',
        nombre = '$nombre',
        apellido = $apellido_val,
        telefono = '$telefono',
        nivel_estudio = '$nivel_estudio',
        tipo_contrato = '$tipo_contrato',
        fecha_inicio_contrato = NULL,
        fecha_fin_contrato = NULL,
        sede_id = $sede_id
        WHERE id = $instructor_id";

    } else if($tipo_contrato == 'contratista') {
        $fecha_inicio = mysqli_real_escape_string($con, $_POST['fecha_inicio_contrato']);
        $fecha_fin = mysqli_real_escape_string($con, $_POST['fecha_fin_contrato']);

        $sql = "UPDATE usuarios SET
        cedula = '$cedula',
        email = '$email',
        nombre = '$nombre',
        apellido = $apellido_val,
        telefono = '$telefono',
        nivel_estudio = '$nivel_estudio',
        tipo_contrato = '$tipo_contrato',
        fecha_inicio_contrato = '$fecha_inicio',
        fecha_fin_contrato = '$fecha_fin',
        sede_id = NULL
        WHERE id = $instructor_id";
    } else {
        header("Location: ../views/admin/instructores_admin.php?sede_id=$sede_id&error=tipo_invalido");
        exit();
    }

    // ⭐ Actualizar contraseña SOLO si se ingresó una nueva
    if(!empty($password)) {
        $sql = str_replace("WHERE id", ", contraseña = '$password' WHERE id", $sql);
    }
    
    $resultado = mysqli_query($con, $sql);
    
    if($resultado){
        header("Location: ../views/admin/instructores_admin.php?sede_id=$sede_id&msg=updated");
    } else {
        header("Location: ../views/admin/instructores_admin.php?sede_id=$sede_id&error=update_failed");
    }
}

mysqli_close($con);
exit();
?>
