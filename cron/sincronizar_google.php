<?php
/**
 * Sincroniza una ficha DESDE su Google Sheet:
 *   descarga el .xlsx público -> importa las ocupaciones (idempotente).
 *
 * Uso (CLI):
 *   php cron/sincronizar_google.php <ficha_id>
 *
 * Uso (todas las fichas activas con Sheet configurado):
 *   php cron/sincronizar_google.php --todas
 *
 * Pensado también para ejecutarse por cron / Task Scheduler.
 */

require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/lib/GoogleSheets.php';
require_once __DIR__ . '/lib/ImportadorCalendario.php';

$con = conexion();
mysqli_set_charset($con, 'utf8mb4');

/** Sincroniza una fila de ficha. Devuelve texto de resultado. */
function sincronizarFicha($con, array $ficha): string
{
    $sheetId = trim((string)($ficha['google_sheet_id'] ?? ''));
    if ($sheetId === '' && !empty($ficha['google_sheet_url'])) {
        $sheetId = GoogleSheets::extraerId($ficha['google_sheet_url']);
    }
    if ($sheetId === '') {
        return "Ficha {$ficha['codigo_ficha']}: sin Google Sheet configurado. Omitida.";
    }

    $tmp = GoogleSheets::rutaTemporal();
    try {
        GoogleSheets::descargarXlsx($sheetId, $tmp);
        $imp = new ImportadorCalendario($con);
        $r = $imp->importar(intval($ficha['id']), $tmp);
    } catch (Throwable $e) {
        @unlink($tmp);
        return "Ficha {$ficha['codigo_ficha']}: ERROR — " . $e->getMessage();
    }
    @unlink($tmp);

    $aviso = '';
    if (!empty($r['sin_instructor'])) {
        $aviso = ' | Sin enlazar: ' . implode(', ', array_keys($r['sin_instructor']));
    }
    return "Ficha {$r['ficha']}: {$r['insertados']} ocupaciones sincronizadas.$aviso";
}

$arg = $argv[1] ?? '';

if ($arg === '--todas') {
    $q = mysqli_query($con, "SELECT * FROM fichas WHERE activa = 1
                             AND (google_sheet_id IS NOT NULL OR google_sheet_url IS NOT NULL)");
    $n = 0;
    while ($ficha = mysqli_fetch_assoc($q)) {
        echo sincronizarFicha($con, $ficha) . "\n";
        $n++;
    }
    if ($n === 0) echo "No hay fichas activas con Google Sheet configurado.\n";
} else {
    $fichaId = intval($arg);
    if ($fichaId <= 0) {
        fwrite(STDERR, "Uso: php cron/sincronizar_google.php <ficha_id> | --todas\n");
        exit(1);
    }
    $q = mysqli_query($con, "SELECT * FROM fichas WHERE id = $fichaId");
    $ficha = $q ? mysqli_fetch_assoc($q) : null;
    if (!$ficha) {
        fwrite(STDERR, "Ficha #$fichaId no encontrada.\n");
        exit(1);
    }
    echo sincronizarFicha($con, $ficha) . "\n";
}

mysqli_close($con);
