<?php
session_start();
require_once "../config/conexion.php";

header('Content-Type: application/json');

if (!isset($_SESSION['id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit();
}

$con = conexion();
mysqli_set_charset($con, 'utf8mb4');

if(isset($_GET['id'])) {
    $piso_id = intval($_GET['id']);
    
    $query = "SELECT * FROM pisos WHERE id = $piso_id";
    $result = mysqli_query($con, $query);
    
    if($result && mysqli_num_rows($result) > 0) {
        $piso = mysqli_fetch_assoc($result);
        echo json_encode([
            'success' => true,
            'piso' => $piso
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Piso no encontrado'
        ]);
    }
} else {
    echo json_encode([
        'success' => false,
        'message' => 'ID no proporcionado'
    ]);
}

mysqli_close($con);
?>