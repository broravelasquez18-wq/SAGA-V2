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

// CASO 1: Obtener UN ambiente por ID (para disponibilidad, detalles, etc.)
if(isset($_GET['id'])) {
    $ambiente_id = intval($_GET['id']);
    
    $query = "SELECT * FROM ambientes WHERE id = $ambiente_id";
    $result = mysqli_query($con, $query);
    
    if($result && mysqli_num_rows($result) > 0) {
        $ambiente = mysqli_fetch_assoc($result);
        echo json_encode([
            'success' => true,
            'ambiente' => $ambiente
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Ambiente no encontrado'
        ]);
    }
}
// CASO 2: Obtener TODOS los ambientes de una sede (para formulario de agendamiento)
elseif(isset($_GET['sede_id'])) {
    $sede_id = intval($_GET['sede_id']);
    
    // Query para obtener ambientes de la sede seleccionada
    $query = "SELECT a.id, a.nombre, p.nombre as piso_nombre 
              FROM ambientes a 
              LEFT JOIN pisos p ON a.piso_id = p.id 
              WHERE p.sede_id = $sede_id
              ORDER BY p.nombre, a.nombre";
    
    $result = mysqli_query($con, $query);
    $ambientes = [];
    
    while($row = mysqli_fetch_assoc($result)) {
        $ambientes[] = [
            'id' => $row['id'],
            'nombre' => $row['nombre'],
            'piso_nombre' => $row['piso_nombre']
        ];
    }
    
    echo json_encode($ambientes);
}
// CASO 3: Sin parámetros
else {
    echo json_encode([
        'success' => false,
        'message' => 'ID o sede_id no proporcionado'
    ]);
}

mysqli_close($con);
?>