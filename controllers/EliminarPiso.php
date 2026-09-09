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
    $piso_id = intval($_GET['id']);
    
    // ⭐ OBTENER SEDE_ID
    $sede_id = isset($_GET['sede_id']) ? intval($_GET['sede_id']) : 0;
    
    if($sede_id <= 0) {
        header("Location: ../views/admin/index_sedes.php");
        exit();
    }
    
    // Iniciar transacción para asegurar integridad
    mysqli_begin_transaction($con);
    
    try {
        // Primero eliminar todos los ambientes asociados al piso
        $query_ambientes = "DELETE FROM ambientes WHERE piso_id = $piso_id";
        mysqli_query($con, $query_ambientes);
        
        // Luego eliminar el piso (validando que pertenezca a la sede)
        $query_piso = "DELETE FROM pisos WHERE id = $piso_id AND sede_id = $sede_id";
        
        if(mysqli_query($con, $query_piso)) {
            // Si todo salió bien, confirmar la transacción
            mysqli_commit($con);
            
            // ⭐ Redirigir CON sede_id
            header("Location: ../views/admin/pisos_admin.php?sede_id=$sede_id&msg=deleted");
            exit();
        } else {
            // Si falla, revertir cambios
            mysqli_rollback($con);
            
            // ⭐ Redirigir CON sede_id
            header("Location: ../views/admin/pisos_admin.php?sede_id=$sede_id&error=delete_failed");
            exit();
        }
        
    } catch (Exception $e) {
        // Si hay error, revertir cambios
        mysqli_rollback($con);
        
        // ⭐ Redirigir CON sede_id
        header("Location: ../views/admin/pisos_admin.php?sede_id=$sede_id&error=delete_failed");
        exit();
    }
    
} else {
    // Si no hay ID, redirigir al índice de sedes
    header("Location: ../views/admin/index_sedes.php");
    exit();
}

mysqli_close($con);
?>