<?php
/**
 * ImportadorCalendario — toma un .xlsx de calendario SENA y crea/actualiza
 * las ocupaciones de una ficha en historial_ocupacion.
 *
 * Idempotente: borra las ocupaciones con origen='sync' de esa ficha y las
 * reinserta. Las ocupaciones manuales (origen='manual') NUNCA se tocan.
 */

require_once __DIR__ . '/ParserCalendario.php';
require_once __DIR__ . '/instructor_match.php';

class ImportadorCalendario
{
    private $con;

    public function __construct($con)
    {
        $this->con = $con;
    }

    /** Determina la jornada a partir del rango horario. */
    private static function jornada(string $hi, string $hf): string
    {
        if ($hi >= '06:00' && $hf <= '12:00') return 'mañana';
        if ($hi >= '12:00' && $hf <= '18:00') return 'tarde';
        if ($hi >= '18:00' && $hf <= '22:00') return 'noche';
        return 'personalizado';
    }

    /**
     * Recorta la ocupación a su jornada PRESENCIAL.
     *
     * En el calendario del SENA cada bloque de clase incluye ~2 horas de
     * "trabajo en casa" al final (p. ej. tarde 12:00–18:00 aparece como
     * 12:00–20:00). Esas horas NO son ocupación del ambiente. Determinamos la
     * jornada por la hora de INICIO y recortamos el fin al límite de esa
     * jornada (mañana→12:00, tarde→18:00, noche→22:00).
     *
     * @return array [hora_fin_presencial, jornada]
     */
    private static function ajustarPresencial(string $hi, string $hf): array
    {
        if     ($hi >= '06:00' && $hi < '12:00') { $limite = '12:00'; $jornada = 'mañana'; }
        elseif ($hi >= '12:00' && $hi < '18:00') { $limite = '18:00'; $jornada = 'tarde';  }
        elseif ($hi >= '18:00' && $hi < '22:00') { $limite = '22:00'; $jornada = 'noche';  }
        else { return [$hf, self::jornada($hi, $hf)]; }   // inicio fuera de rango: no tocar

        // Recortar las horas extra (trabajo en casa) que sobrepasan la jornada
        $hfPres = ($hf > $limite) ? $limite : $hf;
        // Salvaguarda: nunca dejar una duración nula o negativa
        if ($hfPres <= $hi) $hfPres = $hf;

        return [$hfPres, $jornada];
    }

