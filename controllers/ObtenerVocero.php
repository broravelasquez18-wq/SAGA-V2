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
    $id = intval($_GET['id']);
    $r  = mysqli_query($con, "SELECT u.*, f.id AS ficha_id, f.codigo_ficha
                              FROM usuarios u
                              LEFT JOIN fichas f ON f.vocero_id = u.id
                              WHERE u.id=$id AND u.rol='vocero'");
    if($r && mysqli_num_rows($r) > 0) {
        $v = mysqli_fetch_assoc($r);
        unset($v['contraseña']);
        echo json_encode(['success' => true, 'vocero' => $v]);
    } else {
        echo json_encode(['success' => false]);
    }
} else {
    echo json_encode(['success' => false]);
}
mysqli_close($con);
?>
