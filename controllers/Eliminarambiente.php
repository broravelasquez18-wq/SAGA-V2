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

if(isset($_GET['id']) && isset($_GET['piso_id'])) {
    $ambiente_id = intval($_GET['id']);
    $piso_id = intval($_GET['piso_id']);
    
    // ⭐ OBTENER SEDE_ID
    $sede_id = isset($_GET['sede_id']) ? intval($_GET['sede_id']) : 0;
    
    if($sede_id <= 0) {
        header("Location: ../views/admin/index_sedes.php");
        exit();
    }
    
    // Eliminar el ambiente
    $query = "DELETE FROM ambientes WHERE id = $ambiente_id";
    
    if(mysqli_query($con, $query)) {
        // ⭐ Redirigir CON sede_id
        header("Location: ../views/admin/Ambientes_piso.php?piso_id=$piso_id&sede_id=$sede_id&msg=deleted");
    } else {
        header("Location: ../views/admin/Ambientes_piso.php?piso_id=$piso_id&sede_id=$sede_id&error=delete_failed");
    }
} else {
    header("Location: ../views/admin/index_sedes.php");
}

mysqli_close($con);
exit();
?>