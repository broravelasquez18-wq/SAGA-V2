<?php
/**
 * Importa el calendario de una ficha desde un archivo .xlsx local.
 *
 * Uso (CLI):
 *   php cron/importar_calendario.php <ficha_id> "<ruta_al_xlsx>"
 *
 * Ejemplo:
 *   php cron/importar_calendario.php 1 "C:/Users/braya/Downloads/2. 3142784- ADSO.xlsx"
 */

require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/lib/ImportadorCalendario.php';

if (PHP_SAPI !== 'cli') {
    die('Este script solo se ejecuta por línea de comandos.');
}

$fichaId = isset($argv[1]) ? intval($argv[1]) : 0;
$ruta    = $argv[2] ?? '';

if ($fichaId <= 0 || $ruta === '') {
    fwrite(STDERR, "Uso: php cron/importar_calendario.php <ficha_id> \"<ruta_xlsx>\"\n");
    exit(1);
}
if (!is_file($ruta)) {
    fwrite(STDERR, "No se encontró el archivo: $ruta\n");
    exit(1);
}

$con = conexion();
mysqli_set_charset($con, 'utf8mb4');

try {
    $imp = new ImportadorCalendario($con);
    $r = $imp->importar($fichaId, $ruta);
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}

echo "==========================================================\n";
echo "  IMPORTACIÓN DE CALENDARIO — Ficha {$r['ficha']}\n";
echo "  {$r['programa']}\n";
echo "==========================================================\n";
echo "Ocupaciones detectadas : {$r['ocupaciones']}\n";
echo "Ocupaciones insertadas : {$r['insertados']}\n";
echo "Celdas no-clase        : {$r['otros_no_clase']} (ALISTAMIENTO/notas)\n";
if (!empty($r['sin_instructor'])) {
    echo "AVISO — instructores sin usuario enlazado:\n";
    foreach ($r['sin_instructor'] as $nom => $n) {
        echo "   · $nom  ($n clases NO importadas)\n";
    }
}
if ($r['errores'] > 0) echo "Errores de inserción    : {$r['errores']}\n";
echo "----------------------------------------------------------\n";
echo "Listo. Última sincronización registrada en la ficha.\n";

mysqli_close($con);
