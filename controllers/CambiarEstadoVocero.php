<?php
session_start();
require_once "../config/conexion.php";
require_once "../config/csrf.php";
if(!isset($_SESSION['id']) || $_SESSION['rol'] != 'admin') {
    header("Location: ../views/home.php"); exit();
}
csrf_validate();
$con      = conexion();
mysqli_set_charset($con, 'utf8mb4');
$id       = intval($_GET['id'] ?? 0);
$estado   = in_array($_GET['estado'] ?? '', ['activo','inactivo']) ? $_GET['estado'] : 'activo';
$sede_id  = intval($_GET['sede_id'] ?? 0);
if($sede_id <= 0) { header("Location: ../views/admin/index_sedes.php"); exit(); }
mysqli_query($con, "UPDATE usuarios SET estado='$estado' WHERE id=$id AND rol='vocero'");
header("Location: ../views/admin/voceros_admin.php?sede_id=$sede_id&msg=estado_updated");
mysqli_close($con);
exit();
?>
