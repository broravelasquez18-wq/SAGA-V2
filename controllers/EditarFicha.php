<?php
/**
 * Actualiza una ficha existente: programa, ambiente fijo y enlace del Google Sheet.
 * El código de ficha no se cambia (es la clave del calendario).
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
$ficha_id    = isset($_POST['ficha_id']) ? intval($_POST['ficha_id']) : 0;
$programa    = trim($_POST['programa'] ?? '');
$ambiente_id = isset($_POST['ambiente_id']) ? intval($_POST['ambiente_id']) : 0;
$sheet_url   = trim($_POST['google_sheet_url'] ?? '');
$vocero_id   = isset($_POST['vocero_id']) ? intval($_POST['vocero_id']) : 0;

$destino = "fichas_admin.php?sede_id=$sede_id";

if ($sede_id <= 0) { header("Location: ../views/admin/index_sedes.php"); exit(); }
if ($ficha_id <= 0 || $ambiente_id <= 0) {
    header("Location: ../views/admin/$destino&error=campos_vacios");
    exit();
}

// La ficha debe pertenecer a la sede
$chkF = mysqli_query($con, "SELECT id FROM fichas WHERE id = $ficha_id AND sede_id = $sede_id");
if (!$chkF || mysqli_num_rows($chkF) == 0) {
    header("Location: ../views/admin/$destino&error=ambiente_invalido");
    exit();
}

// El ambiente debe pertenecer a la sede
$chkA = mysqli_query($con, "SELECT a.id FROM ambientes a
                            JOIN pisos p ON a.piso_id = p.id
                            WHERE a.id = $ambiente_id AND p.sede_id = $sede_id");
if (!$chkA || mysqli_num_rows($chkA) == 0) {
    header("Location: ../views/admin/$destino&error=ambiente_invalido");
    exit();
}

$sheet_id    = $sheet_url !== '' ? GoogleSheets::extraerId($sheet_url) : '';
$programa_e  = mysqli_real_escape_string($con, $programa);
$sheet_id_e  = mysqli_real_escape_string($con, $sheet_id);
$sheet_url_e = mysqli_real_escape_string($con, $sheet_url);

// Validar el vocero (debe ser un usuario con rol vocero); si no, se deja sin asignar.
$vocero_val = 'NULL';
if ($vocero_id > 0) {
    $vc = mysqli_query($con, "SELECT id FROM usuarios WHERE id = $vocero_id AND rol = 'vocero'");
    if ($vc && mysqli_num_rows($vc) > 0) $vocero_val = (string)$vocero_id;
}

$sql = "UPDATE fichas SET
            programa         = " . ($programa_e !== '' ? "'$programa_e'" : "NULL") . ",
            ambiente_id      = $ambiente_id,
            vocero_id        = $vocero_val,
            google_sheet_id  = " . ($sheet_id_e !== '' ? "'$sheet_id_e'" : "NULL") . ",
            google_sheet_url = " . ($sheet_url_e !== '' ? "'$sheet_url_e'" : "NULL") . "
        WHERE id = $ficha_id AND sede_id = $sede_id";

if (mysqli_query($con, $sql)) {
    header("Location: ../views/admin/$destino&msg=ficha_editada");
} else {
    header("Location: ../views/admin/$destino&error=registro_fallido");
}
exit();
