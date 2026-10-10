<?php
session_start();

// Verificar sesión de instructor
if(!isset($_SESSION['id']) || $_SESSION['rol'] != 'instructor') {
    header("Location: ../../views/home.php");
    exit();
}

require_once "../../config/conexion.php";
require_once "../../config/csrf.php";
$con = conexion();

$instructor_id = $_SESSION['id'];
$instructor_nombre = $_SESSION['nombre'];

// Obtener filtro de estado
$filtro = isset($_GET['filtro']) ? $_GET['filtro'] : 'todos';

// Query base
$query_base = "SELECT 
    h.id,
    h.fecha_inicio,
    h.fecha_fin,
    h.jornada,
    h.observaciones,
    h.estado,
    a.nombre as ambiente_nombre,
    p.nombre as piso_nombre,
    s.nombre as sede_nombre
FROM historial_ocupacion h
INNER JOIN ambientes a ON h.ambiente_id = a.id
INNER JOIN pisos p ON a.piso_id = p.id
INNER JOIN sedes s ON p.sede_id = s.id
WHERE h.usuario_id = $instructor_id";

// Aplicar filtro
switch($filtro) {
    case 'activos':
        $query_base .= " AND h.estado = 'ocupado' AND h.fecha_inicio <= NOW() AND h.fecha_fin >= NOW()";
        break;
    case 'proximos':
        $query_base .= " AND h.estado = 'ocupado' AND h.fecha_inicio > NOW()";
        break;
    case 'finalizados':
        $query_base .= " AND h.estado = 'finalizado'";
        break;
    case 'cancelados':
        $query_base .= " AND h.estado = 'cancelado'";
        break;
}

$query_base .= " ORDER BY h.fecha_inicio DESC";

$ocupaciones = mysqli_query($con, $query_base);
$total_registros = mysqli_num_rows($ocupaciones);

// Estadísticas
$stats_total = mysqli_fetch_assoc(mysqli_query($con, 
    "SELECT COUNT(*) as total FROM historial_ocupacion WHERE usuario_id = $instructor_id"
))['total'];

$stats_activos = mysqli_fetch_assoc(mysqli_query($con, 
    "SELECT COUNT(*) as total FROM historial_ocupacion 
     WHERE usuario_id = $instructor_id 
     AND estado = 'ocupado' 
     AND fecha_inicio <= NOW() 
     AND fecha_fin >= NOW()"
))['total'];

$stats_proximos = mysqli_fetch_assoc(mysqli_query($con, 
    "SELECT COUNT(*) as total FROM historial_ocupacion 
     WHERE usuario_id = $instructor_id 
     AND estado = 'ocupado' 
     AND fecha_inicio > NOW()"
))['total'];

$stats_finalizados = mysqli_fetch_assoc(mysqli_query($con, 
    "SELECT COUNT(*) as total FROM historial_ocupacion 
     WHERE usuario_id = $instructor_id 
     AND estado = 'finalizado'"
))['total'];

$stats_cancelados = mysqli_fetch_assoc(mysqli_query($con, 
    "SELECT COUNT(*) as total FROM historial_ocupacion 
     WHERE usuario_id = $instructor_id 
     AND estado = 'cancelado'"
))['total'];

