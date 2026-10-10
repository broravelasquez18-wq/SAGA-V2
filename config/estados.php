<?php
/**
 * Autocorrección de estados de ocupación.
 *
 * El estado ('ocupado'/'finalizado') se fija al registrar o sincronizar la
 * ocupación, pero no se actualiza solo cuando pasa la hora de fin. Esta función
 * marca como 'finalizado' toda ocupación cuya fecha_fin ya pasó, para que las
 * tarjetas, estadísticas y la ocupación de ambientes reflejen la hora real.
 *
 * Se usa la hora de PHP (timezone America/Bogota definido en conexion.php) en
 * vez de NOW() de MySQL, para respetar la zona horaria correcta.
 */
if (!function_exists('expirar_ocupaciones')) {
    function expirar_ocupaciones($con): int
    {
        $ahora = date('Y-m-d H:i:s');
        mysqli_query($con,
            "UPDATE historial_ocupacion
                SET estado = 'finalizado'
              WHERE fecha_fin <= '$ahora'
                AND estado NOT IN ('finalizado', 'cancelado')");
        $cantidad = mysqli_affected_rows($con);
        sincronizar_estados_ambientes($con);
        return $cantidad;
    }
}

function sincronizar_estados_ambientes(mysqli $con): void
{
    $ahora = date('Y-m-d H:i:s');
    $s = $con->prepare("UPDATE ambientes a SET estado = CASE WHEN EXISTS (
        SELECT 1 FROM historial_ocupacion h WHERE h.ambiente_id=a.id
          AND h.estado IN ('ocupado','proximo_a_desocupar')
          AND h.fecha_inicio <= ? AND h.fecha_fin > ?
    ) THEN IF(a.estado='proximo_a_desocupar','proximo_a_desocupar','ocupado') ELSE 'disponible' END
    WHERE a.estado IN ('ocupado','disponible','proximo_a_desocupar')");
    $s->bind_param('ss', $ahora, $ahora);
    $s->execute();
}
