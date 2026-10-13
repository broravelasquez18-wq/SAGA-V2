<?php
/**
 * Helpers compartidos para las notificaciones in-app (campana).
 * Usados por views/_campana.php y controllers/ObtenerNotificaciones.php
 */
if (!function_exists('camp_icono')) {
    /** Devuelve [icono_bootstrap, clase_color] según el tipo de notificación. */
    function camp_icono($tipo) {
        switch ($tipo) {
            case 'solicitud_aprobada':  return ['bi-check-circle-fill', 'exito'];
            case 'solicitud_rechazada': return ['bi-x-circle-fill', 'error'];
            case 'solicitud_nueva':     return ['bi-envelope-paper-fill', 'info'];
            case 'no_llego':          return ['bi-person-x-fill', 'error'];
            case 'clase_cancelada':     return ['bi-calendar-x-fill', 'aviso'];
            case 'ocupacion_solicitud':    return ['bi-box-arrow-in-down', 'info'];
            case 'ocupacion_aprobada':     return ['bi-check-circle-fill', 'exito'];
            case 'ocupacion_rechazada':    return ['bi-x-circle-fill', 'error'];
            case 'finalizacion_solicitud': return ['bi-hourglass-split', 'info'];
            case 'finalizacion_aprobada':  return ['bi-check-circle-fill', 'exito'];
            case 'finalizacion_rechazada': return ['bi-x-circle-fill', 'error'];
            case 'cambio_jornada_nueva':    return ['bi-arrow-left-right', 'info'];
            case 'cambio_jornada_aprobada': return ['bi-check-circle-fill', 'exito'];
            case 'cambio_jornada_rechazada':return ['bi-x-circle-fill', 'error'];
            case 'aviso':               return ['bi-exclamation-triangle-fill', 'aviso'];
            default:                    return ['bi-info-circle-fill', 'info'];
        }
    }

    /** Tiempo relativo legible ("hace 5 min", "hace 2 h", fecha). */
    function camp_tiempo($fecha) {
        $ts = strtotime($fecha);
        $diff = time() - $ts;
        if ($diff < 60)     return 'hace un momento';
        if ($diff < 3600)   return 'hace ' . floor($diff / 60) . ' min';
        if ($diff < 86400)  return 'hace ' . floor($diff / 3600) . ' h';
        if ($diff < 604800) return 'hace ' . floor($diff / 86400) . ' d';
        return date('d/m/Y', $ts);
    }
}
