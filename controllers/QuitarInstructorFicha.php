<?php
/**
 * El admin quita un instructor de una ficha (elimina la fila de ficha_instructores).
 */
session_start();
require_once "../config/conexion.php";
require_once "../config/csrf.php";

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

$sede_id = intval($_POST['sede_id'] ?? 0);
$fi_id   = intval($_POST['fi_id'] ?? 0);
$destino = "fichas_admin.php?sede_id=$sede_id";

if ($sede_id <= 0 || $fi_id <= 0) {
    header("Location: ../views/admin/$destino&error=campos_vacios");
    exit();
}

// La fila debe pertenecer a una ficha de esta sede
$q = mysqli_query($con, "SELECT fi.id FROM ficha_instructores fi
                         JOIN fichas f ON fi.ficha_id = f.id
                         WHERE fi.id = $fi_id AND f.sede_id = $sede_id");
if (!$q || mysqli_num_rows($q) == 0) {
    header("Location: ../views/admin/$destino&error=no_encontrada");
    exit();
}

mysqli_query($con, "DELETE FROM ficha_instructores WHERE id = $fi_id");
header("Location: ../views/admin/$destino&msg=instructor_quitado");
exit();
