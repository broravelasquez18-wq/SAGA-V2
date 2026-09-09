<?php
session_start();
require_once "../config/conexion.php";
require_once "../config/csrf.php";

$con = conexion();
mysqli_set_charset($con, 'utf8mb4'); // sin esto, la columna «contraseña» (con ñ) no se lee bien

if($_SERVER['REQUEST_METHOD'] != 'POST') {
    header("Location: ../views/home.php");
    exit();
}

csrf_validate();

$cedula = mysqli_real_escape_string($con, $_POST['cedula']);
$password = $_POST['password'];

// Buscar usuario con su sede (sin filtrar por estado)
$sql = "SELECT u.*, s.nombre AS sede_nombre
        FROM usuarios u
        LEFT JOIN sedes s ON u.sede_id = s.id
        WHERE u.cedula = '$cedula'";

$resultado = mysqli_query($con, $sql);

if(mysqli_num_rows($resultado) > 0){

    $usuario = mysqli_fetch_assoc($resultado);

    // Verificar contraseña (soporta hash bcrypt y texto plano legacy)
    $hash = $usuario['contraseña'];
    $password_ok = password_verify($password, $hash) || ($password === $hash);

    if(!$password_ok){
        header("Location: ../views/home.php?error=credenciales");
        exit();
    }

    // Verificar que el usuario esté activo
    if($usuario['estado'] != 'activo'){
        header("Location: ../views/home.php?error=inactivo");
        exit();
    }

    if($password_ok){

        // Guardar en sesión
        $_SESSION['id'] = $usuario['id'];
        $_SESSION['nombre'] = $usuario['nombre'];
        $_SESSION['apellido'] = $usuario['apellido'];
        $_SESSION['rol'] = $usuario['rol'];
        $_SESSION['sede_id'] = $usuario['sede_id'];
        $_SESSION['sede_nombre'] = $usuario['sede_nombre'];
        $_SESSION['tipo_contrato'] = $usuario['tipo_contrato'];
        
        // Determinar permisos
        if($usuario['rol'] == 'admin') {
            $_SESSION['acceso_sedes'] = 'todas';
            $_SESSION['puede_cambiar_sede'] = true;
        } 
        elseif($usuario['rol'] == 'celador') {
            $_SESSION['acceso_sedes'] = 'una';
            $_SESSION['puede_cambiar_sede'] = false;
        }
        elseif($usuario['rol'] == 'instructor') {
            if($usuario['tipo_contrato'] == 'planta') {
                $_SESSION['acceso_sedes'] = 'una';
                $_SESSION['puede_cambiar_sede'] = false;
            } else {
                $_SESSION['acceso_sedes'] = 'todas';
                $_SESSION['puede_cambiar_sede'] = true;
            }
        }
        elseif($usuario['rol'] == 'vocero') {
            if($usuario['tipo_contrato'] == 'planta') {
                $_SESSION['acceso_sedes'] = 'una';
                $_SESSION['puede_cambiar_sede'] = false;
            } else {
                $_SESSION['acceso_sedes'] = 'todas';
                $_SESSION['puede_cambiar_sede'] = true;
            }
        }

        // Redirigir según rol
        if($usuario['rol'] == 'admin'){
            header("Location: ../views/admin/index_sedes.php");
            exit();
        }
        elseif($usuario['rol'] == 'celador'){
            header("Location: ../views/celador/dashboard_celador.php");
            exit();
        }
        elseif($usuario['rol'] == 'instructor'){
            header("Location: ../views/instructor/dashboard_instructor.php");
            exit();
        }
        elseif($usuario['rol'] == 'vocero'){
            header("Location: ../views/vocero/calendario_vocero.php");
            exit();
        }

    }

} else {
    // ⭐ USUARIO NO ENCONTRADO - Redirigir a views/home.php
    header("Location: ../views/home.php?error=credenciales");
    exit();
}
?>