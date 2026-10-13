<?php
/**
 * Registro de llegada del instructor (check-in del celador).
 *
 * Las clases sincronizadas desde Google Sheets ya existen como ocupación, así
 * que el celador no crea otra: confirma que el instructor llegó (escaneando el
 * carnet o con el botón del listado). Se guarda la hora y quién la confirmó.
 *
 * Las horas se calculan en PHP (zona America/Bogota, ver conexion.php) y NO con
 * NOW() de MySQL, porque el contenedor de la BD puede estar en UTC.
 */

if (!defined('LLEGADA_TOLERANCIA_MIN')) {
    /** Minutos después del inicio a partir de los cuales es "tarde" / "no ha llegado". */
    define('LLEGADA_TOLERANCIA_MIN', 15);
    /** Desde cuántos minutos antes del inicio se puede registrar la llegada. */
    define('LLEGADA_ANTICIPACION_MIN', 60);
}

/**
 * Estado de llegada de una ocupación para mostrarlo en pantalla.
 * Requiere las claves fecha_inicio, fecha_fin, estado, llegada_en y usuario_rol.
 * @return array|null [clase, texto, icono] o null si no aplica (p. ej. no es instructor).
 */
function llegada_estado(array $o): ?array
{
    if (($o['usuario_rol'] ?? 'instructor') !== 'instructor') return null;
    $ini = strtotime($o['fecha_inicio']);

    if (!empty($o['llegada_en'])) {
        $lleg = strtotime($o['llegada_en']);
        $min  = (int) floor(($lleg - $ini) / 60);
        if ($min > LLEGADA_TOLERANCIA_MIN) {
            return ['tarde', 'Llegó tarde ' . date('H:i', $lleg) . " (+{$min} min)", 'bi-clock-history'];
        }
        return ['ok', 'Llegó ' . date('H:i', $lleg), 'bi-person-check-fill'];
    }
    if (in_array($o['estado'] ?? '', ['finalizado', 'cancelado'], true)) return null;
    if (date('Y-m-d', $ini) !== date('Y-m-d')) return null;   // solo clases de hoy
    if (time() > $ini + LLEGADA_TOLERANCIA_MIN * 60) {
        return ['no', 'No ha llegado', 'bi-person-x-fill'];
    }
    return ['pend', 'Pendiente de llegada', 'bi-hourglass-split'];
}

/** ¿Se puede registrar la llegada ahora? (hoy, activa, sin llegada, dentro de la ventana). */
function llegada_registrable(array $o): bool
{
    if (($o['usuario_rol'] ?? 'instructor') !== 'instructor') return false;
    if (!empty($o['llegada_en'])) return false;
    if (!in_array($o['estado'] ?? '', ['ocupado', 'proximo_a_desocupar'], true)) return false;
    $ini = strtotime($o['fecha_inicio']);
    $fin = strtotime($o['fecha_fin']);
    if (date('Y-m-d', $ini) !== date('Y-m-d')) return false;
    return time() >= $ini - LLEGADA_ANTICIPACION_MIN * 60 && time() < $fin;
}

/** Texto para el tipo de etiqueta en CSS (clases .llegada-ok, .llegada-tarde, …). */
function llegada_badge(array $o): string
{
    $e = llegada_estado($o);
    if (!$e) return '';
    [$clase, $texto, $icono] = $e;
    return '<span class="llegada-badge llegada-' . $clase . '"><i class="bi ' . $icono . '"></i> '
         . htmlspecialchars($texto, ENT_QUOTES, 'UTF-8') . '</span>';
}

/**
 * Texto de la columna "Llegada" en los reportes.
 * Las clases anteriores a esta función no tienen registro: se muestran como
 * "Sin registro" (no como "No llegó") para no culpar a nadie con datos históricos.
 */
