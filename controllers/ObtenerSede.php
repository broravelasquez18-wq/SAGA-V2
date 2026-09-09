<?php
session_start();
require_once "../config/conexion.php";

$con = conexion();
mysqli_set_charset($con, 'utf8mb4');

// Verificar que sea admin
if(!isset($_SESSION['id']) || $_SESSION['rol'] != 'admin') {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit();
}

// Obtener ID de la sede
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID inválido']);
    exit();
}

// Consultar la sede
$query = "SELECT * FROM sedes WHERE id = $id";
$result = mysqli_query($con, $query);

if($result && mysqli_num_rows($result) > 0) {
    $sede = mysqli_fetch_assoc($result);
    echo json_encode(['success' => true, 'sede' => $sede]);
} else {
    echo json_encode(['success' => false, 'message' => 'Sede no encontrada']);
}

mysqli_close($con);
?>