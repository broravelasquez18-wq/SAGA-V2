<?php
session_start();
require_once "../config/csrf.php";

$error = $_GET['error'] ?? '';
$msg = $_GET['msg'] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#007832">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../assets/css/home.css?v=20260914-2">
    <title>SAGA | Gestión de ambientes</title>
</head>
<body>
    <header class="site-header">
        <a class="brand" href="home.php" aria-label="SAGA, inicio">
            <span class="brand-mark" aria-hidden="true">S</span>
            <span>SAGA</span>
        </a>
        <button class="header-login" id="abrirModal" type="button">
            Iniciar sesión <i class="bi bi-arrow-up-right" aria-hidden="true"></i>
        </button>
    </header>

    <main class="hero">
        <div class="hero-background" aria-hidden="true"></div>
        <section class="hero-copy" aria-labelledby="hero-title">
            <p class="eyebrow"><span></span> Gestión de ambientes</p>
            <h1 id="hero-title">Espacios que impulsan<br><em>el aprendizaje.</em></h1>
            <p class="hero-description">Centraliza la disponibilidad, reservas y seguimiento de los ambientes de formación en un solo lugar.</p>
            <div class="hero-actions">
                <button class="primary-button" id="abrirModal2" type="button">
                    Acceder a SAGA <i class="bi bi-arrow-right" aria-hidden="true"></i>
                </button>
                <a class="text-link" href="#beneficios">Conoce más <i class="bi bi-chevron-down" aria-hidden="true"></i></a>
            </div>
            <ul class="trust-list" aria-label="Beneficios principales">
                <li><i class="bi bi-check2" aria-hidden="true"></i> Información centralizada</li>
                <li><i class="bi bi-check2" aria-hidden="true"></i> Reservas sin conflictos</li>
            </ul>
        </section>

        <aside class="availability-card" aria-label="Resumen de SAGA">
            <div class="availability-header">
                <div class="availability-icon"><i class="bi bi-calendar2-check" aria-hidden="true"></i></div>
                <span>Gestión simplificada</span>
            </div>
            <div class="availability-content">
                <p>Consulta y organiza</p>
                <strong>cada ambiente</strong>
                <span>con información actualizada para tu jornada.</span>
            </div>
            <div class="availability-status"><span></span> Sistema disponible</div>
        </aside>
    </main>

    <section class="benefits" id="beneficios" aria-labelledby="benefits-title">
        <div class="section-intro">
            <p class="eyebrow eyebrow-dark"><span></span> Todo en un solo lugar</p>
            <h2 id="benefits-title">Una gestión más simple, ordenada y confiable.</h2>
        </div>
        <div class="benefit-grid">
            <article class="benefit-card">
                <div class="benefit-icon"><i class="bi bi-buildings" aria-hidden="true"></i></div>
                <h3>Ambientes organizados</h3>
                <p>Consulta los espacios de formación de forma clara y centralizada.</p>
            </article>
            <article class="benefit-card">
                <div class="benefit-icon"><i class="bi bi-calendar3" aria-hidden="true"></i></div>
                <h3>Reservas coordinadas</h3>
                <p>Planifica cada jornada con visibilidad sobre la disponibilidad.</p>
            </article>
            <article class="benefit-card">
                <div class="benefit-icon"><i class="bi bi-graph-up-arrow" aria-hidden="true"></i></div>
                <h3>Decisiones informadas</h3>
                <p>Haz seguimiento al uso de los ambientes cuando lo necesites.</p>
            </article>
        </div>
    </section>

    <footer class="site-footer">
        <span>SAGA · Sistema de Administración y Gestión de Ambientes</span>
        <span>© <?php echo date('Y'); ?></span>
    </footer>

    <div class="modal" id="modalLogin" role="dialog" aria-modal="true" aria-labelledby="login-title" aria-hidden="true">
        <div class="modal-content">
            <button class="cerrar" id="cerrarModal" type="button" aria-label="Cerrar inicio de sesión"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            <div class="modal-brand"><span class="brand-mark" aria-hidden="true">S</span> SAGA</div>
            <h2 id="login-title">Bienvenido de nuevo</h2>
            <p class="modal-subtitle">Ingresa tus datos para continuar.</p>

            <?php if ($msg === 'sesion_cerrada'): ?>
                <div class="alert-success" role="status"><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Sesión cerrada correctamente.</div>
            <?php endif; ?>
            <?php if ($msg === 'password_actualizada'): ?>
                <div class="alert-success" role="status">Contraseña actualizada. Ya puedes iniciar sesión.</div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert-error" role="alert">
                    <i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i>
                    <?php echo match ($error) {
                        'inactivo' => 'Usuario inactivo. Contacta al administrador.',
                        'sesion' => 'Tu sesión terminó o tus permisos cambiaron. Inicia sesión de nuevo.',
                        default => 'Cédula o contraseña incorrectos.'
                    }; ?>
                </div>
            <?php endif; ?>

            <form action="../controllers/loginController.php" method="POST" autocomplete="on">
                <?php echo csrf_field(); ?>
                <div class="field">
                    <label for="cedula">Cédula</label>
                    <div class="input-wrap"><i class="bi bi-person" aria-hidden="true"></i><input id="cedula" type="text" name="cedula" placeholder="Ingresa tu número de cédula" inputmode="numeric" autocomplete="username" required></div>
                </div>
                <div class="field">
                    <label for="password">Contraseña</label>
                    <div class="input-wrap">
                        <i class="bi bi-lock" aria-hidden="true"></i>
                        <input id="password" type="password" name="password" placeholder="Ingresa tu contraseña" autocomplete="current-password" required>
                        <button type="button" class="password-toggle" id="togglePassword" aria-label="Mostrar contraseña" aria-controls="password" title="Mostrar contraseña">
                            <i class="bi bi-eye" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>
                <a class="forgot-password" href="recuperar_password.php">Olvidé mi contraseña</a>
                <button type="submit" class="submit-button">Ingresar <i class="bi bi-arrow-right" aria-hidden="true"></i></button>
            </form>
        </div>
    </div>

    <script>
        const modal = document.getElementById('modalLogin');
        const openers = [document.getElementById('abrirModal'), document.getElementById('abrirModal2')];
        const closer = document.getElementById('cerrarModal');
        const cedula = document.getElementById('cedula');
        const password = document.getElementById('password');
        const togglePassword = document.getElementById('togglePassword');

        function mostrarPassword(visible) {
            password.type = visible ? 'text' : 'password';
            const label = visible ? 'Ocultar contraseña' : 'Mostrar contraseña';
            togglePassword.setAttribute('aria-label', label);
            togglePassword.title = label;
            togglePassword.querySelector('i').className = visible ? 'bi bi-eye-slash' : 'bi bi-eye';
        }
        togglePassword.addEventListener('click', () => mostrarPassword(password.type === 'password'));

        function abrirModal() {
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('modal-abierto');
            window.setTimeout(() => cedula.focus(), 100);
        }
        function cerrarModal() {
            mostrarPassword(false);
            modal.classList.remove('is-open');
            modal.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('modal-abierto');
        }
        openers.forEach(button => button.addEventListener('click', abrirModal));
        closer.addEventListener('click', cerrarModal);
        modal.addEventListener('click', event => { if (event.target === modal) cerrarModal(); });
        document.addEventListener('keydown', event => { if (event.key === 'Escape' && modal.classList.contains('is-open')) cerrarModal(); });
        <?php if ($error || $msg || isset($_GET['login'])): ?>abrirModal();<?php endif; ?>
    </script>
</body>
</html>
