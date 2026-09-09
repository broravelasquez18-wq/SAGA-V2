<?php
session_start();

// Verificar sesión de celador
if(!isset($_SESSION['id']) || $_SESSION['rol'] != 'celador') {
    header("Location: ../../views/home.php");
    exit();
}

require_once "../../config/conexion.php";
require_once "../../config/csrf.php";
$con = conexion();

// ⭐ OBTENER SEDE DEL CELADOR
$celador_sede_id = $_SESSION['sede_id'];

// Obtener información de la sede
$sede_query = mysqli_query($con, "SELECT nombre, ciudad FROM sedes WHERE id = $celador_sede_id");

if(mysqli_num_rows($sede_query) > 0) {
    $sede_info = mysqli_fetch_assoc($sede_query);
    $sede_nombre = $sede_info['nombre'];
    $sede_ciudad = $sede_info['ciudad'];
} else {
    // Si no tiene sede asignada, cerrar sesión
    header("Location: ../../controllers/logout.php");
    exit();
}

$hoy = date('Y-m-d');

// ⭐ ESTADÍSTICAS FILTRADAS POR SEDE
$total_hoy = mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) total FROM historial_ocupacion ho LEFT JOIN ambientes a ON ho.ambiente_id = a.id LEFT JOIN pisos p ON a.piso_id = p.id WHERE DATE(ho.fecha_inicio) = '$hoy' AND p.sede_id = $celador_sede_id"))['total'];

$ocupados = mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) total FROM historial_ocupacion ho LEFT JOIN ambientes a ON ho.ambiente_id = a.id LEFT JOIN pisos p ON a.piso_id = p.id WHERE DATE(ho.fecha_inicio) = '$hoy' AND ho.estado = 'ocupado' AND p.sede_id = $celador_sede_id"))['total'];

$proximos = mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) total FROM historial_ocupacion ho LEFT JOIN ambientes a ON ho.ambiente_id = a.id LEFT JOIN pisos p ON a.piso_id = p.id WHERE DATE(ho.fecha_inicio) = '$hoy' AND ho.estado = 'proximo_a_desocupar' AND p.sede_id = $celador_sede_id"))['total'];

$finalizados = mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) total FROM historial_ocupacion ho LEFT JOIN ambientes a ON ho.ambiente_id = a.id LEFT JOIN pisos p ON a.piso_id = p.id WHERE DATE(ho.fecha_inicio) = '$hoy' AND ho.estado = 'finalizado' AND p.sede_id = $celador_sede_id"))['total'];

$ambientes_disponibles = mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) total FROM ambientes a LEFT JOIN pisos p ON a.piso_id = p.id WHERE a.estado = 'disponible' AND p.sede_id = $celador_sede_id"))['total'];

// ⭐ OCUPACIONES ACTIVAS DE LA SEDE
$query_activas = "SELECT ho.*, a.nombre AS ambiente_nombre, p.nombre AS piso_nombre, CONCAT_WS(' ', u.nombre, u.apellido) AS instructor_nombre,
                         f.id AS ficha_id, f.codigo_ficha, f.vocero_id
                  FROM historial_ocupacion ho
                  LEFT JOIN ambientes a ON ho.ambiente_id = a.id
                  LEFT JOIN pisos p ON a.piso_id = p.id
                  LEFT JOIN usuarios u ON ho.usuario_id = u.id
                  LEFT JOIN fichas f ON ho.ficha_id = f.id
                  WHERE p.sede_id = $celador_sede_id
                  AND DATE(ho.fecha_inicio) = '$hoy'
                  AND ho.estado IN ('ocupado', 'proximo_a_desocupar')
                  ORDER BY CASE ho.estado WHEN 'ocupado' THEN 1 WHEN 'proximo_a_desocupar' THEN 2 END, ho.fecha_inicio ASC
                  LIMIT 10";
$result_activas = mysqli_query($con, $query_activas);