function llegada_texto_reporte(array $o): string
{
    if (($o['usuario_rol'] ?? 'instructor') !== 'instructor') return '—';
    if (($o['estado'] ?? '') === 'cancelado') return 'Cancelada';
    if (!empty($o['llegada_en'])) {
        $ini  = strtotime($o['fecha_inicio']);
        $lleg = strtotime($o['llegada_en']);
        $min  = (int) floor(($lleg - $ini) / 60);
        return $min > LLEGADA_TOLERANCIA_MIN
            ? 'Tarde ' . date('H:i', $lleg) . " (+{$min} min)"
            : 'A tiempo ' . date('H:i', $lleg);
    }
    // alerta_no_llego: 1 = se avisó al admin (no llegó); 2 = clase anterior a esta función
    if ((int)($o['alerta_no_llego'] ?? 0) === 1) return 'No llegó';
    if (strtotime($o['fecha_inicio']) > time()) return 'Pendiente';
    return 'Sin registro';
}

/**
 * Avisa a los administradores (campana) de las clases de hoy cuyo instructor no
 * ha llegado pasada la tolerancia. Cada clase se avisa una sola vez
 * (alerta_no_llego). Es idempotente y barato: se puede llamar en cada carga de
 * página del celador/admin y también desde el cron.
 * @return int cuántas alertas nuevas se enviaron
 */
function alertar_no_llegadas(mysqli $con, int $sede_id = 0): int
{
    $hoy    = date('Y-m-d');
    $ahora  = date('Y-m-d H:i:s');
    $limite = date('Y-m-d H:i:s', time() - LLEGADA_TOLERANCIA_MIN * 60);
    $filtro = $sede_id > 0 ? 'AND p.sede_id = ' . intval($sede_id) : '';

    $q = @mysqli_query($con, "
        SELECT ho.id, ho.fecha_inicio, a.nombre AS ambiente, p.nombre AS piso, p.sede_id,
               CONCAT_WS(' ', u.nombre, u.apellido) AS instructor, f.codigo_ficha
        FROM historial_ocupacion ho
        JOIN usuarios u  ON u.id = ho.usuario_id AND u.rol = 'instructor'
        JOIN ambientes a ON a.id = ho.ambiente_id
        JOIN pisos p     ON p.id = a.piso_id
        LEFT JOIN fichas f ON f.id = ho.ficha_id
        WHERE ho.llegada_en IS NULL AND ho.alerta_no_llego = 0
          AND ho.estado IN ('ocupado','proximo_a_desocupar')
          AND DATE(ho.fecha_inicio) = '$hoy'
          AND ho.fecha_inicio <= '$limite' AND ho.fecha_fin > '$ahora'
          $filtro");
    if (!$q) return 0;

    $admins = [];
    $aq = @mysqli_query($con, "SELECT id FROM usuarios WHERE rol = 'admin' AND estado = 'activo'");
    while ($aq && ($r = mysqli_fetch_assoc($aq))) $admins[] = intval($r['id']);

    $enviadas = 0;
    while ($o = mysqli_fetch_assoc($q)) {
        $id = intval($o['id']);
        // Marcar primero (atómico) para no duplicar el aviso entre peticiones simultáneas.
        mysqli_query($con, "UPDATE historial_ocupacion SET alerta_no_llego = 1 WHERE id = $id AND alerta_no_llego = 0");
        if (mysqli_affected_rows($con) !== 1) continue;

        $msg = "{$o['instructor']} no ha registrado llegada a {$o['piso']} - {$o['ambiente']}"
             . ' (clase de las ' . date('H:i', strtotime($o['fecha_inicio'])) . ')'
             . ($o['codigo_ficha'] ? " · ficha {$o['codigo_ficha']}" : '') . '.';
        $msg_e = mysqli_real_escape_string($con, $msg);
        $url_e = mysqli_real_escape_string($con, 'ocupaciones_admin.php?sede_id=' . intval($o['sede_id']));
        foreach ($admins as $aid) {
            mysqli_query($con, "INSERT INTO notificaciones (usuario_id, tipo, titulo, mensaje, url)
                                VALUES ($aid, 'no_llego', 'Instructor sin llegada', '$msg_e', '$url_e')");
        }
        $enviadas++;
    }
    return $enviadas;
}
