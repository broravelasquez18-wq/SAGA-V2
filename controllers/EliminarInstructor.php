<?php
session_start();
require_once "../config/conexion.php";
require_once "../config/csrf.php";

if(!isset($_SESSION['id']) || $_SESSION['rol'] != 'admin') {
    header("Location: ../views/home.php");
    exit();
}

csrf_validate();

$con = conexion();
mysqli_set_charset($con, 'utf8mb4');

if(isset($_GET['id'])) {
    $instructor_id = intval($_GET['id']);
    
    // ⭐ OBTENER SEDE_ID
    $sede_id = isset($_GET['sede_id']) ? intval($_GET['sede_id']) : 0;
    
    if($sede_id <= 0) {
        header("Location: ../views/admin/index_sedes.php");
        exit();
    }
    
    // Eliminar instructor
    $query = "DELETE FROM usuarios WHERE id = $instructor_id AND rol = 'instructor'";
    
    if(mysqli_query($con, $query)) {
        header("Location: ../views/admin/instructores_admin.php?sede_id=$sede_id&msg=deleted");
    } else {
        header("Location: ../views/admin/instructores_admin.php?sede_id=$sede_id&error=delete_failed");
    }
} else {
    header("Location: ../views/admin/index_sedes.php");
}

mysqli_close($con);
exit();
?>