// Ocupaciones con solicitud de cancelación pendiente
$solicitudes_pendientes = [];
$spq = mysqli_query($con, "SELECT ocupacion_id FROM solicitudes_cancelacion
                           WHERE instructor_id = $instructor_id AND estado = 'pendiente'");
if($spq){ while($r = mysqli_fetch_assoc($spq)){ $solicitudes_pendientes[(int)$r['ocupacion_id']] = true; } }

// Ocupaciones con solicitud de cambio de jornada pendiente
$cambios_pendientes = [];
$cpq = mysqli_query($con, "SELECT ocupacion_id FROM solicitudes_cambio_jornada
                           WHERE instructor_id = $instructor_id AND estado = 'pendiente'");
if($cpq){ while($r = mysqli_fetch_assoc($cpq)){ $cambios_pendientes[(int)$r['ocupacion_id']] = true; } }

// Mensajes
$msg = $_GET['msg'] ?? '';
$error = $_GET['error'] ?? '';
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mis Ocupaciones - SAGA</title>
    <link rel="stylesheet" href="../../assets/css/mis_ocupaciones_instructor.css">
    <link rel="stylesheet" href="../../assets/css/notificaciones.css">
    <script src="../../assets/js/notificaciones.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body>
    <!-- Header -->
    <div class="header">
        <div class="logo">
            <button class="btn-menu" id="btnMenu">
                <i class="bi bi-list"></i>
            </button>
<h2>SAGA</h2>
        </div>

        <div class="header-info">
            <?php include __DIR__ . '/../_campana.php'; ?>
            <div class="usuario-info">
                <span class="usuario-nombre"><?php echo $instructor_nombre; ?></span>
                <span class="usuario-tipo">Instructor</span>
            </div>
            <a class="btn-logout" href="../../controllers/logout.php">
                <i class="bi bi-box-arrow-right"></i>
            </a>
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
            <h1><i class="bi bi-clipboard"></i> Mis Ocupaciones</h1>
            <p>Historial completo de todas tus reservas de ambientes</p>
        </div>
    </div>

    <!-- Notificaciones (toast) -->
    <?php
    $noti_texto = ''; $noti_tipo = $error ? 'error' : 'exito';
    if ($error) {
        $noti_texto = match($error){
            'motivo_requerido' => 'Debes escribir un motivo para solicitar la cancelación.',
            'no_cancelable'    => 'Solo puedes solicitar cancelación hasta el día anterior. La ocupación no debe estar finalizada ni cancelada.',
            'no_encontrada'    => 'No se encontró la ocupación.',
            'usar_solicitud'   => 'Para cancelar una ocupación debes enviar una solicitud al administrador.',
            'adjunto_tipo'     => 'El archivo adjunto debe ser una imagen (JPG, PNG, WebP) o un PDF.',
            'adjunto_grande'   => 'El archivo adjunto supera el máximo de 5 MB.',
            'adjunto_error'    => 'No se pudo subir el archivo adjunto. Inténtalo de nuevo.',
            'cambio_motivo'    => 'Debes escribir un motivo para solicitar el cambio de jornada.',
            'jornada_invalida' => 'Selecciona una jornada válida.',
            'no_cambiable'     => 'Esa ocupación no se puede cambiar (ya pasó, está finalizada o cancelada).',
            'misma_jornada'    => 'La nueva jornada debe ser diferente a la actual.',
            default            => 'Ocurrió un error. Inténtalo de nuevo.',
        };
    } elseif ($msg) {
        if (in_array($msg, ['solicitud_ya_existe'])) $noti_tipo = 'aviso';
        $noti_texto = match($msg){
            'solicitud_enviada'   => 'Solicitud de cancelación enviada. El administrador la revisará.',
            'cambio_enviado'      => 'Solicitud de cambio de jornada enviada. El administrador la revisará.',
            'solicitud_ya_existe' => 'Ya tienes una solicitud pendiente para esa ocupación.',
            'cancelado'           => 'Ocupación cancelada exitosamente.',
            default               => '',
        };
    }
    ?>
    <?php if ($noti_texto): ?>
    <script>document.addEventListener('DOMContentLoaded', function(){ notificar(<?php echo json_encode($noti_texto); ?>, <?php echo json_encode($noti_tipo); ?>); });</script>
    <?php endif; ?>

    <!-- Estadísticas -->
    <div class="estadisticas">
        <div class="stat-card">
            <div class="stat-icono"><i class="bi bi-bar-chart-line"></i></div>
            <div class="stat-info">
                <span class="stat-numero"><?php echo $stats_total; ?></span>
                <span class="stat-label">Total Registros</span>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icono"><i class="bi bi-circle-fill text-danger" style="font-size:.7rem"></i></div>
            <div class="stat-info">
                <span class="stat-numero"><?php echo $stats_activos; ?></span>
                <span class="stat-label">Activos Ahora</span>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icono"><i class="bi bi-circle-fill text-warning" style="font-size:.7rem"></i></div>
            <div class="stat-info">
                <span class="stat-numero"><?php echo $stats_proximos; ?></span>
                <span class="stat-label">Próximos</span>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icono"><i class="bi bi-circle-fill text-success" style="font-size:.7rem"></i></div>
            <div class="stat-info">
                <span class="stat-numero"><?php echo $stats_finalizados; ?></span>
                <span class="stat-label">Finalizados</span>
            </div>
        </div>
    </div>

    <!-- Filtros -->
    <div class="filtros-ocupaciones">
        <a href="mis_ocupaciones_instructor.php?filtro=todos" 
           class="filtro-btn <?php echo $filtro == 'todos' ? 'active' : ''; ?>">
            <i class="bi bi-bar-chart-line"></i> Todos (<?php echo $stats_total; ?>)
        </a>
        <a href="mis_ocupaciones_instructor.php?filtro=activos" 
           class="filtro-btn <?php echo $filtro == 'activos' ? 'active' : ''; ?>">
            <i class="bi bi-circle-fill text-danger" style="font-size:.7rem"></i> Activos (<?php echo $stats_activos; ?>)
        </a>
        <a href="mis_ocupaciones_instructor.php?filtro=proximos" 
           class="filtro-btn <?php echo $filtro == 'proximos' ? 'active' : ''; ?>">
            <i class="bi bi-circle-fill text-warning" style="font-size:.7rem"></i> Próximos (<?php echo $stats_proximos; ?>)
        </a>
        <a href="mis_ocupaciones_instructor.php?filtro=finalizados" 
           class="filtro-btn <?php echo $filtro == 'finalizados' ? 'active' : ''; ?>">
            <i class="bi bi-circle-fill text-success" style="font-size:.7rem"></i> Finalizados (<?php echo $stats_finalizados; ?>)
        </a>
        <a href="mis_ocupaciones_instructor.php?filtro=cancelados" 
           class="filtro-btn <?php echo $filtro == 'cancelados' ? 'active' : ''; ?>">
            <i class="bi bi-circle-fill"></i> Cancelados (<?php echo $stats_cancelados; ?>)
        </a>
    </div>

    <!-- Lista de Ocupaciones -->
    <div class="ocupaciones-lista-container">
        <div class="total-registros">
            Mostrando <?php echo $total_registros; ?> registro(s)
        </div>

        <?php if($total_registros > 0): ?>
            <div class="ocupaciones-lista">
                <?php 
                mysqli_data_seek($ocupaciones, 0);
                while($ocu = mysqli_fetch_assoc($ocupaciones)): 
                    // Determinar estado
                    $ahora = new DateTime();
                    $fecha_inicio = new DateTime($ocu['fecha_inicio']);
                    $fecha_fin = new DateTime($ocu['fecha_fin']);
                    
                    if($ocu['estado'] == 'cancelado') {
                        $estado_clase = 'cancelado';
                        $estado_texto = 'Cancelado';
                        $estado_badge = 'cancelado';
                    } elseif($ocu['estado'] == 'finalizado') {
                        $estado_clase = 'finalizado';
                        $estado_texto = 'Finalizado';
                        $estado_badge = 'finalizado';
                    } elseif($ahora >= $fecha_inicio && $ahora <= $fecha_fin) {
                        $estado_clase = 'activo';
                        $estado_texto = 'En Curso';
                        $estado_badge = 'activo';
                    } else {
                        $estado_clase = 'proximo';
                        $estado_texto = 'Próximo';
                        $estado_badge = 'proximo';
                    }

                    // Formatear fechas
                    $dia = $fecha_inicio->format('d');
                    $mes = ucfirst($fecha_inicio->format('M'));
                    $ano = $fecha_inicio->format('Y');
                    $hora_inicio = $fecha_inicio->format('H:i');
                    $hora_fin = $fecha_fin->format('H:i');
                ?>
                    <div class="ocupacion-item <?php echo $estado_clase; ?>">
                        <div class="ocupacion-fecha-box">
                            <div class="fecha-dia"><?php echo $dia; ?></div>
                            <div class="fecha-mes"><?php echo $mes; ?></div>
                            <div class="fecha-ano"><?php echo $ano; ?></div>
                        </div>

                        <div class="ocupacion-detalles">
                            <h3><?php echo $ocu['ambiente_nombre']; ?></h3>
                            <p class="ocupacion-ubicacion">
                                <i class="bi bi-geo-alt"></i> <?php echo $ocu['sede_nombre']; ?> - <?php echo $ocu['piso_nombre']; ?>
                            </p>
                            <p class="ocupacion-horario">
                                <i class="bi bi-clock"></i> <?php echo $hora_inicio; ?> - <?php echo $hora_fin; ?>
                                (<?php echo ucfirst($ocu['jornada']); ?>)
                            </p>
                            <?php if(!empty($ocu['observaciones'])): ?>
                                <p class="ocupacion-obs">
                                    <i class="bi bi-card-text"></i> <?php echo $ocu['observaciones']; ?>
                                </p>
                            <?php endif; ?>
                            <span class="ocupacion-estado-badge <?php echo $estado_badge; ?>">
                                <?php echo $estado_texto; ?>
                            </span>
                        </div>

                        <div class="ocupacion-acciones-lista">
                            <?php $es_futura = ($ocu['estado'] == 'ocupado' && $ahora < $fecha_inicio); ?>
                            <?php if(isset($solicitudes_pendientes[$ocu['id']])): ?>
                                <span class="badge-solicitud-pendiente"><i class="bi bi-hourglass-split"></i> Cancelación solicitada</span>
                            <?php elseif($es_futura): ?>
                                <button type="button" class="btn-cancelar-small" onclick="solicitarCancelacion(<?php echo $ocu['id']; ?>)">
                                    <i class="bi bi-x-circle"></i> Solicitar cancelación
                                </button>
                            <?php endif; ?>

                            <?php if(isset($cambios_pendientes[$ocu['id']])): ?>
                                <span class="badge-solicitud-pendiente"><i class="bi bi-hourglass-split"></i> Cambio de jornada solicitado</span>
                            <?php elseif($es_futura && !isset($solicitudes_pendientes[$ocu['id']])): ?>
                                <button type="button" class="btn-jornada-small" onclick="solicitarCambioJornada(<?php echo $ocu['id']; ?>, '<?php echo htmlspecialchars($ocu['jornada'], ENT_QUOTES); ?>')">
                                    <i class="bi bi-arrow-left-right"></i> Cambiar jornada
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <div class="estado-vacio">
                <div class="vacio-icono"><i class="bi bi-clipboard-data"></i></div>
                <h3>No hay ocupaciones registradas</h3>
                <p>
                    <?php 
                    if($filtro == 'activos') echo 'No tienes ocupaciones activas en este momento';
                    elseif($filtro == 'proximos') echo 'No tienes ocupaciones próximas programadas';
                    elseif($filtro == 'finalizados') echo 'Aún no tienes ocupaciones finalizadas';
                    elseif($filtro == 'cancelados') echo 'No tienes ocupaciones canceladas';
                    else echo 'Registra tu primera ocupación en el calendario';
                    ?>
                </p>
                <a href="calendario_instructor.php" class="btn-agendar">
                    <i class="bi bi-calendar-event"></i> Ir al Calendario
                </a>
            </div>
        <?php endif; ?>
    </div>

    <!-- MODAL SOLICITAR CANCELACIÓN -->
    <div class="modal" id="modalSolicitud">
        <div class="modal-contenido">
            <div class="modal-encabezado">
                <h2><i class="bi bi-x-circle"></i> Solicitar cancelación</h2>
                <p>Las solicitudes se reciben solo hasta el día anterior a la ocupación.</p>
                <button class="modal-cerrar" type="button" onclick="cerrarModalSolicitud()"><i class="bi bi-x-lg"></i></button>
            </div>
            <form action="../../controllers/SolicitarCancelacionOcupacion.php" method="POST" enctype="multipart/form-data">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="id" id="solicitudOcupacionId">
                <input type="hidden" name="origen" value="mis_ocupaciones">
                <p style="font-size:13.5px;color:var(--gris);margin:2px 0 4px;">Tu solicitud será revisada por el administrador. La ocupación se mantiene activa hasta que sea aprobada.</p>
                <div class="form-grupo">
                    <label>Motivo de la cancelación *</label>
                    <textarea name="motivo" rows="3" required placeholder="Explica por qué necesitas cancelar..."></textarea>
                </div>
                <div class="form-grupo">
                    <label>Soporte (opcional)</label>
                    <input type="file" name="adjunto" accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf">
                    <small style="color:var(--gris);">Imagen (JPG, PNG, WebP) o PDF. Máx. 5 MB.</small>
                </div>
                <div class="form-botones">
                    <button type="button" class="btn-cancelar" onclick="cerrarModalSolicitud()">Cerrar</button>
                    <button type="submit" class="btn-guardar"><i class="bi bi-send"></i> Enviar solicitud</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL SOLICITAR CAMBIO DE JORNADA -->
    <div class="modal" id="modalCambioJornada">
        <div class="modal-contenido">
            <div class="modal-encabezado">
                <h2><i class="bi bi-arrow-left-right"></i> Solicitar cambio de jornada</h2>
                <button class="modal-cerrar" type="button" onclick="cerrarModalCambio()"><i class="bi bi-x-lg"></i></button>
            </div>
            <form action="../../controllers/SolicitarCambioJornada.php" method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="id" id="cambioOcupacionId">
                <input type="hidden" name="origen" value="mis_ocupaciones">
                <p style="font-size:13.5px;color:var(--gris);margin:2px 0 4px;">Tu solicitud será revisada por el administrador. La jornada actual se mantiene hasta que sea aprobada.</p>
                <div class="form-grupo">
                    <label>Jornada actual</label>
                    <input type="text" id="cambioJornadaActual" readonly style="background:#f3f4f6;cursor:not-allowed;">
                </div>
                <div class="form-grupo">
                    <label>Nueva jornada *</label>
                    <select name="jornada_nueva" id="cambioJornadaNueva" required>
                        <option value="">Seleccionar...</option>
                        <option value="mañana">Mañana (06:00 - 12:00)</option>
                        <option value="tarde">Tarde (12:00 - 18:00)</option>
                        <option value="noche">Noche (18:00 - 22:00)</option>
                    </select>
                </div>
                <div class="form-grupo">
                    <label>Motivo *</label>
                    <textarea name="motivo" rows="3" required placeholder="Explica por qué necesitas cambiar la jornada..."></textarea>
                </div>
                <div class="form-botones">
                    <button type="button" class="btn-cancelar" onclick="cerrarModalCambio()">Cerrar</button>
                    <button type="submit" class="btn-guardar"><i class="bi bi-send"></i> Enviar solicitud</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const btnMenu = document.getElementById('btnMenu');
        const modalMenu = document.getElementById('modalMenu');

        btnMenu.addEventListener('click', () => modalMenu.classList.add('active'));
        modalMenu.addEventListener('click', (e) => {
            if(e.target === modalMenu) modalMenu.classList.remove('active');
        });

        function solicitarCancelacion(id) {
            document.getElementById('solicitudOcupacionId').value = id;
            document.getElementById('modalSolicitud').style.display = 'flex';
        }
        function cerrarModalSolicitud() {
            document.getElementById('modalSolicitud').style.display = 'none';
        }
        document.getElementById('modalSolicitud').addEventListener('click', (e) => {
            if(e.target.id === 'modalSolicitud') cerrarModalSolicitud();
        });

        function solicitarCambioJornada(id, jornadaActual) {
            document.getElementById('cambioOcupacionId').value = id;
            document.getElementById('cambioJornadaActual').value = jornadaActual ? jornadaActual.charAt(0).toUpperCase() + jornadaActual.slice(1) : '—';
            const sel = document.getElementById('cambioJornadaNueva');
            sel.value = '';
            Array.from(sel.options).forEach(op => {
                const esActual = (op.value !== '' && op.value === jornadaActual);
                op.hidden = esActual;
                op.disabled = esActual;
            });
            document.getElementById('modalCambioJornada').style.display = 'flex';
        }
        function cerrarModalCambio() {
            document.getElementById('modalCambioJornada').style.display = 'none';
        }
        document.getElementById('modalCambioJornada').addEventListener('click', (e) => {
            if(e.target.id === 'modalCambioJornada') cerrarModalCambio();
        });

        // Ocultar mensaje después de 5 segundos
        setTimeout(() => {
            const alert = document.getElementById('mensajeAlert');
            if(alert) {
                alert.style.opacity = '0';
                setTimeout(() => alert.style.display = 'none', 300);
            }
        }, 5000);
    </script>
</body>
</html>
