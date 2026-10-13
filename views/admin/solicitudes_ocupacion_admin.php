<?php
session_start();
if (!isset($_SESSION['id']) || $_SESSION['rol'] != 'admin') {
    header("Location: ../../views/home.php");
    exit();
}
require_once "../../config/conexion.php";
require_once "../../config/csrf.php";
$con = conexion();
mysqli_set_charset($con, 'utf8mb4');

// Solicitudes de ocupación (todas las sedes; pendientes primero)
$solicitudes = [];
$q = mysqli_query($con, "
    SELECT so.id, so.jornada, so.fecha_inicio, so.fecha_fin, so.motivo, so.estado, so.creado_en, so.resuelto_en,
           a.nombre AS ambiente_nombre, p.nombre AS piso_nombre, s.nombre AS sede_nombre,
           f.codigo_ficha,
           CONCAT_WS(' ', v.nombre, v.apellido) AS vocero_nombre,
           CONCAT_WS(' ', ur.nombre, ur.apellido) AS resuelto_nombre
    FROM solicitudes_ocupacion so
    JOIN ambientes a ON so.ambiente_id = a.id
    JOIN pisos p ON a.piso_id = p.id
    JOIN sedes s ON p.sede_id = s.id
    LEFT JOIN fichas f ON so.ficha_id = f.id
    LEFT JOIN usuarios v ON so.vocero_id = v.id
    LEFT JOIN usuarios ur ON so.resuelto_por = ur.id
    ORDER BY (so.estado = 'pendiente') DESC, so.creado_en DESC");
while ($row = mysqli_fetch_assoc($q)) { $solicitudes[] = $row; }

$n_pendientes = 0;
foreach ($solicitudes as $s) { if ($s['estado'] === 'pendiente') $n_pendientes++; }

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$msg = $_GET['msg'] ?? ''; $error = $_GET['error'] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Solicitudes de ocupación - SAGA</title>
    <link rel="stylesheet" href="../../assets/css/solicitudes_admin.css?v=20261013">
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
                <span class="usuario-nombre"><?php echo h($_SESSION['nombre']); ?></span>
                <span class="usuario-tipo">Administrador</span>
            </div>
            <a class="btn-logout" href="../../controllers/logout.php"><i class="bi bi-box-arrow-right"></i></a>
        </div>
    </div>

    <?php include __DIR__ . '/_sidebar.php'; ?>

    <!-- Encabezado -->
    <div class="encabezado">
        <div>
            <h1><i class="bi bi-box-arrow-in-down"></i> Solicitudes de ocupación</h1>
            <p>Voceros que piden ocupar el ambiente de su ficha — aprueba o rechaza</p>
        </div>
        <div class="resumen-pendientes <?php echo $n_pendientes > 0 ? 'alerta' : ''; ?>">
            <span class="np"><?php echo $n_pendientes; ?></span> pendiente<?php echo $n_pendientes == 1 ? '' : 's'; ?>
        </div>
    </div>

    <?php if ($msg === 'ocupacion_aprobada'): ?>
        <div class="banner ok"><i class="bi bi-check-circle-fill"></i> Solicitud aprobada: la ocupación fue creada.</div>
    <?php elseif ($msg === 'ocupacion_rechazada'): ?>
        <div class="banner info"><i class="bi bi-x-circle-fill"></i> Solicitud rechazada.</div>
    <?php elseif ($error): ?>
        <div class="banner err"><i class="bi bi-exclamation-triangle-fill"></i>
            <?php echo match($error) {
                'ambiente_ocupado' => 'No se pudo aprobar: el ambiente ya está ocupado en ese horario.',
                'ya_resuelta'      => 'Esa solicitud ya había sido resuelta.',
                'no_encontrada'    => 'No se encontró la solicitud.',
                default            => 'Ocurrió un error. Inténtalo de nuevo.',
            }; ?>
        </div>
    <?php endif; ?>

    <div class="lista-solicitudes">
        <?php if (!$solicitudes): ?>
            <div class="estado-vacio">
                <div class="vacio-icono"><i class="bi bi-inbox"></i></div>
                <h3>No hay solicitudes</h3>
                <p>Cuando un vocero pida ocupar su ambiente, aparecerá aquí.</p>
            </div>
        <?php else: foreach ($solicitudes as $s):
            $fi = strtotime($s['fecha_inicio']); $ff = strtotime($s['fecha_fin']);
        ?>
            <div class="solicitud-card <?php echo h($s['estado']); ?>">
                <div class="solicitud-main">
                    <div class="solicitud-cab">
                        <span class="badge-estado <?php echo h($s['estado']); ?>">
                            <?php echo ['pendiente'=>'Pendiente','aprobada'=>'Aprobada','rechazada'=>'Rechazada'][$s['estado']] ?? h($s['estado']); ?>
                        </span>
                        <span class="solicitud-fecha"><i class="bi bi-clock"></i> <?php echo date('d/m/Y H:i', strtotime($s['creado_en'])); ?></span>
                    </div>
                    <h3><i class="bi bi-person-badge"></i> <?php echo h($s['vocero_nombre'] ?: 'Vocero'); ?><?php echo $s['codigo_ficha'] ? ' · Ficha ' . h($s['codigo_ficha']) : ''; ?></h3>
                    <p class="solicitud-ocup">
                        <i class="bi bi-door-open"></i> <?php echo h($s['piso_nombre'].' - '.$s['ambiente_nombre']); ?>
                        &nbsp;·&nbsp; <i class="bi bi-building"></i> <?php echo h($s['sede_nombre']); ?>
                        &nbsp;·&nbsp; <i class="bi bi-clock"></i> <?php echo date('H:i', $fi).' - '.date('H:i', $ff); ?>
                        (<?php echo h(ucfirst($s['jornada'])); ?>)
                    </p>
                    <?php if (!empty($s['motivo'])): ?>
                        <p class="solicitud-motivo"><i class="bi bi-chat-left-quote"></i> <strong>Motivo:</strong> <?php echo h($s['motivo']); ?></p>
                    <?php endif; ?>
                    <?php if ($s['estado'] !== 'pendiente'): ?>
                        <p class="solicitud-resuelto">
                            Resuelta por <strong><?php echo h($s['resuelto_nombre'] ?: '—'); ?></strong>
                            el <?php echo $s['resuelto_en'] ? date('d/m/Y H:i', strtotime($s['resuelto_en'])) : '—'; ?>
                        </p>
                    <?php endif; ?>
                </div>

                <?php if ($s['estado'] === 'pendiente'): ?>
                <div class="solicitud-acciones">
                    <form method="POST" action="../../controllers/ResolverOcupacionSolicitud.php"
                          onsubmit="return confirm('¿Aprobar y crear la ocupación?')">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="solicitud_id" value="<?php echo (int)$s['id']; ?>">
                        <input type="hidden" name="accion" value="aprobar">
                        <button type="submit" class="btn btn-aprobar"><i class="bi bi-check-lg"></i> Aprobar</button>
                    </form>
                    <form method="POST" action="../../controllers/ResolverOcupacionSolicitud.php"
                          onsubmit="return confirm('¿Rechazar la solicitud?')">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="solicitud_id" value="<?php echo (int)$s['id']; ?>">
                        <input type="hidden" name="accion" value="rechazar">
                        <button type="submit" class="btn btn-rechazar"><i class="bi bi-x-lg"></i> Rechazar</button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        <?php endforeach; endif; ?>
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
