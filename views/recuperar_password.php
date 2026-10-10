<?php
session_start();
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/password_reset.php';

// Retira el token de la URL antes de cargar recursos o mostrar el formulario.
if (isset($_GET['token'])) {
    $token = is_string($_GET['token']) ? $_GET['token'] : '';
    $_SESSION['password_reset_token'] = preg_match('/\A[a-f0-9]{64}\z/', $token) ? $token : '';
    header('Location: recuperar_password.php?modo=nueva', true, 303);
    exit;
}
$nueva = ($_GET['modo'] ?? '') === 'nueva';
$error = '';
$enviado = false;
$valido = false;
$token = $_SESSION['password_reset_token'] ?? '';
try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    if ($nueva || $_SERVER['REQUEST_METHOD'] === 'POST') {
        $db = conexion();
        $db->set_charset('utf8mb4');
    }
    if ($nueva) $valido = recuperacion_usuario($db, $token) !== null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrf = $_POST['csrf_token'] ?? '';
        if (!is_string($csrf) || !hash_equals(csrf_token(), $csrf)) {
            http_response_code(403);
            $error = 'La sesión del formulario venció. Recarga la página e inténtalo nuevamente.';
        } elseif ($nueva) {
            $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
            $confirmacion = is_string($_POST['confirmacion'] ?? null) ? $_POST['confirmacion'] : '';
            if (!$valido) {
                $error = 'El enlace no es válido o ya venció. Solicita uno nuevo.';
            } elseif (!recuperacion_password_valido($password)) {
                $error = 'Usa al menos 10 caracteres. Si la contraseña es muy larga, elige una más corta.';
            } elseif ($password !== $confirmacion) {
                $error = 'Las contraseñas no coinciden.';
            } elseif (recuperacion_cambiar($db, $token, $password)) {
                unset($_SESSION['password_reset_token']);
                header('Location: home.php?msg=password_actualizada', true, 303);
                exit;
            } else {
                $valido = false;
                $error = 'El enlace no es válido o ya fue utilizado. Solicita uno nuevo.';
            }
        } else {
            $cedula = is_string($_POST['cedula'] ?? null) ? trim($_POST['cedula']) : '';
            $email = is_string($_POST['email'] ?? null) ? trim($_POST['email']) : '';
            if ($cedula === '' || strlen($cedula) > 50 || strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Escribe tu cédula o usuario y un correo válido.';
            } else {
                require_once __DIR__ . '/../config/sendgrid.php';
                recuperacion_solicitar($db, $cedula, $email, $_SERVER['REMOTE_ADDR'] ?? 'unknown', function (array $usuario, string $url): bool {
                    $link = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
                    $html = _emailBase('Restablecer contraseña', '<p>Recibimos una solicitud para cambiar tu contraseña de SAGA.</p><p><a href="' . $link . '">Crear una contraseña nueva</a></p><p>Este enlace vence en 30 minutos y solo puede usarse una vez.</p><p>Si no solicitaste el cambio, ignora este correo. Tu contraseña seguirá igual.</p>');
                    return enviarEmail($usuario['email'], $usuario['nombre'], 'Restablecer tu contraseña de SAGA', $html);
                });
                $_SESSION['reset_solicitado'] = true;
                header('Location: recuperar_password.php?enviado=1', true, 303);
                exit;
            }
        }
    }
} catch (Throwable $e) {
    error_log('SAGA recuperacion: fallo de configuracion, base de datos o correo. Codigo ' . $e->getCode());
    $error = 'La recuperación no está disponible en este momento. Intenta más tarde o contacta al administrador.';
}
if (isset($_GET['enviado']) && !empty($_SESSION['reset_solicitado'])) {
    $enviado = true;
    unset($_SESSION['reset_solicitado']);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Recuperar contraseña | SAGA</title>
    <link rel="stylesheet" href="../assets/css/home.css?v=20260914-2">
</head>
<body class="recovery-page">
<main class="recovery-card">
    <a class="brand" href="home.php"><span class="brand-mark">S</span> SAGA</a>
    <h1><?= $nueva ? 'Crea tu nueva contraseña' : '¿Olvidaste tu contraseña?' ?></h1>
    <?php if ($error): ?><div class="alert-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <?php if ($enviado): ?>
        <div class="alert-success" role="status">Si los datos corresponden a una cuenta activa, recibirás un enlace en el correo registrado. Revisa también la carpeta de spam. Si ya lo solicitaste, espera unos minutos antes de volver a intentarlo.</div>
    <?php elseif ($nueva && !$valido): ?>
        <p>El enlace no está disponible, venció o ya fue utilizado.</p>
        <a class="back-login" href="recuperar_password.php">Solicitar un enlace nuevo</a>
    <?php else: ?>
        <p><?= $nueva ? 'Elige una contraseña que no uses en otros servicios.' : 'Ingresa tu cédula o usuario y el correo registrado en SAGA. Te enviaremos un enlace válido durante 30 minutos.' ?></p>
        <form method="POST" action="recuperar_password.php<?= $nueva ? '?modo=nueva' : '' ?>">
            <?= csrf_field() ?>
            <?php if ($nueva): ?>
                <div class="field">
                    <label for="password">Nueva contraseña</label>
                    <div class="input-wrap"><input id="password" name="password" type="password" autocomplete="new-password" minlength="10" maxlength="72" required></div>
                    <small>Usa al menos 10 caracteres.</small>
                </div>
                <div class="field">
                    <label for="confirmacion">Confirma la contraseña</label>
                    <div class="input-wrap"><input id="confirmacion" name="confirmacion" type="password" autocomplete="new-password" minlength="10" maxlength="72" required></div>
                </div>
                <button type="button" class="forgot-password" id="verPassword">Mostrar contraseñas</button>
                <button type="submit" class="submit-button">Guardar nueva contraseña</button>
            <?php else: ?>
                <div class="field">
                    <label for="cedula">Cédula o usuario</label>
                    <div class="input-wrap"><input id="cedula" name="cedula" autocomplete="username" maxlength="50" required></div>
                </div>
                <div class="field">
                    <label for="email">Correo registrado</label>
                    <div class="input-wrap"><input id="email" name="email" type="email" autocomplete="email" maxlength="150" required></div>
                </div>
                <button type="submit" class="submit-button">Enviar enlace de recuperación</button>
            <?php endif; ?>
        </form>
    <?php endif; ?>
    <a class="back-login" href="home.php?login=1">Volver al inicio de sesión</a>
</main>
<script>
const ver = document.getElementById('verPassword');
if (ver) ver.addEventListener('click', () => {
    const password = document.getElementById('password');
    const visible = password.type === 'password';
    password.type = document.getElementById('confirmacion').type = visible ? 'text' : 'password';
    ver.textContent = visible ? 'Ocultar contraseñas' : 'Mostrar contraseñas';
});
</script>
</body>
</html>
