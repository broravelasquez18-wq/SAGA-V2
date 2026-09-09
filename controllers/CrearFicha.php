<?php
/**
 * Registra una nueva ficha de formación (código, programa, ambiente fijo, Google Sheet).
 */
session_start();
require_once "../config/conexion.php";
require_once "../config/csrf.php";
require_once "../cron/lib/GoogleSheets.php";

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

$sede_id     = isset($_POST['sede_id']) ? intval($_POST['sede_id']) : 0;
$codigo      = trim($_POST['codigo_ficha'] ?? '');
$programa    = trim($_POST['programa'] ?? '');
$ambiente_id = isset($_POST['ambiente_id']) ? intval($_POST['ambiente_id']) : 0;
$sheet_url   = trim($_POST['google_sheet_url'] ?? '');

$destino = "fichas_admin.php?sede_id=$sede_id";

if ($sede_id <= 0) { header("Location: ../views/admin/index_sedes.php"); exit(); }
if ($codigo === '' || $ambiente_id <= 0) {
    header("Location: ../views/admin/$destino&error=campos_vacios");
    exit();
}

// Validar que el ambiente pertenezca a la sede
$chk = mysqli_query($con, "SELECT a.id FROM ambientes a
                           JOIN pisos p ON a.piso_id = p.id
                           WHERE a.id = $ambiente_id AND p.sede_id = $sede_id");
if (!$chk || mysqli_num_rows($chk) == 0) {
    header("Location: ../views/admin/$destino&error=ambiente_invalido");
    exit();
}

$sheet_id  = $sheet_url !== '' ? GoogleSheets::extraerId($sheet_url) : '';
$codigo_e  = mysqli_real_escape_string($con, $codigo);
$programa_e = mysqli_real_escape_string($con, $programa);
$sheet_id_e = mysqli_real_escape_string($con, $sheet_id);
$sheet_url_e = mysqli_real_escape_string($con, $sheet_url);

// Evitar duplicado de código
$dup = mysqli_query($con, "SELECT id FROM fichas WHERE codigo_ficha = '$codigo_e'");
if ($dup && mysqli_num_rows($dup) > 0) {
    header("Location: ../views/admin/$destino&error=ficha_duplicada");
    exit();
}

$sql = "INSERT INTO fichas (codigo_ficha, programa, ambiente_id, sede_id, google_sheet_id, google_sheet_url, activa)
        VALUES ('$codigo_e', '$programa_e', $ambiente_id, $sede_id,
                " . ($sheet_id_e !== '' ? "'$sheet_id_e'" : "NULL") . ",
                " . ($sheet_url_e !== '' ? "'$sheet_url_e'" : "NULL") . ", 1)";

if (mysqli_query($con, $sql)) {
    header("Location: ../views/admin/$destino&msg=ficha_creada");
} else {
    header("Location: ../views/admin/$destino&error=registro_fallido");
}
exit();
