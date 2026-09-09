<?php
/**
 * auto_sync.php — sincronización automática "silenciosa".
 *
 * Se incluye al principio de las vistas donde importa ver el calendario al día
 * (fichas, ocupaciones, calendario). Sincroniza desde Google las fichas cuya
 * última sincronización sea más antigua que $maxEdadSeg, y NUNCA rompe la
 * página: si algo falla, simplemente no hace nada.
 *
 * Así el sistema se mantiene actualizado por sí solo, sin botones ni comandos:
 * basta con que alguien use la aplicación.
 */

require_once __DIR__ . '/GoogleSheets.php';
require_once __DIR__ . '/ImportadorCalendario.php';

if (!function_exists('auto_sync_sede')) {
    /**
     * @param mysqli $con
     * @param int    $sede_id     Sede a sincronizar (0 = todas)
     * @param int    $maxEdadSeg  No re-sincroniza si ya se hizo hace menos de esto.
     * @return array Resultado por ficha (silencioso en errores).
     */
    function auto_sync_sede($con, int $sede_id = 0, int $maxEdadSeg = 600): array
    {
        $resultado = [];

        $filtroSede = $sede_id > 0 ? "AND sede_id = " . intval($sede_id) : "";
        $sql = "SELECT * FROM fichas
                WHERE activa = 1
                  AND (google_sheet_id IS NOT NULL OR google_sheet_url IS NOT NULL)
                  AND (ultima_sync IS NULL OR ultima_sync < (NOW() - INTERVAL " . intval($maxEdadSeg) . " SECOND))
                  $filtroSede";
        $q = @mysqli_query($con, $sql);
        if (!$q) return $resultado;

        // Aumentar el tiempo por si hay varias fichas
        @set_time_limit(120);

        while ($ficha = mysqli_fetch_assoc($q)) {
            $sheetId = trim((string)($ficha['google_sheet_id'] ?? ''));
            if ($sheetId === '' && !empty($ficha['google_sheet_url'])) {
                $sheetId = GoogleSheets::extraerId($ficha['google_sheet_url']);
            }
            if ($sheetId === '') continue;

            $tmp = GoogleSheets::rutaTemporal();
            try {
                GoogleSheets::descargarXlsx($sheetId, $tmp);
                $imp = new ImportadorCalendario($con);
                $r = $imp->importar(intval($ficha['id']), $tmp);
                $resultado[$ficha['codigo_ficha']] = $r['insertados'];
            } catch (Throwable $e) {
                // Silencioso: no interrumpe la página.
                $resultado[$ficha['codigo_ficha']] = 'error';
            } finally {
                @unlink($tmp);
            }
        }
        return $resultado;
    }
}
