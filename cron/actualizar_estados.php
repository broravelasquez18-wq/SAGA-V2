<?php
/**
 * Automatiza los cambios de estado de las ocupaciones SIN intervención humana.
 *
 * Marca como 'finalizado' toda ocupación cuya hora de fin ya pasó (respeta las
 * finalizadas o canceladas manualmente). Pensado para ejecutarse por el
 * Programador de tareas de Windows / cron cada 1-2 minutos, de modo que los
 * cambios ocurran solos aunque nadie tenga SAGA abierto.
 *
 * Uso (CLI):  php cron/actualizar_estados.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/estados.php';

$con = conexion();
mysqli_set_charset($con, 'utf8mb4');

$n = expirar_ocupaciones($con);

echo date('Y-m-d H:i:s') . " - Ocupaciones finalizadas automáticamente: " . max(0, $n) . "\n";

mysqli_close($con);
