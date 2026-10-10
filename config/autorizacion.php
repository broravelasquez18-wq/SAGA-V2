<?php
function proteger_password(string $password): string {
    if ($password === '') return '';
    if (strlen($password) > 72 || str_contains($password, "\0")) {
        http_response_code(422);
        exit('La contrasena no puede superar 72 bytes ni contener caracteres nulos.');
    }
    return password_hash($password, PASSWORD_DEFAULT);
}

function firma_sesion(array $usuario): string {
    $columna = 'contrase' . chr(195) . chr(177) . 'a';
    return hash('sha256', json_encode([
        $usuario[$columna], $usuario['rol'], (int) $usuario['sede_id'],
        $usuario['tipo_contrato'], $usuario['estado']
    ]));
}

function validar_sesion_actual(mysqli $con): void {
    if (PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_ACTIVE || !isset($_SESSION['id'])) return;
    $id = (int) $_SESSION['id'];
    $s = $con->prepare('SELECT * FROM usuarios WHERE id=?');
    $s->bind_param('i', $id);
    $s->execute();
    $usuario = $s->get_result()->fetch_assoc();
    if ($usuario && $usuario['estado'] === 'activo' &&
        hash_equals(firma_sesion($usuario), $_SESSION['firma_sesion'] ?? '')) return;

    $_SESSION = [];
    session_destroy();
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    if (str_contains($script, '/views/')) {
        $base = strstr($script, '/views/', true);
        header('Location: ' . $base . '/views/home.php?error=sesion');
        exit;
    }
    http_response_code(401);
    exit('La sesion ha caducado. Vuelve a iniciar sesion.');
}

function puede_acceder_sede(int $sede): bool {
    if (!isset($_SESSION['id']) || $sede <= 0) return false;
    $rol = $_SESSION['rol'] ?? '';
    if ($rol === 'admin') return true;
    if ($rol === 'instructor' && ($_SESSION['tipo_contrato'] ?? '') === 'contratista') return true;
    return in_array($rol, ['instructor', 'celador', 'vocero'], true)
        && $sede === (int) ($_SESSION['sede_id'] ?? 0);
}

function exigir_sede(int $sede): void {
    if (!puede_acceder_sede($sede)) {
        http_response_code(403);
        exit('No tienes permiso para operar en esta sede.');
    }
}
