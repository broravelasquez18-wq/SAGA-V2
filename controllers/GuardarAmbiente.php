<?php
session_start();
require_once "../config/conexion.php";
require_once "../config/csrf.php";

if(!isset($_SESSION['id']) || $_SESSION['rol'] != 'admin') {
    header("Location: ../views/home.php");
    exit();
}

$con = conexion();
mysqli_set_charset($con, 'utf8mb4');

if($_SERVER['REQUEST_METHOD'] != 'POST') {
    header("Location: ../views/admin/index_sedes.php");
    exit();
}

csrf_validate();

// ⭐ OBTENER SEDE_ID
$sede_id = isset($_POST['sede_id']) ? intval($_POST['sede_id']) : 0;
$piso_id = isset($_POST['piso_id']) ? intval($_POST['piso_id']) : 0;

if($sede_id <= 0 || $piso_id <= 0) {
    header("Location: ../views/admin/index_sedes.php");
    exit();
}

// Validar que el piso pertenezca a la sede
$check_piso = mysqli_query($con, "SELECT id FROM pisos WHERE id = $piso_id AND sede_id = $sede_id");
if(mysqli_num_rows($check_piso) == 0) {
    header("Location: ../views/admin/pisos_admin.php?sede_id=$sede_id&error=piso_invalido");
    exit();
}

$accion = $_POST['accion'];
$nombre = mysqli_real_escape_string($con, trim($_POST['nombre']));
$descripcion = mysqli_real_escape_string($con, trim($_POST['descripcion']));
$estado = mysqli_real_escape_string($con, $_POST['estado']);

if($accion == 'crear') {
    // CREAR nuevo ambiente
    $query = "INSERT INTO ambientes (nombre, descripcion, piso_id, estado) 
              VALUES ('$nombre', '$descripcion', $piso_id, '$estado')";
    
    if(mysqli_query($con, $query)) {
        // ⭐ Redirigir CON sede_id
        header("Location: ../views/admin/Ambientes_piso.php?piso_id=$piso_id&sede_id=$sede_id&msg=created");
    } else {
        header("Location: ../views/admin/Ambientes_piso.php?piso_id=$piso_id&sede_id=$sede_id&error=create_failed");
    }
    
} elseif($accion == 'editar') {
    // EDITAR ambiente existente
    $ambiente_id = intval($_POST['ambiente_id']);
    
    $query = "UPDATE ambientes SET 
              nombre = '$nombre',
              descripcion = '$descripcion',
              estado = '$estado'
              WHERE id = $ambiente_id AND piso_id = $piso_id";
    
    if(mysqli_query($con, $query)) {
        header("Location: ../views/admin/Ambientes_piso.php?piso_id=$piso_id&sede_id=$sede_id&msg=updated");
    } else {
        header("Location: ../views/admin/Ambientes_piso.php?piso_id=$piso_id&sede_id=$sede_id&error=update_failed");
    }
}

mysqli_close($con);
exit();
?>