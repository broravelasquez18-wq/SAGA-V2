<?php
session_start();
require_once "../config/conexion.php";
require_once "../config/csrf.php";

$con = conexion();
mysqli_set_charset($con, 'utf8mb4');

// Verificar que sea admin
if(!isset($_SESSION['id']) || $_SESSION['rol'] != 'admin') {
    header("Location: ../views/admin/index_sedes.php");
    exit();
}

csrf_validate();

// Obtener ID de la sede
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if($id <= 0) {
    header("Location: ../views/admin/index_sedes.php?error=operacion_fallida");
    exit();
}

// ⭐ VALIDAR: No eliminar si tiene pisos asociados
$check_pisos = mysqli_query($con, "SELECT COUNT(*) as total FROM pisos WHERE sede_id = $id");
$total_pisos = mysqli_fetch_assoc($check_pisos)['total'];

if($total_pisos > 0) {
    header("Location: ../views/admin/index_sedes.php?error=no_eliminar");
    exit();
}

// ⭐ VALIDAR: No eliminar si tiene usuarios asignados
$check_usuarios = mysqli_query($con, "SELECT COUNT(*) as total FROM usuarios WHERE sede_id = $id");
$total_usuarios = mysqli_fetch_assoc($check_usuarios)['total'];

if($total_usuarios > 0) {
    header("Location: ../views/admin/index_sedes.php?error=no_eliminar");
    exit();
}

// Si pasa las validaciones, eliminar la sede
$query = "DELETE FROM sedes WHERE id = $id";

if(mysqli_query($con, $query)) {
    header("Location: ../views/admin/index_sedes.php?msg=sede_eliminada");
} else {
    header("Location: ../views/admin/index_sedes.php?error=operacion_fallida");
}

mysqli_close($con);
exit();
?>