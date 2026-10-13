<?php
session_start();
if (!isset($_SESSION['id']) || $_SESSION['rol'] != 'instructor') {
    header("Location: ../../views/home.php");
    exit();
}
require_once "../../config/conexion.php";
require_once "../../config/csrf.php";
$con = conexion();
mysqli_set_charset($con, 'utf8mb4');

$instructor_id = intval($_SESSION['id']);
$instructor_nombre = $_SESSION['nombre'];

// Fichas a las que está asignado el instructor
$fichas = [];
$q = mysqli_query($con, "
    SELECT f.id, f.codigo_ficha, f.programa, f.jornada, f.ultima_sync,
           a.nombre AS ambiente_nombre, p.nombre AS piso_nombre, s.nombre AS sede_nombre,
           (SELECT COUNT(*) FROM historial_ocupacion h
             WHERE h.ficha_id = f.id AND h.usuario_id = $instructor_id) AS mis_ocupaciones
    FROM ficha_instructores fi
    JOIN fichas f ON fi.ficha_id = f.id
    LEFT JOIN ambientes a ON f.ambiente_id = a.id
    LEFT JOIN pisos p ON a.piso_id = p.id
    LEFT JOIN sedes s ON f.sede_id = s.id
    WHERE fi.usuario_id = $instructor_id
    GROUP BY f.id
    ORDER BY f.codigo_ficha");
while ($row = mysqli_fetch_assoc($q)) { $fichas[] = $row; }

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis Fichas - SAGA</title>
    <link rel="stylesheet" href="../../assets/css/mis_fichas_instructor.css?v=20261013">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body>
    <!-- Header -->
    <div class="header">
        <div class="logo">
            <button class="btn-menu" id="btnMenu"><i class="bi bi-list"></i></button>
            <h2>SAGA</h2>
        </div>
        <div class="header-info">
            <?php include __DIR__ . '/../_campana.php'; ?>
            <div class="usuario-info">
                <span class="usuario-nombre"><?php echo h($instructor_nombre); ?></span>
                <span class="usuario-tipo">Instructor</span>
            </div>
            <a class="btn-logout" href="../../controllers/logout.php"><i class="bi bi-box-arrow-right"></i></a>
        </div>
    </div>

    <!-- Sidebar -->
    <?php $pagina_actual = basename($_SERVER['PHP_SELF']); ?>
    <div class="modal-menu" id="modalMenu">
        <div class="sidebar">
            <h3 class="menu-titulo">PRINCIPAL</h3>
            <a href="dashboard_instructor.php" <?php echo $pagina_actual == 'dashboard_instructor.php' ? 'class="active"' : ''; ?>>
                <i class="bi bi-bar-chart-fill"></i>Dashboard
            </a>
            <a href="calendario_instructor.php" <?php echo $pagina_actual == 'calendario_instructor.php' ? 'class="active"' : ''; ?>>
                <i class="bi bi-calendar3"></i>Calendario
            </a>
            <a href="mis_ocupaciones_instructor.php" <?php echo $pagina_actual == 'mis_ocupaciones_instructor.php' ? 'class="active"' : ''; ?>>
                <i class="bi bi-clock-history"></i>Mis Ocupaciones
            </a>
            <a href="mis_fichas_instructor.php" <?php echo $pagina_actual == 'mis_fichas_instructor.php' ? 'class="active"' : ''; ?>>
                <i class="bi bi-journal-bookmark-fill"></i>Mis Fichas
            </a>
        </div>
    </div>

    <!-- Encabezado -->
    <div class="encabezado">
        <div>
            <h1><i class="bi bi-journal-bookmark-fill"></i> Mis Fichas</h1>
            <p>Las fichas de formación en las que estás asignado como instructor</p>
        </div>
    </div>

    <div class="fichas-wrap">
        <?php if (!$fichas): ?>
            <div class="estado-vacio">
                <div class="vacio-icono"><i class="bi bi-journal-x"></i></div>
                <h3>No tienes fichas asignadas</h3>
                <p>Cuando el administrador te asigne a una ficha (o el calendario del SENA te detecte), aparecerá aquí.</p>
            </div>
        <?php else: ?>
            <div class="fichas-grid">
                <?php foreach ($fichas as $f): ?>
                    <div class="ficha-card">
                        <div class="ficha-cab">
                            <span class="ficha-codigo"><i class="bi bi-journal-bookmark-fill"></i> <?php echo h($f['codigo_ficha']); ?></span>
                            <span class="ficha-badge"><?php echo (int)$f['mis_ocupaciones']; ?> clase<?php echo (int)$f['mis_ocupaciones'] == 1 ? '' : 's'; ?></span>
                        </div>
                        <h3 class="ficha-programa"><?php echo $f['programa'] ? h($f['programa']) : 'Programa sin nombre'; ?></h3>
                        <p class="ficha-info"><i class="bi bi-geo-alt"></i>
                            <?php echo h($f['sede_nombre'] ?: 'Sin sede'); ?>
                            <?php if ($f['ambiente_nombre']): ?> · <?php echo h($f['piso_nombre'] . ' - ' . $f['ambiente_nombre']); ?><?php endif; ?>
                        </p>
                        <p class="ficha-info"><i class="bi bi-arrow-repeat"></i>
                            Última sincronización: <?php echo $f['ultima_sync'] ? date('d/m/Y H:i', strtotime($f['ultima_sync'])) : 'nunca'; ?>
                        </p>
                        <a href="calendario_instructor.php" class="ficha-link"><i class="bi bi-calendar3"></i> Ver en el calendario</a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
        const btnMenu = document.getElementById('btnMenu');
        const modalMenu = document.getElementById('modalMenu');
        if (btnMenu && modalMenu) {
            btnMenu.addEventListener('click', () => modalMenu.classList.add('active'));
            modalMenu.addEventListener('click', (e) => { if (e.target === modalMenu) modalMenu.classList.remove('active'); });
        }
    </script>
</body>
</html>
