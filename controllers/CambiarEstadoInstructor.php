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

if(isset($_GET['id']) && isset($_GET['estado'])) {
    $instructor_id = intval($_GET['id']);
    $nuevo_estado = $_GET['estado'];
    
    // ⭐ OBTENER SEDE_ID
    $sede_id = isset($_GET['sede_id']) ? intval($_GET['sede_id']) : 0;
    
    if($sede_id <= 0) {
        header("Location: ../views/admin/index_sedes.php");
        exit();
    }
    
    // Validar que el estado sea válido
    if($nuevo_estado == 'activo' || $nuevo_estado == 'inactivo') {
        // Actualizar estado del instructor
        $query = "UPDATE usuarios SET estado = '$nuevo_estado'
                  WHERE id = $instructor_id AND rol = 'instructor'
                  AND (sede_id = $sede_id OR (tipo_contrato = 'contratista' AND sede_id IS NULL))";
        
        if(mysqli_query($con, $query)) {
            $msg = ($nuevo_estado == 'activo') ? 'activated' : 'deactivated';
            // ⭐ REDIRIGIR AL MISMO MÓDULO CON SEDE_ID
            header("Location: ../views/admin/instructores_admin.php?sede_id=$sede_id&msg=$msg");
        } else {
            header("Location: ../views/admin/instructores_admin.php?sede_id=$sede_id&error=estado_failed");
        }
    } else {
        header("Location: ../views/admin/instructores_admin.php?sede_id=$sede_id&error=invalid_estado");
    }
} else {
    header("Location: ../views/admin/index_sedes.php");
}

mysqli_close($con);
exit();
?>