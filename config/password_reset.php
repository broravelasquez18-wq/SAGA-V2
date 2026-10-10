<?php
require_once __DIR__ . '/env.php';
cargarEnv();

function recuperacion_url(): string {
    $url = rtrim(getenv('APP_URL') ?: '', '/');
    $parts = parse_url($url);
    $local = in_array($parts['host'] ?? '', ['localhost', '127.0.0.1', '::1'], true);
    if (!$parts || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) ||
        isset($parts['query']) || isset($parts['fragment']) ||
        (($parts['scheme'] ?? '') !== 'https' && !(($parts['scheme'] ?? '') === 'http' && $local))) {
        throw new RuntimeException('Configurar APP_URL con la URL publica HTTPS de SAGA.');
    }
    return $url;
}

function recuperacion_limite(mysqli $db, string $clave, int $maximo): bool {
    $clave = hash('sha256', $clave);
    $db->begin_transaction();
    try {
        $s = $db->prepare('INSERT IGNORE INTO password_reset_limits (clave) VALUES (?)');
        $s->bind_param('s', $clave);
        $s->execute();
        $s = $db->prepare('SELECT intentos, inicio <= DATE_SUB(NOW(), INTERVAL 1 HOUR) AS vencido FROM password_reset_limits WHERE clave=? FOR UPDATE');
        $s->bind_param('s', $clave);
        $s->execute();
        $row = $s->get_result()->fetch_assoc();
        $permitido = $row['vencido'] || $row['intentos'] < $maximo;
        if ($permitido) {
            $sql = $row['vencido']
                ? 'UPDATE password_reset_limits SET intentos=1, inicio=NOW() WHERE clave=?'
                : 'UPDATE password_reset_limits SET intentos=intentos+1 WHERE clave=?';
            $s = $db->prepare($sql);
            $s->bind_param('s', $clave);
            $s->execute();
        }
        $db->commit();
        return $permitido;
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

function recuperacion_solicitar(mysqli $db, string $cedula, string $email, string $ip, callable $enviar): void {
    $base = recuperacion_url();
    if (!recuperacion_limite($db, 'ip:' . $ip, 10) ||
        !recuperacion_limite($db, 'usuario:' . strtolower($cedula), 5)) return;
    $s = $db->prepare('SELECT id, nombre, email FROM usuarios WHERE cedula=? AND email=? AND estado="activo" LIMIT 1');
    $s->bind_param('ss', $cedula, $email);
    $s->execute();
    $usuario = $s->get_result()->fetch_assoc();
    if (!$usuario || !filter_var($usuario['email'], FILTER_VALIDATE_EMAIL)) return;
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $id = (int) $usuario['id'];
    $db->begin_transaction();
    try {
        // Serializa solicitudes y consumo de enlaces para la misma cuenta.
        $s = $db->prepare('SELECT id FROM usuarios WHERE id=? FOR UPDATE');
        $s->bind_param('i', $id);
        $s->execute();
        $s->get_result()->fetch_assoc();
        $s = $db->prepare('SELECT creado_en > DATE_SUB(NOW(), INTERVAL 1 MINUTE) AS reciente FROM password_resets WHERE usuario_id=?');
        $s->bind_param('i', $id);
        $s->execute();
        $anterior = $s->get_result()->fetch_assoc();
        if ($anterior && $anterior['reciente']) { $db->commit(); return; }
        $s = $db->prepare('INSERT INTO password_resets (usuario_id, token_hash, email, expira_en) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE)) ON DUPLICATE KEY UPDATE token_hash=VALUES(token_hash), email=VALUES(email), expira_en=VALUES(expira_en), creado_en=NOW()');
        $s->bind_param('iss', $id, $hash, $usuario['email']);
        $s->execute();
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
    $enviado = false;
    try {
        $enviado = $enviar($usuario, $base . '/views/recuperar_password.php?token=' . $token);
    } finally {
        if (!$enviado) {
            $s = $db->prepare('DELETE FROM password_resets WHERE usuario_id=? AND token_hash=?');
            $s->bind_param('is', $id, $hash);
            $s->execute();
            error_log('SAGA: no se pudo enviar el correo de recuperacion.');
        }
    }
}

function recuperacion_usuario(mysqli $db, string $token): ?int {
    if (!preg_match('/\A[a-f0-9]{64}\z/', $token)) return null;
    $hash = hash('sha256', $token);
    $s = $db->prepare('SELECT r.usuario_id FROM password_resets r JOIN usuarios u ON u.id=r.usuario_id WHERE r.token_hash=? AND r.expira_en>NOW() AND u.estado="activo" AND BINARY u.email=BINARY r.email');
    $s->bind_param('s', $hash);
    $s->execute();
    $row = $s->get_result()->fetch_assoc();
    return $row ? (int) $row['usuario_id'] : null;
}

function recuperacion_password_valido(string $password): bool {
    return strlen($password) <= 72 && preg_match_all('/./us', $password) >= 10;
}

function recuperacion_cambiar(mysqli $db, string $token, string $password): bool {
    if (!recuperacion_password_valido($password)) return false;
    $id = recuperacion_usuario($db, $token);
    if ($id === null) return false;
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $db->begin_transaction();
    try {
        $s = $db->prepare('SELECT id FROM usuarios WHERE id=? FOR UPDATE');
        $s->bind_param('i', $id);
        $s->execute();
        $s->get_result()->fetch_assoc();
        // Revalida despues del bloqueo: el enlace solo puede consumirse una vez.
        if (recuperacion_usuario($db, $token) !== $id) { $db->rollback(); return false; }
        $columna = 'contrase' . chr(195) . chr(177) . 'a';
        $s = $db->prepare("UPDATE usuarios SET `$columna`=? WHERE id=?");
        $s->bind_param('si', $hash, $id);
        $s->execute();
        $s = $db->prepare('DELETE FROM password_resets WHERE usuario_id=?');
        $s->bind_param('i', $id);
        $s->execute();
        $db->commit();
        return true;
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}
