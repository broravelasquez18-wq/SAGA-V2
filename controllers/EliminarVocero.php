<?php
session_start();
require_once "../config/conexion.php";
require_once "../config/csrf.php";
if(!isset($_SESSION['id']) || $_SESSION['rol'] != 'admin') {
    header("Location: ../views/home.php"); exit();
}
csrf_validate();
$con = conexion();
mysqli_set_charset($con, 'utf8mb4');
$vocero_id = intval($_GET['id'] ?? 0);
$sede_id   = intval($_GET['sede_id'] ?? 0);
if($sede_id <= 0) { header("Location: ../views/admin/index_sedes.php"); exit(); }
if(mysqli_query($con, "DELETE FROM usuarios WHERE id=$vocero_id AND rol='vocero' AND sede_id=$sede_id")) {
    header("Location: ../views/admin/voceros_admin.php?sede_id=$sede_id&msg=deleted");
} else {
    header("Location: ../views/admin/voceros_admin.php?sede_id=$sede_id&error=delete_failed");
}
mysqli_close($con);
exit();
?>
