<?php
/**
 * instructor_match.php — resolución automática de instructores del calendario.
 *
 * Los nombres que llegan del Google Sheet vienen recortados y en mayúsculas
 * ("CARLOS ARTURO", "OSCAR YANGUAS") y sin cédula, por lo que no coinciden
 * exactamente con `usuarios.nombre`. Estas funciones eliminan el mapeo manual
 * repetido mediante dos capas:
 *
 *   1. Memoria global de alias (tabla `instructor_alias`): una vez que un nombre
 *      del Sheet se enlaza a un usuario, se recuerda para TODAS las fichas.
 *   2. Auto-match por nombre: se enlaza automáticamente si existe UN solo
 *      instructor cuyo nombre completo contenga todas las palabras del Sheet.
 *      Si hay varias coincidencias (ambiguo), se deja para el mapeo manual.
 */

if (!function_exists('instructor_normalizar')) {
    /** Normaliza para comparar: MAYÚSCULAS, sin acentos, solo letras/números y espacios. */
    function instructor_normalizar(string $s): string
    {
        $s = mb_strtoupper(trim($s), 'UTF-8');
        $s = strtr($s, [
            'Á' => 'A', 'À' => 'A', 'Ä' => 'A', 'Â' => 'A',
            'É' => 'E', 'È' => 'E', 'Ë' => 'E', 'Ê' => 'E',
            'Í' => 'I', 'Ì' => 'I', 'Ï' => 'I', 'Î' => 'I',
            'Ó' => 'O', 'Ò' => 'O', 'Ö' => 'O', 'Ô' => 'O',
            'Ú' => 'U', 'Ù' => 'U', 'Ü' => 'U', 'Û' => 'U',
            'Ñ' => 'N',
        ]);
        $s = preg_replace('/[^A-Z0-9 ]/', ' ', $s);
        $s = preg_replace('/\s+/', ' ', $s);
        return trim($s);
    }

    /** Guarda/actualiza el alias global  nombre_del_sheet -> usuario. */
    function instructor_alias_guardar($con, string $nombreExcel, int $usuarioId): void
    {
        $norm = instructor_normalizar($nombreExcel);
        if ($norm === '' || $usuarioId <= 0) return;
        $ne  = mysqli_real_escape_string($con, $norm);
        $uid = intval($usuarioId);
        @mysqli_query($con,
            "INSERT INTO instructor_alias (nombre_norm, usuario_id) VALUES ('$ne', $uid)
             ON DUPLICATE KEY UPDATE usuario_id = VALUES(usuario_id)");
    }

    /**
     * Intenta resolver un nombre del Sheet a un usuario_id.
     * Orden: (1) alias recordado, (2) auto-match único por nombre.
     * Devuelve null si es ambiguo o no hay coincidencia (queda para mapeo manual).
     */
    function instructor_resolver($con, string $nombreExcel): ?int
    {
        $norm = instructor_normalizar($nombreExcel);
        if ($norm === '') return null;

        // 1. Memoria de alias
        $ne = mysqli_real_escape_string($con, $norm);
        $q  = @mysqli_query($con, "SELECT usuario_id FROM instructor_alias WHERE nombre_norm = '$ne' LIMIT 1");
        if ($q && ($r = mysqli_fetch_assoc($q))) {
            return intval($r['usuario_id']);
        }

        // 2. Auto-match: todas las palabras del Sheet deben aparecer en el nombre
        //    del instructor. Requiere >= 2 palabras para no enlazar por un nombre suelto.
        $tokens = array_values(array_filter(explode(' ', $norm), fn($t) => $t !== ''));
        if (count($tokens) < 2) return null;

        $iq = @mysqli_query($con, "SELECT id, nombre FROM usuarios WHERE rol = 'instructor'");
        if (!$iq) return null;

        $coincidencias = [];
        while ($u = mysqli_fetch_assoc($iq)) {
            $un = instructor_normalizar($u['nombre']);
            $ok = true;
            foreach ($tokens as $t) {
                if (!preg_match('/\b' . preg_quote($t, '/') . '\b/', $un)) { $ok = false; break; }
            }
            if ($ok) $coincidencias[] = intval($u['id']);
        }

        if (count($coincidencias) === 1) {
            // Coincidencia única y confiable: la recordamos para acelerar próximas fichas.
            instructor_alias_guardar($con, $nombreExcel, $coincidencias[0]);
            return $coincidencias[0];
        }
        return null; // 0 o múltiples -> mapeo manual
    }
}
