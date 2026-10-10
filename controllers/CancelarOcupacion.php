<?php
session_start();
require_once __DIR__ . '/../config/conexion.php';
$con = conexion();
$rol = $_SESSION['rol'] ?? '';

// Compatibilidad con el endpoint antiguo, sin una segunda ruta de permisos.
if (in_array($rol, ['admin', 'celador'], true)) {
    require __DIR__ . '/FinalizarOcupacion.php';
    exit;
}
if ($rol === 'instructor') {
    header('Location: ../views/instructor/mis_ocupaciones_instructor.php?error=usar_solicitud');
} elseif ($rol === 'vocero') {
    header('Location: ../views/vocero/calendario_vocero.php?error=usar_solicitud');
} else {
    header('Location: ../views/home.php');
}
exit;
