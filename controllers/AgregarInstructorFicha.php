<?php
/**
 * El admin asigna manualmente un instructor registrado a una ficha
 * (independiente de los nombres detectados del Google Sheet).
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

$sede_id  = intval($_POST['sede_id']  ?? 0);
$ficha_id = intval($_POST['ficha_id'] ?? 0);
$cedula   = trim($_POST['cedula'] ?? '');
$destino  = "fichas_admin.php?sede_id=$sede_id";

if ($sede_id <= 0 || $ficha_id <= 0 || $cedula === '') {
    header("Location: ../views/admin/$destino&error=campos_vacios");
    exit();
}

// La ficha debe pertenecer a la sede
$fq = mysqli_query($con, "SELECT id FROM fichas WHERE id = $ficha_id AND sede_id = $sede_id");
if (!$fq || mysqli_num_rows($fq) == 0) {
    header("Location: ../views/admin/$destino&error=ficha_invalida");
    exit();
}

// Buscar el instructor por cédula
$ced = mysqli_real_escape_string($con, $cedula);
$uq = mysqli_query($con, "SELECT id, CONCAT_WS(' ', nombre, apellido) AS nombre
                          FROM usuarios WHERE cedula = '$ced' AND rol = 'instructor' LIMIT 1");
if (!$uq || mysqli_num_rows($uq) == 0) {
    header("Location: ../views/admin/$destino&error=instructor_no_existe");
    exit();
}
$u   = mysqli_fetch_assoc($uq);
$uid = intval($u['id']);
$nombre_e = mysqli_real_escape_string($con, $u['nombre']);

// ¿Ya está enlazado a la ficha?
$dup = mysqli_query($con, "SELECT id FROM ficha_instructores WHERE ficha_id = $ficha_id AND usuario_id = $uid");
if ($dup && mysqli_num_rows($dup) > 0) {
    header("Location: ../views/admin/$destino&msg=instructor_ya_en_ficha");
    exit();
}

// Insertar (nombre del instructor como nombre_excel). Si ya existe esa fila por
// nombre, se actualiza con la cédula y el usuario.
$ins = mysqli_query($con, "INSERT INTO ficha_instructores (ficha_id, nombre_excel, cedula, usuario_id, origen)
                           VALUES ($ficha_id, '$nombre_e', '$ced', $uid, 'manual')
                           ON DUPLICATE KEY UPDATE cedula = '$ced', usuario_id = $uid");

if ($ins) {
    // Recordar el enlace para el auto-match de futuras fichas
    instructor_alias_guardar($con, $u['nombre'], $uid);
    header("Location: ../views/admin/$destino&msg=instructor_agregado");
} else {
    header("Location: ../views/admin/$destino&error=registro_fallido");
}
exit();