    /**
     * Importa el calendario para una ficha.
     * @return array resultado con contadores y avisos.
     */
    public function importar(int $fichaId, string $rutaXlsx, bool $forzar = false): array
    {
        $con = $this->con;

        // 1. Cargar la ficha y su ambiente fijo
        $fq = mysqli_query($con, "SELECT * FROM fichas WHERE id = " . intval($fichaId));
        $ficha = $fq ? mysqli_fetch_assoc($fq) : null;
        if (!$ficha) {
            throw new RuntimeException("Ficha #$fichaId no encontrada.");
        }
        if (empty($ficha['ambiente_id'])) {
            throw new RuntimeException("La ficha {$ficha['codigo_ficha']} no tiene ambiente asignado.");
        }
        $ambienteId = intval($ficha['ambiente_id']);

        // 1b. Atajo rápido: si el archivo descargado es idéntico al de la última
        //     sincronización (misma huella), no hace falta re-parsear ni reinsertar.
        //     Sólo actualizamos ultima_sync y salimos. Esto ahorra el parseo con
        //     PhpSpreadsheet (lo más costoso) cuando el cronograma no cambió.
        $hashActual = @sha1_file($rutaXlsx);
        if (!$forzar && $hashActual && !empty($ficha['sheet_hash']) && hash_equals((string)$ficha['sheet_hash'], $hashActual)) {
            mysqli_query($con, "UPDATE fichas SET ultima_sync = NOW() WHERE id = " . intval($fichaId));
            $totRow = mysqli_fetch_assoc(mysqli_query($con,
                "SELECT COUNT(*) t FROM historial_ocupacion WHERE ficha_id = " . intval($fichaId) . " AND origen = 'sync'"));
            return [
                'ficha'          => $ficha['codigo_ficha'],
                'programa'       => $ficha['programa'],
                'ambiente_id'    => $ambienteId,
                'meta'           => [],
                'ocupaciones'    => (int)($totRow['t'] ?? 0),
                'insertados'     => 0,        // 0 = no hubo cambios (no dispara recarga)
                'sin_instructor' => [],
                'otros_no_clase' => 0,
                'errores'        => 0,
                'sin_cambios'    => true,
            ];
        }

        // 2. Parsear el archivo
        $parser = new ParserCalendario();
        $parser->parseArchivo($rutaXlsx);
        $ocupaciones = $parser->agrupar();

        // 3a. Auto-registrar los nombres de instructor detectados en el Sheet,
        //     para que aparezcan en la pantalla de mapeo aunque aún no tengan usuario.
        $nombresDetectados = [];
        foreach ($ocupaciones as $o) {
            $nombresDetectados[mb_strtoupper($o['instructor'])] = $o['instructor'];
        }
        $ins = mysqli_prepare($con,
            "INSERT IGNORE INTO ficha_instructores (ficha_id, nombre_excel) VALUES (?, ?)");
        foreach ($nombresDetectados as $nom) {
            mysqli_stmt_bind_param($ins, 'is', $fichaId, $nom);
            mysqli_stmt_execute($ins);
        }
        mysqli_stmt_close($ins);

        // 3b. Mapa instructor(nombre en Excel) -> usuario_id.
        //     Si la fila tiene cédula pero aún no usuario_id, se resuelve por cédula.
        $map = [];
        $mq = mysqli_query($con,
            "SELECT id, nombre_excel, cedula, usuario_id FROM ficha_instructores WHERE ficha_id = " . intval($fichaId));
        while ($row = mysqli_fetch_assoc($mq)) {
            $uid = $row['usuario_id'] ? intval($row['usuario_id']) : null;
            if (!$uid && !empty($row['cedula'])) {
                $ced = mysqli_real_escape_string($con, $row['cedula']);
                $uq = mysqli_query($con, "SELECT id FROM usuarios WHERE cedula = '$ced' LIMIT 1");
                if ($uq && ($ur = mysqli_fetch_assoc($uq))) {
                    $uid = intval($ur['id']);
                    mysqli_query($con, "UPDATE ficha_instructores SET usuario_id = $uid WHERE id = " . intval($row['id']));
                }
            }
            // Auto-match: alias recordado o coincidencia única por nombre. Evita el
            // mapeo manual repetido de los instructores que se repiten entre fichas.
            if (!$uid) {
                $uid = instructor_resolver($con, $row['nombre_excel']);
                if ($uid) {
                    mysqli_query($con, "UPDATE ficha_instructores SET usuario_id = $uid WHERE id = " . intval($row['id']));
                }
            }
            $map[mb_strtoupper(trim($row['nombre_excel']))] = $uid;
        }

        // 4. Idempotencia: borrar los 'sync' previos de esta ficha.
        //    Todo el borrado + reinserción va en UNA transacción: en MySQL cada
        //    INSERT suelto hace un fsync a disco; agruparlos en una transacción
        //    convierte cientos de commits en uno solo (mucho más rápido).
        //    Las clases CANCELADAS (eliminadas por el admin/celador o con
        //    cancelación aprobada) se conservan y NO se vuelven a crear: si no,
        //    cada sincronización "reviviría" lo que se canceló.
        $canceladas = [];
        $cq = mysqli_query($con, "SELECT usuario_id, fecha_inicio FROM historial_ocupacion
                                  WHERE ficha_id = " . intval($fichaId) . " AND origen = 'sync' AND estado = 'cancelado'");
        while ($cq && ($c = mysqli_fetch_assoc($cq))) {
            $canceladas[$c['usuario_id'] . '|' . $c['fecha_inicio']] = true;
        }

        //    Las llegadas ya registradas por el celador también se conservan:
        //    se guardan aquí y se vuelven a aplicar a la clase reinsertada.
        $llegadas = [];
        $lq = mysqli_query($con, "SELECT usuario_id, fecha_inicio, llegada_en, llegada_por, alerta_no_llego
                                  FROM historial_ocupacion
                                  WHERE ficha_id = " . intval($fichaId) . " AND origen = 'sync' AND estado <> 'cancelado'
                                    AND (llegada_en IS NOT NULL OR alerta_no_llego = 1)");
        while ($lq && ($l = mysqli_fetch_assoc($lq))) {
            $llegadas[$l['usuario_id'] . '|' . $l['fecha_inicio']] = $l;
        }

        mysqli_begin_transaction($con);
        mysqli_query($con, "DELETE FROM historial_ocupacion WHERE ficha_id = " . intval($fichaId) . " AND origen = 'sync' AND estado <> 'cancelado'");

        // 5. Insertar
        $now = new DateTime();
        $insertados = 0;
        $sinInstructor = [];   // nombre_excel => nº de clases sin usuario
        $errores = 0;

        $stmt = mysqli_prepare($con,
            "INSERT INTO historial_ocupacion
             (ambiente_id, usuario_id, ficha_id, fecha_inicio, fecha_fin, estado, observaciones, competencia, origen, jornada)
             VALUES (?, ?, ?, ?, ?, ?, 'Sincronizado desde calendario SENA', ?, 'sync', ?)");

        foreach ($ocupaciones as $o) {
            $nombre = mb_strtoupper($o['instructor']);
            $uid = $map[$nombre] ?? null;
            if (!$uid) {
                $sinInstructor[$o['instructor']] = ($sinInstructor[$o['instructor']] ?? 0) + 1;
                continue;
            }

            // Recortar el "trabajo en casa" y clasificar la jornada por la hora de inicio
            [$hfPresencial, $jornada] = self::ajustarPresencial($o['hi'], $o['hf']);
            $fi = $o['fecha'] . ' ' . $o['hi'] . ':00';
            $ff = $o['fecha'] . ' ' . $hfPresencial . ':00';
            if (isset($canceladas[$uid . '|' . $fi])) continue;   // cancelada: no recrear
            $estado = (strtotime($ff) <= $now->getTimestamp()) ? 'finalizado' : 'ocupado';

            mysqli_stmt_bind_param($stmt, 'iiissss' . 's',
                $ambienteId, $uid, $fichaId, $fi, $ff, $estado, $o['competencia'], $jornada);
            if (mysqli_stmt_execute($stmt)) {
                $insertados++;
                // Reaplicar la llegada registrada antes de la resincronización
                $clave = $uid . '|' . $fi;
                if (isset($llegadas[$clave])) {
                    $l   = $llegadas[$clave];
                    $nid = intval(mysqli_insert_id($con));
                    $le  = $l['llegada_en'] ? "'" . mysqli_real_escape_string($con, $l['llegada_en']) . "'" : 'NULL';
                    $lp  = $l['llegada_por'] ? intval($l['llegada_por']) : 'NULL';
                    mysqli_query($con, "UPDATE historial_ocupacion SET llegada_en = $le, llegada_por = $lp,
                                        alerta_no_llego = " . intval($l['alerta_no_llego']) . " WHERE id = $nid");
                }
            } else {
                $errores++;
            }
        }
        mysqli_stmt_close($stmt);
        mysqli_commit($con);

        // 6. Marcar última sincronización y guardar la huella del archivo
        $hashEsc = $hashActual ? "'" . mysqli_real_escape_string($con, $hashActual) . "'" : 'NULL';
        mysqli_query($con, "UPDATE fichas SET ultima_sync = NOW(), sheet_hash = $hashEsc WHERE id = " . intval($fichaId));

        return [
            'ficha'          => $ficha['codigo_ficha'],
            'programa'       => $ficha['programa'],
            'ambiente_id'    => $ambienteId,
            'meta'           => $parser->meta,
            'ocupaciones'    => count($ocupaciones),
            'insertados'     => $insertados,
            'sin_instructor' => $sinInstructor,
            'otros_no_clase' => count($parser->otros),
            'errores'        => $errores,
            'sin_cambios'    => false,
        ];
    }
}
