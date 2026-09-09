<?php
/**
 * Sincroniza una ficha desde su Google Sheet (disparado desde el botón de la UI).
 */
session_start();
require_once "../config/conexion.php";
require_once "../config/csrf.php";
require_once "../cron/lib/GoogleSheets.php";
require_once "../cron/lib/ImportadorCalendario.php";

if (!isset($_SESSION['id']) || $_SESSION['rol'] != 'admin') {
    header("Location: ../views/home.php");
    exit();
}
if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    header("Location: ../views/admin/index_sedes.php");
    exit();
}
csrf_validate();

$sede_id  = isset($_POST['sede_id'])  ? intval($_POST['sede_id'])  : 0;
$ficha_id = isset($_POST['ficha_id']) ? intval($_POST['ficha_id']) : 0;

if ($sede_id <= 0 || $ficha_id <= 0) {
    header("Location: ../views/admin/index_sedes.php");
    exit();
}

$destino = "fichas_admin.php?sede_id=$sede_id";

$con = conexion();
mysqli_set_charset($con, 'utf8mb4');

// Cargar la ficha
$fq = mysqli_query($con, "SELECT * FROM fichas WHERE id = $ficha_id AND sede_id = $sede_id");
$ficha = $fq ? mysqli_fetch_assoc($fq) : null;
if (!$ficha) {
    header("Location: ../views/admin/$destino&error=ficha_invalida");
    exit();
}

// Resolver el ID del Sheet
$sheetId = trim((string)($ficha['google_sheet_id'] ?? ''));
if ($sheetId === '' && !empty($ficha['google_sheet_url'])) {
    $sheetId = GoogleSheets::extraerId($ficha['google_sheet_url']);
}
if ($sheetId === '') {
    header("Location: ../views/admin/$destino&error=sin_sheet");
    exit();
}

// La descarga + parseo puede tardar varios segundos.
set_time_limit(120);

$tmp = GoogleSheets::rutaTemporal();
try {
    GoogleSheets::descargarXlsx($sheetId, $tmp);
    $imp = new ImportadorCalendario($con);
    $r = $imp->importar($ficha_id, $tmp, true);   // manual: fuerza re-importación completa
} catch (Throwable $e) {
    @unlink($tmp);
    // Distinguir el caso de documento no accesible
    $cod = (stripos($e->getMessage(), 'no es accesible') !== false) ? 'sheet_privado' : 'sync_fallo';
    header("Location: ../views/admin/$destino&error=$cod");
    exit();
}
@unlink($tmp);

$sin = !empty($r['sin_instructor']) ? count($r['sin_instructor']) : 0;
header("Location: ../views/admin/$destino&msg=sync_ok&total={$r['insertados']}&sin=$sin");
exit();