// ⭐ SOLICITUDES DE OCUPACIÓN PENDIENTES (del vocero, en esta sede)
$solicitudes_ocup = mysqli_query($con, "
    SELECT so.id, so.creado_en, so.jornada, so.fecha_inicio, so.fecha_fin,
           a.nombre AS ambiente_nombre, p.nombre AS piso_nombre,
           f.codigo_ficha, CONCAT_WS(' ', v.nombre, v.apellido) AS vocero_nombre, so.motivo
    FROM solicitudes_ocupacion so
    JOIN ambientes a ON so.ambiente_id = a.id
    JOIN pisos p     ON a.piso_id = p.id
    LEFT JOIN fichas f ON so.ficha_id = f.id
    LEFT JOIN usuarios v ON so.vocero_id = v.id
    WHERE so.estado = 'pendiente' AND p.sede_id = $celador_sede_id
    ORDER BY so.creado_en ASC");

// ⭐ SOLICITUDES DE FINALIZACIÓN PENDIENTES (del vocero, en esta sede)
$solicitudes_fin = mysqli_query($con, "
    SELECT sf.id, sf.creado_en, a.nombre AS ambiente_nombre, p.nombre AS piso_nombre,
           f.codigo_ficha, CONCAT_WS(' ', v.nombre, v.apellido) AS vocero_nombre
    FROM solicitudes_finalizacion sf
    JOIN historial_ocupacion ho ON sf.ocupacion_id = ho.id
    JOIN ambientes a ON ho.ambiente_id = a.id
    JOIN pisos p     ON a.piso_id = p.id
    LEFT JOIN fichas f ON ho.ficha_id = f.id
    LEFT JOIN usuarios v ON sf.vocero_id = v.id
    WHERE sf.estado = 'pendiente' AND p.sede_id = $celador_sede_id
    ORDER BY sf.creado_en ASC");

// Datos para el modal de nueva ocupación
$ambientes_modal = mysqli_query($con, "SELECT a.id, a.nombre, p.nombre AS piso_nombre FROM ambientes a LEFT JOIN pisos p ON a.piso_id = p.id WHERE p.sede_id = $celador_sede_id ORDER BY p.nombre, a.nombre");
$instructores_modal = mysqli_query($con, "SELECT id, nombre, apellido, cedula FROM usuarios WHERE rol = 'instructor' AND estado = 'activo' ORDER BY nombre, apellido");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SAGA - Dashboard Celador</title>
    <link rel="stylesheet" href="../../assets/css/dashboard_celador.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body>
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
                <span class="usuario-nombre"><?php echo htmlspecialchars($_SESSION['nombre'] . ' ' . ($_SESSION['apellido'] ?? '')); ?></span>
                <span class="usuario-tipo">Celador</span>
            </div>
            <a class="btn-logout" href="../../controllers/logout.php">
                <i class="bi bi-box-arrow-right"></i>
            </a>
        </div>
    </div>

    <div class="modal-menu" id="modalMenu">
        <div class="sidebar">
            <h3 class="menu-titulo">PRINCIPAL</h3>
            <a href="dashboard_celador.php" class="active"><i class="bi bi-bar-chart-fill"></i>Dashboard</a>
            <a href="ocupaciones_celador.php"><i class="bi bi-calendar-check"></i>Ocupaciones</a>
            <a href="calendario_celador.php"><i class="bi bi-calendar3"></i>Calendario</a>
            <a href="historial_celador.php"><i class="bi bi-clock-history"></i>Historial</a>
        </div>
    </div>

    <div class="bienvenida">
        <div class="bienvenida-contenido">
            <h1>¡Hola, <?php echo htmlspecialchars($_SESSION['nombre']); ?>! <i class="bi bi-hand-index-thumb"></i></h1>
            <p>Gestiona las ocupaciones de <?php echo htmlspecialchars($sede_nombre); ?></p>
            <p class="fecha-actual"><i class="bi bi-calendar-event"></i> Hoy es <?php echo date('d/m/Y'); ?></p>
        </div>
        <div class="bienvenida-botones">
            <button class="btn-agendar-grande" onclick="abrirModal()"><i class="bi bi-plus-lg"></i> Nueva Ocupación</button>
        </div>
    </div>

    <div class="estadisticas">
        <div class="stat-card ocupado">
            <div class="stat-icono"><i class="bi bi-circle-fill text-danger" style="font-size:.7rem"></i></div>
            <div class="stat-info">
                <span class="stat-numero"><?php echo $ocupados; ?></span>
                <span class="stat-label">Ocupados Ahora</span>
            </div>
        </div>
        <div class="stat-card proximo">
            <div class="stat-icono"><i class="bi bi-circle-fill text-warning" style="font-size:.7rem"></i></div>
            <div class="stat-info">
                <span class="stat-numero"><?php echo $proximos; ?></span>
                <span class="stat-label">Por Desocupar</span>
            </div>
        </div>
        <div class="stat-card finalizado">
            <div class="stat-icono"><i class="bi bi-check-circle-fill"></i></div>
            <div class="stat-info">
                <span class="stat-numero"><?php echo $finalizados; ?></span>
                <span class="stat-label">Finalizados Hoy</span>
            </div>
        </div>
        <div class="stat-card disponible">
            <div class="stat-icono"><i class="bi bi-circle-fill text-success" style="font-size:.7rem"></i></div>
            <div class="stat-info">
                <span class="stat-numero"><?php echo $ambientes_disponibles; ?></span>
                <span class="stat-label">Ambientes Libres</span>
            </div>
        </div>
    </div>

    <?php
    $c_msg = $_GET['msg'] ?? ''; $c_err = $_GET['error'] ?? '';
    $c_banner = [
        'ocupacion_aprobada'    => ['ok',   'Ocupación aprobada y creada. Se notificó al vocero.'],
        'ocupacion_rechazada'   => ['info', 'Solicitud de ocupación rechazada. Se notificó al vocero.'],
        'finalizacion_aprobada' => ['ok',   'Ocupación finalizada. Se notificó al vocero.'],
        'finalizacion_rechazada'=> ['info', 'Solicitud de finalización rechazada. Se notificó al vocero.'],
    ];
    $c_banner_icono = ['ok' => 'bi-check-circle-fill', 'info' => 'bi-x-circle-fill', 'warn' => 'bi-exclamation-triangle-fill'];
    $c_errores = [
        'ambiente_ocupado'   => 'No se pudo aprobar: el ambiente ya está ocupado en ese horario.',
        'otra_sede'          => 'Esa solicitud es de otra sede.',
        'ya_resuelta'        => 'Esa solicitud ya fue resuelta.',
        'no_encontrada'      => 'No se encontró la solicitud.',
    ];
    ?>
    <?php if(isset($c_banner[$c_msg])): $b = $c_banner[$c_msg]; ?>
        <div class="saga-banner <?php echo $b[0]; ?>">
            <i class="bi <?php echo $c_banner_icono[$b[0]] ?? 'bi-info-circle-fill'; ?>"></i> <?php echo $b[1]; ?>
        </div>
    <?php elseif(isset($c_errores[$c_err])): ?>
        <div class="saga-banner err">
            <i class="bi bi-exclamation-circle-fill"></i> <?php echo $c_errores[$c_err]; ?>
        </div>
    <?php endif; ?>

    <?php if($solicitudes_ocup && mysqli_num_rows($solicitudes_ocup) > 0): ?>
    <div class="solic-panel ocupacion">
        <h2 class="solic-titulo"><i class="bi bi-box-arrow-in-down"></i> Solicitudes de ocupación del vocero</h2>
        <p class="solic-sub">Un vocero pide ocupar el ambiente de su ficha. Revisa el motivo y aprueba o rechaza.</p>
        <?php while($so = mysqli_fetch_assoc($solicitudes_ocup)): ?>
            <div class="solic-row">
                <div class="solic-info">
                    <span class="voc"><?php echo htmlspecialchars($so['vocero_nombre'] ?: 'Vocero'); ?></span> solicita ocupar
                    <span class="amb"><?php echo htmlspecialchars($so['piso_nombre'] . ' - ' . $so['ambiente_nombre']); ?></span>
                    <span class="meta">
                        <?php echo $so['codigo_ficha'] ? '· ficha ' . htmlspecialchars($so['codigo_ficha']) : ''; ?>
                        · <?php echo htmlspecialchars(ucfirst($so['jornada'])); ?>
                        <?php echo date('H:i', strtotime($so['fecha_inicio'])); ?>–<?php echo date('H:i', strtotime($so['fecha_fin'])); ?>
                    </span>
                    <?php if(!empty($so['motivo'])): ?><span class="solic-motivo"><i class="bi bi-chat-left-quote"></i> <strong>Motivo:</strong> <?php echo htmlspecialchars($so['motivo']); ?></span><?php endif; ?>
                </div>
                <div class="solic-acciones">
                    <form method="POST" action="../../controllers/ResolverOcupacionSolicitud.php" onsubmit="return confirm('¿Aprobar y crear la ocupación?')">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="solicitud_id" value="<?php echo intval($so['id']); ?>">
                        <input type="hidden" name="accion" value="aprobar">
                        <button type="submit" class="btn-solic aprobar"><i class="bi bi-check-lg"></i> Aprobar</button>
                    </form>
                    <form method="POST" action="../../controllers/ResolverOcupacionSolicitud.php" onsubmit="return confirm('¿Rechazar la solicitud?')">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="solicitud_id" value="<?php echo intval($so['id']); ?>">
                        <input type="hidden" name="accion" value="rechazar">
                        <button type="submit" class="btn-solic rechazar"><i class="bi bi-x-lg"></i> Rechazar</button>
                    </form>
                </div>
            </div>
        <?php endwhile; ?>
    </div>
    <?php endif; ?>

    <?php if($solicitudes_fin && mysqli_num_rows($solicitudes_fin) > 0): ?>
    <div class="solic-panel finalizacion">
        <h2 class="solic-titulo"><i class="bi bi-hourglass-split"></i> Solicitudes de finalización del vocero</h2>
        <p class="solic-sub">Un vocero pide finalizar su ocupación. Apruébala si estás de acuerdo y presente.</p>
        <?php while($sf = mysqli_fetch_assoc($solicitudes_fin)): ?>
            <div class="solic-row">
                <div class="solic-info">
                    <span class="voc"><?php echo htmlspecialchars($sf['vocero_nombre'] ?: 'Vocero'); ?></span> solicita finalizar
                    <span class="amb"><?php echo htmlspecialchars($sf['piso_nombre'] . ' - ' . $sf['ambiente_nombre']); ?></span>
                    <span class="meta">
                        <?php echo $sf['codigo_ficha'] ? '· ficha ' . htmlspecialchars($sf['codigo_ficha']) : ''; ?>
                        · <?php echo date('H:i', strtotime($sf['creado_en'])); ?>
                    </span>
                </div>
                <div class="solic-acciones">
                    <form method="POST" action="../../controllers/ResolverFinalizacionCelador.php" onsubmit="return confirm('¿Aprobar y finalizar la ocupación?')">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="solicitud_id" value="<?php echo intval($sf['id']); ?>">
                        <input type="hidden" name="accion" value="aprobar">
                        <button type="submit" class="btn-solic aprobar"><i class="bi bi-check-lg"></i> Aprobar</button>
                    </form>
                    <form method="POST" action="../../controllers/ResolverFinalizacionCelador.php" onsubmit="return confirm('¿Rechazar la solicitud?')">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="solicitud_id" value="<?php echo intval($sf['id']); ?>">
                        <input type="hidden" name="accion" value="rechazar">
                        <button type="submit" class="btn-solic rechazar"><i class="bi bi-x-lg"></i> Rechazar</button>
                    </form>
                </div>
            </div>
        <?php endwhile; ?>
    </div>
    <?php endif; ?>

    <div class="contenedor-principal">
        <div class="seccion-grande">
            <div class="seccion-header">
                <h2><i class="bi bi-circle-fill text-danger" style="font-size:.7rem"></i> Ocupaciones Activas de Hoy</h2>
                <a href="ocupaciones_celador.php" class="btn-ver-todas">Ver todas →</a>
            </div>
            <div class="ocupaciones-lista">
                <?php if($result_activas && mysqli_num_rows($result_activas) > 0):
                    while($ocup = mysqli_fetch_assoc($result_activas)):
                        $clase = ($ocup['estado'] == 'ocupado') ? 'ocupado' : 'proximo';
                        $texto = ($ocup['estado'] == 'ocupado') ? 'OCUPADO' : 'PRÓXIMO A DESOCUPAR';
                        $icono = ($ocup['estado'] == 'ocupado') ? '<i class="bi bi-circle-fill text-danger" style="font-size:.7rem"></i>' : '<i class="bi bi-circle-fill text-warning" style="font-size:.7rem"></i>';
                        $icono_jornada = ($ocup['jornada'] == 'mañana') ? '<i class="bi bi-sunrise"></i>' : (($ocup['jornada'] == 'tarde') ? '<i class="bi bi-sun"></i>' : '<i class="bi bi-moon-stars"></i>');
                ?>
                    <div class="ocupacion-item <?php echo $clase; ?>">
                        <div class="ocupacion-info">
                            <div class="ocupacion-badge <?php echo $clase; ?>"><?php echo $icono; ?> <?php echo $texto; ?></div>
                            <h3><?php echo $ocup['piso_nombre'] . ' - ' . $ocup['ambiente_nombre']; ?></h3>
                            <p class="ocupacion-instructor"><i class="bi bi-person"></i> <?php echo $ocup['instructor_nombre']; ?></p>
                            <p class="ocupacion-horario"><i class="bi bi-clock"></i> <?php echo date('H:i', strtotime($ocup['fecha_inicio'])); ?> - <?php echo date('H:i', strtotime($ocup['fecha_fin'])); ?> <span class="ocupacion-jornada"><?php echo $icono_jornada; ?> <?php echo ucfirst($ocup['jornada']); ?></span></p>
                            <?php if($ocup['observaciones']): ?>
                                <p class="ocupacion-obs"><i class="bi bi-card-text"></i> <?php echo htmlspecialchars($ocup['observaciones']); ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="ocupacion-acciones">
                            <a href="../../controllers/FinalizarOcupacion.php?id=<?php echo $ocup['id']; ?>&sede_id=<?php echo $celador_sede_id; ?>" class="btn-finalizar" onclick="return confirm('¿Finalizar esta ocupación?')"><i class="bi bi-check-circle-fill"></i> Finalizar</a>
                        </div>
                    </div>
                <?php endwhile; else: ?>
                    <div class="estado-vacio">
                        <div class="vacio-icono"><i class="bi bi-calendar-event"></i></div>
                        <h3>No hay ocupaciones activas</h3>
                        <p>Todas las ocupaciones de hoy han finalizado</p>
                        <button class="btn-registrar-vacio" onclick="abrirModal()"><i class="bi bi-plus-lg"></i> Registrar Primera Ocupación</button>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="seccion-lateral">
            <div class="seccion-header">
                <h2><i class="bi bi-bar-chart-line"></i> Resumen del Día</h2>
            </div>
            <div class="resumen-items">
                <div class="resumen-item">
                    <div class="resumen-icono"><i class="bi bi-clipboard-data"></i></div>
                    <div class="resumen-datos">
                        <span class="resumen-numero"><?php echo $total_hoy; ?></span>
                        <span class="resumen-texto">Total Registradas</span>
                    </div>
                </div>
                <div class="resumen-item">
                    <div class="resumen-icono"><i class="bi bi-arrow-repeat"></i></div>
                    <div class="resumen-datos">
                        <span class="resumen-numero"><?php echo $ocupados + $proximos; ?></span>
                        <span class="resumen-texto">En Proceso</span>
                    </div>
                </div>
                <div class="resumen-item">
                    <div class="resumen-icono"><i class="bi bi-check-circle-fill"></i></div>
                    <div class="resumen-datos">
                        <span class="resumen-numero"><?php echo $finalizados; ?></span>
                        <span class="resumen-texto">Completadas</span>
                    </div>
                </div>
                <div class="resumen-item">
                    <div class="resumen-icono"><i class="bi bi-circle-fill text-success" style="font-size:.7rem"></i></div>
                    <div class="resumen-datos">
                        <span class="resumen-numero"><?php echo $ambientes_disponibles; ?></span>
                        <span class="resumen-texto">Ambientes Libres</span>
                    </div>
                </div>
            </div>
            <div class="acciones-rapidas">
                <h3>Acciones Rápidas</h3>
                <button class="btn-accion" onclick="abrirModal()"><i class="bi bi-plus-lg"></i> Registrar Ocupación</button>
                <a href="historial_celador.php" class="btn-accion-secundario"><i class="bi bi-clock-history"></i> Ver Historial Completo</a>
            </div>
        </div>
    </div>

    <!-- MODAL NUEVA OCUPACIÓN -->
    <div class="modal" id="modalOcupacion">
        <div class="modal-contenido">
            <button class="modal-cerrar" type="button" onclick="cerrarModal()"><i class="bi bi-x-lg"></i></button>
            <h2><i class="bi bi-plus-lg"></i> Nueva Ocupación</h2>
            <form action="../../controllers/RegistrarOcupacion.php" method="POST" id="formOcupacion">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="sede_id" value="<?php echo $celador_sede_id; ?>">
                <input type="hidden" name="return" value="calendario">

                <!-- Checkbox múltiples días -->
                <div class="form-grupo">
                    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
                        <input type="checkbox" id="checkMultiple" onchange="toggleModoMultiple()" style="width:auto;cursor:pointer;">
                        <span><i class="bi bi-calendar-event"></i> Registrar en múltiples días</span>
                    </label>
                </div>

                <!-- Modo simple: una fecha -->
                <div id="modoSimple">
                    <div class="form-grupo">
                        <label>Fecha *</label>
                        <input type="date" name="fecha" id="fechaSimple"
                               value="<?php echo date('Y-m-d'); ?>"
                               min="<?php echo date('Y-m-d'); ?>"
                               required onchange="validarJornada()">
                    </div>
                </div>

                <!-- Modo múltiple: varias fechas -->
                <div id="modoMultiple" style="display:none;">
                    <div class="form-grupo">
                        <label>Selecciona los días *</label>
                        <div class="info-multiple"><i class="bi bi-lightbulb"></i> Selecciona múltiples días para registrar la misma ocupación</div>
                        <div id="selectorFechas" class="selector-fechas"></div>
                        <input type="hidden" name="fechas_multiples" id="fechasMultiples">
                    </div>
                </div>

                <div class="form-grupo">
                    <label>Ambiente *</label>
                    <select name="ambiente_id" required>
                        <option value="">Seleccionar ambiente...</option>
                        <?php while($amb = mysqli_fetch_assoc($ambientes_modal)): ?>
                            <option value="<?php echo $amb['id']; ?>">
                                <?php echo htmlspecialchars($amb['piso_nombre'] . ' - ' . $amb['nombre']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="form-grupo">
                    <label>Instructor *</label>
                    <select name="usuario_id" required>
                        <option value="">Seleccionar instructor...</option>
                        <?php while($inst = mysqli_fetch_assoc($instructores_modal)): ?>
                            <option value="<?php echo $inst['id']; ?>">
                                <?php echo htmlspecialchars($inst['nombre'] . ' ' . $inst['apellido'] . ' - ' . $inst['cedula']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="form-grupo">
                    <label>Jornada *</label>
                    <select name="jornada" id="jornadaSelect" required
                            onchange="toggleHorarioPersonalizado(); validarJornada();">
                        <option value="">Seleccionar jornada...</option>
                        <option value="mañana"> Mañana (06:00 - 12:00)</option>
                        <option value="tarde"> Tarde (12:00 - 18:00)</option>
                        <option value="noche"> Noche (18:00 - 22:00)</option>
                        <option value="otro">Otro horario (personalizado)</option>
                    </select>
                    <div id="avisoJornada" style="display:none;margin-top:8px;padding:10px 14px;background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;border-radius:8px;font-size:13px;font-weight:600;">
                        <i class="bi bi-slash-circle"></i> No se puede registrar esta ocupación porque la jornada ya pasó.
                    </div>
                </div>

                <!-- Horario personalizado -->
                <div id="horarioPersonalizado" style="display:none;">
                    <div class="form-row" style="display:flex;gap:15px;">
                        <div class="form-grupo" style="flex:1;">
                            <label>Hora Inicio *</label>
                            <input type="time" name="hora_inicio" id="horaInicio" min="06:00" max="22:00">
                            <small>Rango: 06:00 - 22:00</small>
                        </div>
                        <div class="form-grupo" style="flex:1;">
                            <label>Hora Fin *</label>
                            <input type="time" name="hora_fin" id="horaFin" min="06:00" max="22:00">
                            <small>Rango: 06:00 - 22:00</small>
                        </div>
                    </div>
                </div>

                <div class="form-grupo">
                    <label>Observaciones</label>
                    <textarea name="observaciones" rows="3" placeholder="Observaciones opcionales..."></textarea>
                </div>

                <div class="form-botones">
                    <button type="button" class="btn-cancelar" onclick="cerrarModal()">Cancelar</button>
                    <button type="submit" class="btn-guardar" id="btnGuardar">Registrar Ocupación</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const btnMenu = document.getElementById("btnMenu");
        const modalMenu = document.getElementById("modalMenu");

        if(btnMenu && modalMenu) {
            btnMenu.addEventListener("click", () => modalMenu.classList.add("active"));
            modalMenu.addEventListener("click", (e) => {
                if(e.target === modalMenu) modalMenu.classList.remove("active");
            });
        }

        function abrirModal() {
            document.getElementById('modalOcupacion').style.display = 'flex';
        }

        function cerrarModal() {
            document.getElementById('modalOcupacion').style.display = 'none';
            document.getElementById('avisoJornada').style.display = 'none';
            document.getElementById('btnGuardar').disabled = false;
            document.getElementById('btnGuardar').style.opacity = '1';
            document.getElementById('formOcupacion').reset();
            document.getElementById('checkMultiple').checked = false;
            document.getElementById('modoSimple').style.display = 'block';
            document.getElementById('modoMultiple').style.display = 'none';
            document.getElementById('fechaSimple').setAttribute('required', 'required');
            document.getElementById('horarioPersonalizado').style.display = 'none';
            document.getElementById('horaInicio').removeAttribute('required');
            document.getElementById('horaFin').removeAttribute('required');
            fechasSeleccionadas = [];
        }

        function validarJornada() {
            const fecha    = document.getElementById('fechaSimple').value;
            const jornada  = document.getElementById('jornadaSelect').value;
            const aviso    = document.getElementById('avisoJornada');
            const btn      = document.getElementById('btnGuardar');
            const hoy      = new Date();
            const horaActual = hoy.getHours() * 60 + hoy.getMinutes();
            const fechaHoy = hoy.toISOString().split('T')[0];
            const fines    = { 'mañana': 12*60, 'tarde': 18*60, 'noche': 22*60 };
            const pasada   = fecha === fechaHoy && jornada && fines[jornada] !== undefined && horaActual >= fines[jornada];
            aviso.style.display    = pasada ? 'block' : 'none';
            btn.disabled           = pasada;
            btn.style.opacity      = pasada ? '0.5' : '1';
        }

        function toggleHorarioPersonalizado() {
            const jornada    = document.getElementById('jornadaSelect').value;
            const horarioDiv = document.getElementById('horarioPersonalizado');
            const horaInicio = document.getElementById('horaInicio');
            const horaFin    = document.getElementById('horaFin');
            if(jornada === 'otro') {
                horarioDiv.style.display = 'block';
                horaInicio.setAttribute('required', 'required');
                horaFin.setAttribute('required', 'required');
            } else {
                horarioDiv.style.display = 'none';
                horaInicio.removeAttribute('required');
                horaFin.removeAttribute('required');
            }
        }

        let fechasSeleccionadas = [];
        const mesActual  = new Date().getMonth() + 1;
        const anioActual = new Date().getFullYear();

        function toggleModoMultiple() {
            const check      = document.getElementById('checkMultiple');
            const modoSimple = document.getElementById('modoSimple');
            const modoMult   = document.getElementById('modoMultiple');
            const fechaInput = document.getElementById('fechaSimple');
            const btn        = document.getElementById('btnGuardar');
            if(check.checked) {
                modoSimple.style.display = 'none';
                modoMult.style.display   = 'block';
                fechaInput.removeAttribute('required');
                btn.innerHTML = '<i class="bi bi-check-circle-fill"></i> Registrar en Días Seleccionados';
                generarSelectorFechas();
            } else {
                modoSimple.style.display = 'block';
                modoMult.style.display   = 'none';
                fechaInput.setAttribute('required', 'required');
                btn.textContent = 'Registrar Ocupación';
                fechasSeleccionadas = [];
            }
        }

        const FESTIVOS_CO = [
            '2025-01-01','2025-01-06','2025-03-24','2025-04-17','2025-04-18','2025-05-01',
            '2025-06-02','2025-06-23','2025-06-30','2025-07-20','2025-08-07','2025-08-18',
            '2025-10-13','2025-11-03','2025-11-17','2025-12-08','2025-12-25',
            '2026-01-01','2026-01-12','2026-03-23','2026-04-02','2026-04-03','2026-05-01',
            '2026-05-18','2026-06-08','2026-06-15','2026-06-29','2026-07-20','2026-08-07',
            '2026-08-17','2026-10-12','2026-11-02','2026-11-16','2026-12-08','2026-12-25',
            '2027-01-01','2027-01-11','2027-03-22','2027-03-25','2027-03-26','2027-05-10',
            '2027-05-31','2027-06-07','2027-07-05','2027-07-20','2027-08-07','2027-08-16',
            '2027-10-18','2027-11-01','2027-11-15','2027-12-08','2027-12-25'
        ];
        const DIAS_SEMANA = ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'];

        function generarSelectorFechas() {
            const container = document.getElementById('selectorFechas');
            const diasMes   = new Date(anioActual, mesActual, 0).getDate();
            const hoy       = new Date(); hoy.setHours(0,0,0,0);
            let html = '<div class="dias-grid">';
            for(let dia = 1; dia <= diasMes; dia++) {
                const fecha    = new Date(anioActual, mesActual - 1, dia);
                const fechaStr = `${anioActual}-${String(mesActual).padStart(2,'0')}-${String(dia).padStart(2,'0')}`;
                const esDomingo = fecha.getDay() === 0;
                const esFestivo = FESTIVOS_CO.includes(fechaStr);
                if(fecha >= hoy && !esDomingo && !esFestivo) {
                    html += `<div class="dia-selector" data-fecha="${fechaStr}" onclick="toggleFecha('${fechaStr}')">
                        <div class="dia-num">${dia}</div>
                        <div class="dia-mes">${DIAS_SEMANA[fecha.getDay()]}</div>
                    </div>`;
                }
            }
            html += '</div><div class="contador-seleccion" id="contadorSeleccion">0 días seleccionados</div>';
            container.innerHTML = html;
        }

        function toggleFecha(fecha) {
            const idx = fechasSeleccionadas.indexOf(fecha);
            const el  = document.querySelector(`[data-fecha="${fecha}"]`);
            if(idx > -1) { fechasSeleccionadas.splice(idx, 1); el.classList.remove('seleccionado'); }
            else         { fechasSeleccionadas.push(fecha);    el.classList.add('seleccionado'); }
            document.getElementById('fechasMultiples').value = fechasSeleccionadas.join(',');
            const n = fechasSeleccionadas.length;
            document.getElementById('contadorSeleccion').textContent =
                `${n} día${n !== 1 ? 's' : ''} seleccionado${n !== 1 ? 's' : ''}`;
        }

        function obtenerNombreMes(mes) {
            return ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'][mes - 1];
        }

        const formOcupacion = document.getElementById('formOcupacion');
        if(formOcupacion) {
            formOcupacion.addEventListener('submit', function(e) {
                const checkMultiple = document.getElementById('checkMultiple');
                if(checkMultiple && checkMultiple.checked && fechasSeleccionadas.length === 0) {
                    e.preventDefault();
                    alert('Debes seleccionar al menos un día.');
                }
            });
        }

        document.addEventListener('keydown', (e) => {
            if(e.key === 'Escape') cerrarModal();
        });

        // Auto-refresh cada 2 minutos (solo si el modal está cerrado)
        setInterval(() => {
            if(document.getElementById('modalOcupacion').style.display !== 'flex') {
                location.reload();
            }
        }, 120000);
    </script>
</body>
</html>
