<?php
session_start();

// Verificar sesión de vocero
if(!isset($_SESSION['id']) || $_SESSION['rol'] != 'vocero') {
    header("Location: ../../views/home.php");
    exit();
}

require_once "../../config/conexion.php";
require_once "../../config/csrf.php";
$con = conexion();
mysqli_set_charset($con, 'utf8mb4');

$vocero_id     = intval($_SESSION['id']);
$vocero_nombre = $_SESSION['nombre'];

// Ficha del vocero (1 vocero = 1 ficha)
$ficha = mysqli_fetch_assoc(mysqli_query($con,
    "SELECT id, codigo_ficha, programa FROM fichas WHERE vocero_id = $vocero_id AND activa = 1 LIMIT 1"));
$ficha_id = $ficha ? intval($ficha['id']) : 0;

// Filtro de estado
$filtro = $_GET['filtro'] ?? 'todos';

// Query base (por ficha)
$query_base = "SELECT
    h.id, h.fecha_inicio, h.fecha_fin, h.jornada, h.observaciones, h.estado,
    a.nombre AS ambiente_nombre, p.nombre AS piso_nombre, s.nombre AS sede_nombre,
    CONCAT_WS(' ', ui.nombre, ui.apellido) AS instructor_nombre
FROM historial_ocupacion h
INNER JOIN ambientes a ON h.ambiente_id = a.id
INNER JOIN pisos p ON a.piso_id = p.id
INNER JOIN sedes s ON p.sede_id = s.id
LEFT JOIN usuarios ui ON h.usuario_id = ui.id
WHERE h.ficha_id = $ficha_id";

switch($filtro) {
    case 'activos':      $query_base .= " AND h.estado = 'ocupado' AND h.fecha_inicio <= NOW() AND h.fecha_fin >= NOW()"; break;
    case 'proximos':     $query_base .= " AND h.estado = 'ocupado' AND h.fecha_inicio > NOW()"; break;
    case 'finalizados':  $query_base .= " AND h.estado = 'finalizado'"; break;
    case 'cancelados':   $query_base .= " AND h.estado = 'cancelado'"; break;
}
$query_base .= " ORDER BY h.fecha_inicio DESC";

$ocupaciones     = $ficha_id > 0 ? mysqli_query($con, $query_base) : false;
$total_registros = $ocupaciones ? mysqli_num_rows($ocupaciones) : 0;

