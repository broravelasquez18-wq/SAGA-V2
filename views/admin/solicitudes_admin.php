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

$sede_id = isset($_GET['sede_id']) ? intval($_GET['sede_id']) : 0;
if ($sede_id <= 0) { header("Location: index_sedes.php"); exit(); }

$sq = mysqli_query($con, "SELECT nombre FROM sedes WHERE id = $sede_id");
if (!$sq || mysqli_num_rows($sq) == 0) { header("Location: index_sedes.php"); exit(); }
$sede_nombre = mysqli_fetch_assoc($sq)['nombre'];

// Solicitudes de la sede (pendientes primero)
$solicitudes = [];
$q = mysqli_query($con, "
    SELECT sc.id, sc.motivo, sc.adjunto, sc.estado, sc.respuesta, sc.creado_en, sc.resuelto_en,
           CONCAT_WS(' ', u.nombre, u.apellido) AS instructor_nombre,
           a.nombre AS ambiente_nombre, p.nombre AS piso_nombre,
           ho.fecha_inicio, ho.fecha_fin, ho.jornada, ho.estado AS ocupacion_estado,
           CONCAT_WS(' ', ur.nombre, ur.apellido) AS resuelto_nombre
    FROM solicitudes_cancelacion sc
    JOIN historial_ocupacion ho ON sc.ocupacion_id = ho.id
    JOIN ambientes a ON ho.ambiente_id = a.id
    JOIN pisos p ON a.piso_id = p.id
    JOIN usuarios u ON sc.instructor_id = u.id
    LEFT JOIN usuarios ur ON sc.resuelto_por = ur.id
    WHERE p.sede_id = $sede_id
    ORDER BY (sc.estado = 'pendiente') DESC, sc.creado_en DESC");
while ($row = mysqli_fetch_assoc($q)) { $solicitudes[] = $row; }

// Solicitudes de CAMBIO DE JORNADA de la sede (pendientes primero)
$cambios = [];
$qc = mysqli_query($con, "
    SELECT scj.id, scj.jornada_actual, scj.jornada_nueva, scj.motivo, scj.estado, scj.respuesta, scj.creado_en, scj.resuelto_en,
           CONCAT_WS(' ', u.nombre, u.apellido) AS instructor_nombre,
           a.nombre AS ambiente_nombre, p.nombre AS piso_nombre,
           ho.fecha_inicio, f.codigo_ficha,
           CONCAT_WS(' ', ur.nombre, ur.apellido) AS resuelto_nombre
    FROM solicitudes_cambio_jornada scj
    JOIN historial_ocupacion ho ON scj.ocupacion_id = ho.id
    JOIN ambientes a ON ho.ambiente_id = a.id
    JOIN pisos p ON a.piso_id = p.id
    JOIN usuarios u ON scj.instructor_id = u.id
    LEFT JOIN fichas f ON ho.ficha_id = f.id
    LEFT JOIN usuarios ur ON scj.resuelto_por = ur.id
    WHERE p.sede_id = $sede_id
    ORDER BY (scj.estado = 'pendiente') DESC, scj.creado_en DESC");
while ($row = mysqli_fetch_assoc($qc)) { $cambios[] = $row; }

$n_pendientes = 0;
foreach ($solicitudes as $s) { if ($s['estado'] === 'pendiente') $n_pendientes++; }
foreach ($cambios as $s) { if ($s['estado'] === 'pendiente') $n_pendientes++; }

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$msg = $_GET['msg'] ?? ''; $error = $_GET['error'] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Solicitudes de cancelación - <?php echo h($sede_nombre); ?></title>
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
            <h1><i class="bi bi-envelope-paper"></i> Solicitudes de instructores</h1>
            <p><?php echo h($sede_nombre); ?> — revisa las cancelaciones y cambios de jornada pedidos por los instructores</p>
        </div>
        <div class="resumen-pendientes <?php echo $n_pendientes > 0 ? 'alerta' : ''; ?>">
            <span class="np"><?php echo $n_pendientes; ?></span> pendiente<?php echo $n_pendientes == 1 ? '' : 's'; ?>
        </div>
    </div>

    <?php if ($msg === 'solicitud_aprobada'): ?>
        <div class="banner ok"><i class="bi bi-check-circle-fill"></i> Solicitud aprobada: la ocupación fue cancelada.</div>
    <?php elseif ($msg === 'solicitud_rechazada'): ?>
        <div class="banner info"><i class="bi bi-x-circle-fill"></i> Solicitud rechazada: la ocupación se mantiene.</div>
    <?php elseif ($msg === 'cambio_aprobada'): ?>
        <div class="banner ok"><i class="bi bi-check-circle-fill"></i> Cambio de jornada aprobado: la ocupación se actualizó.</div>
    <?php elseif ($msg === 'cambio_rechazada'): ?>
        <div class="banner info"><i class="bi bi-x-circle-fill"></i> Cambio de jornada rechazado: se mantiene la jornada actual.</div>
    <?php elseif ($error): ?>
        <div class="banner err"><i class="bi bi-exclamation-triangle-fill"></i>
            <?php echo match($error) {
                'ya_resuelta'      => 'Esa solicitud ya había sido resuelta.',
                'ocupacion_no_cancelable' => 'No se aprobó: la ocupación ya inició, terminó, se canceló o la solicitud fue resuelta.',
                'no_encontrada'    => 'No se encontró la solicitud.',
                'ambiente_ocupado' => 'No se pudo aprobar: el ambiente ya está ocupado en esa jornada.',
                'jornada_invalida' => 'La jornada solicitada no es válida.',
                default            => 'Ocurrió un error. Inténtalo de nuevo.',
            }; ?>
        </div>
    <?php endif; ?>

    <div class="lista-solicitudes">
        <?php if (!$solicitudes): ?>
            <div class="estado-vacio">
                <div class="vacio-icono"><i class="bi bi-inbox"></i></div>
                <h3>No hay solicitudes</h3>
                <p>Cuando un instructor pida cancelar una ocupación, aparecerá aquí.</p>
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
                    <h3><i class="bi bi-person-workspace"></i> <?php echo h($s['instructor_nombre']); ?></h3>
                    <p class="solicitud-ocup">
                        <i class="bi bi-door-open"></i> <?php echo h($s['piso_nombre'].' - '.$s['ambiente_nombre']); ?>
                        &nbsp;·&nbsp; <i class="bi bi-calendar-event"></i> <?php echo date('d/m/Y', $fi); ?>
                        &nbsp;·&nbsp; <i class="bi bi-clock"></i> <?php echo date('H:i', $fi).' - '.date('H:i', $ff); ?>
                        (<?php echo h(ucfirst($s['jornada'])); ?>)
                    </p>
                    <p class="solicitud-motivo"><i class="bi bi-chat-left-quote"></i> <strong>Motivo:</strong> <?php echo h($s['motivo']); ?></p>
                    <?php if (!empty($s['adjunto'])): ?>
                        <p class="solicitud-adjunto">
                            <i class="bi bi-paperclip"></i>
                            <a href="../../uploads/solicitudes/<?php echo h($s['adjunto']); ?>" target="_blank" rel="noopener">Ver soporte adjunto</a>
                        </p>
                    <?php endif; ?>
                    <?php if ($s['estado'] !== 'pendiente'): ?>
                        <p class="solicitud-resuelto">
                            Resuelta por <strong><?php echo h($s['resuelto_nombre'] ?: 'Administrador'); ?></strong>
                            el <?php echo $s['resuelto_en'] ? date('d/m/Y H:i', strtotime($s['resuelto_en'])) : '—'; ?>
                            <?php if (!empty($s['respuesta'])): ?> — “<?php echo h($s['respuesta']); ?>”<?php endif; ?>
                        </p>
                    <?php endif; ?>
                </div>

                <?php if ($s['estado'] === 'pendiente'): ?>
                <div class="solicitud-acciones">
                    <form method="POST" action="../../controllers/ResolverSolicitudCancelacion.php"
                          onsubmit="return confirm('¿Aprobar la cancelación? La ocupación quedará cancelada.')">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="sede_id" value="<?php echo $sede_id; ?>">
                        <input type="hidden" name="solicitud_id" value="<?php echo (int)$s['id']; ?>">
                        <input type="hidden" name="accion" value="aprobar">
                        <button type="submit" class="btn btn-aprobar"><i class="bi bi-check-lg"></i> Aprobar</button>
                    </form>
                    <form method="POST" action="../../controllers/ResolverSolicitudCancelacion.php"
                          onsubmit="return confirm('¿Rechazar la solicitud? La ocupación se mantiene.')">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="sede_id" value="<?php echo $sede_id; ?>">
                        <input type="hidden" name="solicitud_id" value="<?php echo (int)$s['id']; ?>">
                        <input type="hidden" name="accion" value="rechazar">
                        <button type="submit" class="btn btn-rechazar"><i class="bi bi-x-lg"></i> Rechazar</button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        <?php endforeach; endif; ?>
    </div>

    <!-- ── Cambios de jornada ── -->
    <?php if ($cambios): ?>
    <h2 style="max-width:1080px;margin:34px auto 0;padding:0 34px;font-size:19px;font-weight:800;color:var(--carbon);display:flex;align-items:center;gap:9px;">
        <i class="bi bi-arrow-left-right" style="color:var(--azul);"></i> Cambios de jornada
    </h2>
    <div class="lista-solicitudes">
        <?php foreach ($cambios as $s):
            $fi = strtotime($s['fecha_inicio']);
        ?>
            <div class="solicitud-card <?php echo h($s['estado']); ?>">
                <div class="solicitud-main">
                    <div class="solicitud-cab">
                        <span class="badge-estado <?php echo h($s['estado']); ?>">
                            <?php echo ['pendiente'=>'Pendiente','aprobada'=>'Aprobada','rechazada'=>'Rechazada'][$s['estado']] ?? h($s['estado']); ?>
                        </span>
                        <span class="solicitud-fecha"><i class="bi bi-clock"></i> <?php echo date('d/m/Y H:i', strtotime($s['creado_en'])); ?></span>
                    </div>
                    <h3><i class="bi bi-person-workspace"></i> <?php echo h($s['instructor_nombre']); ?></h3>
                    <p class="solicitud-ocup">
                        <i class="bi bi-door-open"></i> <?php echo h($s['piso_nombre'].' - '.$s['ambiente_nombre']); ?>
                        &nbsp;·&nbsp; <i class="bi bi-calendar-event"></i> <?php echo date('d/m/Y', $fi); ?>
                        &nbsp;·&nbsp; <i class="bi bi-arrow-left-right"></i> <strong><?php echo h(ucfirst($s['jornada_actual'])); ?></strong> &rarr; <strong><?php echo h(ucfirst($s['jornada_nueva'])); ?></strong>
                        <?php echo $s['codigo_ficha'] ? '&nbsp;·&nbsp; Ficha '.h($s['codigo_ficha']) : ''; ?>
                    </p>
                    <p class="solicitud-motivo"><i class="bi bi-chat-left-quote"></i> <strong>Motivo:</strong> <?php echo h($s['motivo']); ?></p>
                    <?php if ($s['estado'] !== 'pendiente'): ?>
                        <p class="solicitud-resuelto">
                            Resuelta por <strong><?php echo h($s['resuelto_nombre'] ?: 'Administrador'); ?></strong>
                            el <?php echo $s['resuelto_en'] ? date('d/m/Y H:i', strtotime($s['resuelto_en'])) : '—'; ?>
                            <?php if (!empty($s['respuesta'])): ?> — &ldquo;<?php echo h($s['respuesta']); ?>&rdquo;<?php endif; ?>
                        </p>
                    <?php endif; ?>
                </div>

                <?php if ($s['estado'] === 'pendiente'): ?>
                <div class="solicitud-acciones">
                    <form method="POST" action="../../controllers/ResolverCambioJornada.php"
                          onsubmit="return confirm('¿Aprobar el cambio de jornada? Se actualizará la ocupación.')">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="sede_id" value="<?php echo $sede_id; ?>">
                        <input type="hidden" name="solicitud_id" value="<?php echo (int)$s['id']; ?>">
                        <input type="hidden" name="accion" value="aprobar">
                        <button type="submit" class="btn btn-aprobar"><i class="bi bi-check-lg"></i> Aprobar</button>
                    </form>
                    <form method="POST" action="../../controllers/ResolverCambioJornada.php"
                          onsubmit="return confirm('¿Rechazar la solicitud? La jornada se mantiene.')">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="sede_id" value="<?php echo $sede_id; ?>">
                        <input type="hidden" name="solicitud_id" value="<?php echo (int)$s['id']; ?>">
                        <input type="hidden" name="accion" value="rechazar">
                        <button type="submit" class="btn btn-rechazar"><i class="bi bi-x-lg"></i> Rechazar</button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

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
