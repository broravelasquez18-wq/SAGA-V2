<?php
/**
 * Funciones de protección CSRF
 */

function csrf_token(): string {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function csrf_validate(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    // Acepta el token por POST (formularios) o por GET (acciones lanzadas con
    // window.location, como eliminar / cambiar estado).
    $token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
    $stored = $_SESSION['csrf_token'] ?? '';
    if (empty($token) || empty($stored) || !hash_equals($stored, $token)) {
        http_response_code(403);
        die('Error de seguridad: token inválido. Por favor recarga la página e inténtalo de nuevo.');
    }
}