// Estadísticas por ficha
function cuenta($con, $ficha_id, $extra) {
    $r = mysqli_query($con, "SELECT COUNT(*) t FROM historial_ocupacion WHERE ficha_id = $ficha_id $extra");
    return ($r && ($x = mysqli_fetch_assoc($r))) ? (int)$x['t'] : 0;
}
$stats_total       = $ficha_id ? cuenta($con, $ficha_id, "") : 0;
$stats_activos     = $ficha_id ? cuenta($con, $ficha_id, "AND estado='ocupado' AND fecha_inicio<=NOW() AND fecha_fin>=NOW()") : 0;
$stats_proximos    = $ficha_id ? cuenta($con, $ficha_id, "AND estado='ocupado' AND fecha_inicio>NOW()") : 0;
$stats_finalizados = $ficha_id ? cuenta($con, $ficha_id, "AND estado='finalizado'") : 0;
$stats_cancelados  = $ficha_id ? cuenta($con, $ficha_id, "AND estado='cancelado'") : 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis Ocupaciones - Vocero - SAGA</title>
    <link rel="stylesheet" href="../../assets/css/mis_ocupaciones_vocero.css?v=20261013">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body>
    <!-- Header -->
    <div class="header">
        <div class="logo">
            <button class="btn-menu" id="btnMenu"><i class="bi bi-list"></i></button>
            <h2>SAGA</h2>
        </div>
        <div class="header-info"><?php include __DIR__ . '/../_campana.php'; ?>
            <div class="usuario-info">
                <span class="usuario-nombre"><?php echo htmlspecialchars($vocero_nombre); ?></span>
                <span class="usuario-tipo">Vocero</span>
            </div>
            <a class="btn-logout" href="../../controllers/logout.php"><i class="bi bi-box-arrow-right"></i></a>
        </div>
    </div>

    <!-- Sidebar -->
    <?php $pagina_actual = basename($_SERVER['PHP_SELF']); ?>
    <div class="modal-menu" id="modalMenu">
        <div class="sidebar">
            <h3 class="menu-titulo">PRINCIPAL</h3>
            <a href="calendario_vocero.php"><i class="bi bi-calendar3"></i>Calendario</a>
            <a href="mis_ocupaciones_vocero.php" class="active"><i class="bi bi-clock-history"></i>Mis Ocupaciones</a>
        </div>
    </div>

    <!-- Encabezado -->
    <div class="encabezado">
        <div>
            <h1><i class="bi bi-clipboard"></i> Ocupaciones de mi ficha</h1>
            <p><?php echo $ficha ? 'Ficha ' . htmlspecialchars($ficha['codigo_ficha']) . ($ficha['programa'] ? ' · ' . htmlspecialchars($ficha['programa']) : '') : 'Sin ficha asignada'; ?></p>
        </div>
    </div>

    <!-- Estadísticas -->
    <div class="estadisticas">
        <div class="stat-card"><div class="stat-icono"><i class="bi bi-bar-chart-line"></i></div><div class="stat-info"><span class="stat-numero"><?php echo $stats_total; ?></span><span class="stat-label">Total Registros</span></div></div>
        <div class="stat-card"><div class="stat-icono"><i class="bi bi-circle-fill text-danger" style="font-size:.7rem"></i></div><div class="stat-info"><span class="stat-numero"><?php echo $stats_activos; ?></span><span class="stat-label">Activos Ahora</span></div></div>
        <div class="stat-card"><div class="stat-icono"><i class="bi bi-circle-fill text-warning" style="font-size:.7rem"></i></div><div class="stat-info"><span class="stat-numero"><?php echo $stats_proximos; ?></span><span class="stat-label">Próximos</span></div></div>
        <div class="stat-card"><div class="stat-icono"><i class="bi bi-circle-fill text-success" style="font-size:.7rem"></i></div><div class="stat-info"><span class="stat-numero"><?php echo $stats_finalizados; ?></span><span class="stat-label">Finalizados</span></div></div>
    </div>

    <!-- Filtros -->
    <div class="filtros-ocupaciones">
        <a href="?filtro=todos"       class="filtro-btn <?php echo $filtro=='todos'?'active':''; ?>"><i class="bi bi-bar-chart-line"></i> Todos (<?php echo $stats_total; ?>)</a>
        <a href="?filtro=activos"     class="filtro-btn <?php echo $filtro=='activos'?'active':''; ?>"><i class="bi bi-circle-fill text-danger" style="font-size:.7rem"></i> Activos (<?php echo $stats_activos; ?>)</a>
        <a href="?filtro=proximos"    class="filtro-btn <?php echo $filtro=='proximos'?'active':''; ?>"><i class="bi bi-circle-fill text-warning" style="font-size:.7rem"></i> Próximos (<?php echo $stats_proximos; ?>)</a>
        <a href="?filtro=finalizados" class="filtro-btn <?php echo $filtro=='finalizados'?'active':''; ?>"><i class="bi bi-circle-fill text-success" style="font-size:.7rem"></i> Finalizados (<?php echo $stats_finalizados; ?>)</a>
        <a href="?filtro=cancelados"  class="filtro-btn <?php echo $filtro=='cancelados'?'active':''; ?>"><i class="bi bi-circle-fill"></i> Cancelados (<?php echo $stats_cancelados; ?>)</a>
    </div>

    <!-- Lista -->
    <div class="ocupaciones-lista-container">
        <div class="total-registros">Mostrando <?php echo $total_registros; ?> registro(s)</div>

        <?php if($total_registros > 0): ?>
            <div class="ocupaciones-lista">
                <?php while($ocu = mysqli_fetch_assoc($ocupaciones)):
                    $ahora = new DateTime();
                    $fi = new DateTime($ocu['fecha_inicio']);
                    $ff = new DateTime($ocu['fecha_fin']);
                    if($ocu['estado'] == 'cancelado')      { $clase='cancelado';  $texto='Cancelado'; }
                    elseif($ocu['estado'] == 'finalizado') { $clase='finalizado'; $texto='Finalizado'; }
                    elseif($ahora >= $fi && $ahora <= $ff) { $clase='activo';     $texto='En Curso'; }
                    else                                   { $clase='proximo';    $texto='Próximo'; }
                ?>
                    <div class="ocupacion-item <?php echo $clase; ?>">
                        <div class="ocupacion-fecha-box">
                            <div class="fecha-dia"><?php echo $fi->format('d'); ?></div>
                            <div class="fecha-mes"><?php echo ucfirst($fi->format('M')); ?></div>
                            <div class="fecha-ano"><?php echo $fi->format('Y'); ?></div>
                        </div>
                        <div class="ocupacion-detalles">
                            <h3><?php echo htmlspecialchars($ocu['ambiente_nombre']); ?></h3>
                            <p class="ocupacion-ubicacion"><i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars($ocu['sede_nombre'] . ' - ' . $ocu['piso_nombre']); ?></p>
                            <p class="ocupacion-horario"><i class="bi bi-clock"></i> <?php echo $fi->format('H:i'); ?> - <?php echo $ff->format('H:i'); ?> (<?php echo htmlspecialchars(ucfirst($ocu['jornada'])); ?>)</p>
                            <p class="ocupacion-ubicacion"><i class="bi bi-person"></i> <?php echo htmlspecialchars($ocu['instructor_nombre'] ?: 'Por confirmar'); ?></p>
                            <?php if(!empty($ocu['observaciones'])): ?>
                                <p class="ocupacion-obs"><i class="bi bi-card-text"></i> <?php echo htmlspecialchars($ocu['observaciones']); ?></p>
                            <?php endif; ?>
                            <span class="ocupacion-estado-badge <?php echo $clase; ?>"><?php echo $texto; ?></span>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <div class="estado-vacio">
                <div class="vacio-icono"><i class="bi bi-clipboard-data"></i></div>
                <h3>No hay ocupaciones</h3>
                <p><?php echo $ficha ? 'Tu ficha aún no tiene clases en este filtro.' : 'Contacta al administrador para que te asigne una ficha.'; ?></p>
                <a href="calendario_vocero.php" class="btn-agendar"><i class="bi bi-calendar-event"></i> Ir al Calendario</a>
            </div>
        <?php endif; ?>
    </div>

    <script>
        const btnMenu = document.getElementById('btnMenu');
        const modalMenu = document.getElementById('modalMenu');
        btnMenu.addEventListener('click', () => modalMenu.classList.add('active'));
        modalMenu.addEventListener('click', (e) => { if(e.target === modalMenu) modalMenu.classList.remove('active'); });
    </script>
</body>
</html>
