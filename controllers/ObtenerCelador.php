<?php
session_start();
require_once "../config/conexion.php";

header('Content-Type: application/json');

if(!isset($_SESSION['id']) || ($_SESSION['rol'] ?? '') !== 'admin') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit();
}

$con = conexion();
mysqli_set_charset($con, 'utf8mb4'); // sin esto, unset($x['contraseña']) no borra el hash y se filtra

if(isset($_GET['id'])) {
    $celador_id = intval($_GET['id']);
    
    $query = "SELECT * FROM usuarios WHERE id = $celador_id AND rol = 'celador'";
    $result = mysqli_query($con, $query);
    
    if($result && mysqli_num_rows($result) > 0) {
        $celador = mysqli_fetch_assoc($result);
        
        // No enviar la contraseña
        unset($celador['contraseña']);
        
        echo json_encode([
            'success' => true,
            'celador' => $celador
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Celador no encontrado'
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
