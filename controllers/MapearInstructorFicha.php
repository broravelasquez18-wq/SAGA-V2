<?php
/**
 * Enlaza un instructor detectado en el Sheet con un usuario de SAGA (por cédula).
 */
session_start();
require_once "../config/conexion.php";
require_once "../config/csrf.php";
require_once "../cron/lib/instructor_match.php";

if (!isset($_SESSION['id']) || $_SESSION['rol'] != 'admin') {
    header("Location: ../views/home.php");
    exit();
}
if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    header("Location: ../views/admin/index_sedes.php");
    exit();
}
csrf_validate();

$con = conexion();
mysqli_set_charset($con, 'utf8mb4');

$sede_id = isset($_POST['sede_id']) ? intval($_POST['sede_id']) : 0;
$fi_id   = isset($_POST['fi_id'])   ? intval($_POST['fi_id'])   : 0;  // id en ficha_instructores
$cedula  = trim($_POST['cedula'] ?? '');

$destino = "fichas_admin.php?sede_id=$sede_id";

if ($sede_id <= 0 || $fi_id <= 0 || $cedula === '') {
    header("Location: ../views/admin/$destino&error=campos_vacios");
    exit();
}

$cedula_e = mysqli_real_escape_string($con, $cedula);

// Buscar usuario por cédula
$uq = mysqli_query($con, "SELECT id FROM usuarios WHERE cedula = '$cedula_e' LIMIT 1");
if (!$uq || mysqli_num_rows($uq) == 0) {
    header("Location: ../views/admin/$destino&error=cedula_no_existe");
    exit();
}
$usuario_id = intval(mysqli_fetch_assoc($uq)['id']);

$upd = mysqli_query($con,
    "UPDATE ficha_instructores SET cedula = '$cedula_e', usuario_id = $usuario_id WHERE id = $fi_id");

if ($upd) {
    // Recordar el enlace globalmente para que este nombre se auto-mapee en las próximas fichas.
    $nq = mysqli_query($con, "SELECT nombre_excel FROM ficha_instructores WHERE id = $fi_id LIMIT 1");
    if ($nq && ($nr = mysqli_fetch_assoc($nq))) {
        instructor_alias_guardar($con, $nr['nombre_excel'], $usuario_id);
    }
    header("Location: ../views/admin/$destino&msg=instructor_enlazado");
} else {
    header("Location: ../views/admin/$destino&error=registro_fallido");
}
exit();
