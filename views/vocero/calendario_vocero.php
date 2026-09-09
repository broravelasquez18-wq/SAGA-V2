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

// Auto-finalizar ocupaciones cuya fecha_fin ya pasó (usando hora PHP para respetar timezone Bogotá)
$ahora = date('Y-m-d H:i:s');
mysqli_query($con, "UPDATE historial_ocupacion SET estado = 'finalizado' WHERE fecha_fin <= '$ahora' AND estado NOT IN ('finalizado', 'cancelado')");

$vocero_id = $_SESSION['id'];
$vocero_nombre = $_SESSION['nombre'];

// Ficha del vocero (1 vocero = 1 ficha); el calendario muestra las clases de la ficha
$ficha_row = mysqli_fetch_assoc(mysqli_query($con,
    "SELECT f.id, f.codigo_ficha, f.ambiente_id,
            a.nombre AS ambiente_nombre, p.nombre AS piso_nombre, s.nombre AS sede_nombre
     FROM fichas f
     LEFT JOIN ambientes a ON f.ambiente_id = a.id
     LEFT JOIN pisos p ON a.piso_id = p.id
     LEFT JOIN sedes s ON p.sede_id = s.id
     WHERE f.vocero_id = " . intval($vocero_id) . " AND f.activa = 1 LIMIT 1"));
$ficha_id       = $ficha_row ? intval($ficha_row['id']) : 0;
$ficha_ambiente_id = $ficha_row ? intval($ficha_row['ambiente_id']) : 0;
$ficha_ambiente_txt = $ficha_row && $ficha_row['ambiente_nombre']
    ? $ficha_row['ambiente_nombre'] . ' · ' . ($ficha_row['sede_nombre'] ?? '') . ' - ' . ($ficha_row['piso_nombre'] ?? '')
    : '';

// OBTENER MES Y AÑO (por defecto mes actual)
$mes = isset($_GET['mes']) ? intval($_GET['mes']) : date('n');
$anio = isset($_GET['anio']) ? intval($_GET['anio']) : date('Y');

// Validar mes
if($mes < 1) { $mes = 12; $anio--; }
if($mes > 12) { $mes = 1; $anio++; }

// CALCULAR DÍAS DEL MES
$primer_dia = mktime(0, 0, 0, $mes, 1, $anio);
$dias_mes = date('t', $primer_dia);
$dia_semana_inicio = date('N', $primer_dia);

// ── SEMANA PARA MÓVIL ──
$offset_cal = ($dia_semana_inicio == 7) ? 0 : ($dia_semana_inicio - 1);
$col_cnt = $offset_cal;
$semana_hoy_cal = 0;
$hoy_dia = (date('Y') == $anio && date('n') == $mes) ? (int)date('j') : -1;
for($d = 1; $d <= $dias_mes; $d++) {
    if(date('N', mktime(0,0,0,$mes,$d,$anio)) == 7) continue;
    if($d == $hoy_dia) $semana_hoy_cal = (int)floor($col_cnt / 6);
    $col_cnt++;
}
$total_semanas_cal = max(1, (int)ceil($col_cnt / 6));
if(isset($_GET['semana'])) {
    if($_GET['semana'] === 'last') $semana_inicial_cal = $total_semanas_cal - 1;
    else $semana_inicial_cal = max(0, min((int)$_GET['semana'], $total_semanas_cal - 1));
} else {
    $semana_inicial_cal = $semana_hoy_cal;
}
$mes_ant = $mes - 1 < 1  ? 12 : $mes - 1;
$anio_ant = $mes - 1 < 1 ? $anio - 1 : $anio;
$mes_sig  = $mes + 1 > 12 ? 1  : $mes + 1;
$anio_sig = $mes + 1 > 12 ? $anio + 1 : $anio;
$dia_seleccionado = isset($_GET['dia']) ? intval($_GET['dia']) : 0;
if($dia_seleccionado < 1 || $dia_seleccionado > $dias_mes) $dia_seleccionado = 0;
if($dia_seleccionado > 0 && !isset($_GET['semana'])) {
    $col_tmp = $offset_cal;
    for($d = 1; $d <= $dias_mes; $d++) {
        if(date('N', mktime(0,0,0,$mes,$d,$anio)) == 7) continue;
        if($d == $dia_seleccionado) { $semana_inicial_cal = (int)floor($col_tmp / 6); break; }
        $col_tmp++;
    }
}

// Nombres de meses
$meses_nombre = [
    1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
    5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
    9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
];

// OBTENER OCUPACIONES DEL MES DEL VOCERO
$fecha_inicio_mes = "$anio-$mes-01";
$fecha_fin_mes = "$anio-$mes-$dias_mes";

$query_ocupaciones = "SELECT h.*,
                      a.nombre AS ambiente_nombre,
                      p.nombre AS piso_nombre,
                      s.nombre AS sede_nombre,
                      CONCAT_WS(' ', ui.nombre, ui.apellido) AS instructor_nombre,
                      DATE(h.fecha_inicio) as fecha_ocupacion
                      FROM historial_ocupacion h
                      INNER JOIN ambientes a ON h.ambiente_id = a.id
                      INNER JOIN pisos p ON a.piso_id = p.id
                      INNER JOIN sedes s ON p.sede_id = s.id
                      LEFT JOIN usuarios ui ON h.usuario_id = ui.id
                      WHERE DATE(h.fecha_inicio) BETWEEN '$fecha_inicio_mes' AND '$fecha_fin_mes'
                      AND h.ficha_id = $ficha_id
                      AND h.estado != 'cancelado'
                      ORDER BY h.fecha_inicio ASC";

$result_ocupaciones = mysqli_query($con, $query_ocupaciones);

// Organizar ocupaciones por día
$ocupaciones_por_dia = [];
while($ocup = mysqli_fetch_assoc($result_ocupaciones)) {
    $dia_num = intval(date('j', strtotime($ocup['fecha_ocupacion'])));
    if(!isset($ocupaciones_por_dia[$dia_num])) {
        $ocupaciones_por_dia[$dia_num] = [];
    }
    $ocupaciones_por_dia[$dia_num][] = $ocup;
}

// ESTADÍSTICAS DEL MES
$total_mes = mysqli_num_rows($result_ocupaciones);

// DÍAS SIN FORMACIÓN: clases de la(s) ficha(s) del vocero canceladas ese día,
// sin ninguna clase activa que la reemplace el mismo día.
$sf_cancel = []; $sf_activo = [];
$q_sf = "SELECT DATE(h.fecha_inicio) f, h.estado, h.jornada, a.nombre amb,
                fi.codigo_ficha, CONCAT_WS(' ', u.nombre, u.apellido) instructor
         FROM historial_ocupacion h
         JOIN fichas fi ON h.ficha_id = fi.id AND fi.vocero_id = $vocero_id
         LEFT JOIN ambientes a ON h.ambiente_id = a.id
         LEFT JOIN usuarios u ON h.usuario_id = u.id
         WHERE DATE(h.fecha_inicio) BETWEEN '$fecha_inicio_mes' AND '$fecha_fin_mes'";
$rsf = mysqli_query($con, $q_sf);
if($rsf){
    while($r = mysqli_fetch_assoc($rsf)){
        $d = (int)date('j', strtotime($r['f']));
        if($r['estado'] == 'cancelado') $sf_cancel[$d][] = $r;
        else $sf_activo[$d] = true;
    }
}
$dias_sin_formacion = [];
foreach($sf_cancel as $d => $infos){
    if(empty($sf_activo[$d])) $dias_sin_formacion[$d] = $infos;
}

$vocero_tipo_contrato = $_SESSION['tipo_contrato'] ?? 'planta';
$vocero_sede_id       = $_SESSION['sede_id'] ?? 0;

if($vocero_tipo_contrato == 'contratista') {
    $sedes_result     = mysqli_query($con, "SELECT id, nombre, ciudad FROM sedes ORDER BY nombre ASC");
    $ambientes_result = null;
} else {
    $sedes_result     = null;
    $ambientes_result = mysqli_query($con, "SELECT a.id, a.nombre, p.nombre AS piso_nombre
                                            FROM ambientes a
                                            LEFT JOIN pisos p ON a.piso_id = p.id
                                            WHERE p.sede_id = $vocero_sede_id
                                            ORDER BY p.nombre, a.nombre");
}

// Mensajes
$msg = $_GET['msg'] ?? '';
$error = $_GET['error'] ?? '';
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Calendario - Vocero - SAGA</title>
    <link rel="stylesheet" href="../../assets/css/calendario_vocero.css">
    <link rel="stylesheet" href="../../assets/css/notificaciones.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="../../assets/js/notificaciones.js"></script>
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
        <div class="header-info"><?php include __DIR__ . '/../_campana.php'; ?>
            <div class="usuario-info">
                <span class="usuario-nombre"><?php echo $vocero_nombre; ?></span>
                <span class="usuario-tipo">Vocero</span>
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
            <a href="calendario_vocero.php" <?php echo $pagina_actual == 'calendario_vocero.php' ? 'class="active"' : ''; ?>>
                <i class="bi bi-calendar3"></i>Calendario
            </a>
            <a href="mis_ocupaciones_vocero.php" <?php echo $pagina_actual == 'mis_ocupaciones_vocero.php' ? 'class="active"' : ''; ?>>
                <i class="bi bi-clock-history"></i>Mis Ocupaciones
            </a>
        </div>
    </div>

    <!-- Notificaciones (toast) -->
    <?php
    $noti_texto = ''; $noti_tipo = 'info';
    if ($msg == 'exito') {
        $registrados = isset($_GET['registrados']) ? intval($_GET['registrados']) : 0;
        $noti_texto = $registrados . ' ocupación(es) registrada(s) correctamente';
        $noti_tipo  = 'exito';
    } elseif ($msg == 'finalizado') {
        $noti_texto = 'Ocupación finalizada correctamente';
        $noti_tipo  = 'exito';
    } elseif ($msg == 'solicitud_ocupacion_enviada') {
        $noti_texto = 'Solicitud enviada. El celador o el admin debe aprobarla.';
        $noti_tipo  = 'exito';
    } elseif ($msg == 'solicitud_finalizacion_enviada') {
        $noti_texto = 'Solicitud de finalización enviada. El celador debe aprobarla.';
        $noti_tipo  = 'exito';
    } elseif ($msg == 'solicitud_ya_existe') {
        $noti_texto = 'Ya tienes una solicitud pendiente para ese día.';
        $noti_tipo  = 'aviso';
    } elseif ($error) {
        $noti_tipo  = in_array($error, ['jornada_pasada','fuera_horario']) ? 'aviso' : 'error';
        $noti_texto = match($error) {
            'sin_fechas'         => 'Debes seleccionar al menos una fecha',
            'conflicto'          => 'Algunas fechas tienen conflictos de horario',
            'jornada_pasada'     => 'La jornada seleccionada ya pasó',
            'jornada_invalida'   => 'Selecciona una jornada válida',
            'fecha_pasada'       => 'No se pueden registrar ocupaciones en fechas pasadas',
            'fecha_invalida'     => 'La fecha no es válida o ya pasó',
            'dia_no_habil'       => 'No se pueden registrar ocupaciones en domingos ni días festivos',
            'sin_ficha'          => 'No tienes una ficha asignada',
            'ficha_sin_ambiente' => 'Tu ficha no tiene un ambiente asignado. Avisa al administrador.',
            'fuera_horario'      => 'Fuera del horario de ocupación (06:00 - 22:00)',
            'motivo_requerido'   => 'Debes escribir un motivo para solicitar la ocupación',
            'no_finalizable'     => 'No se pudo procesar la solicitud de finalización',
            default              => 'No se pudo procesar la solicitud',
        };
    }
    ?>
    <?php if ($noti_texto): ?>
    <script>document.addEventListener('DOMContentLoaded', function(){ notificar(<?php echo json_encode($noti_texto); ?>, <?php echo json_encode($noti_tipo); ?>); });</script>
    <?php endif; ?>

    <!-- Encabezado -->
    <div class="encabezado">
        <div class="encabezado-contenido">
            <h1><i class="bi bi-calendar-event"></i> Calendario - Vocero</h1>
            <p>Consulta las clases de tu ficha. Haz clic en un día para ver el detalle.</p>
        </div>

        <div class="navegacion-mes">
            <a href="?mes=<?php echo $mes-1; ?>&anio=<?php echo $anio; ?>" class="btn-mes">
                ◀ Anterior
            </a>
            <h2 class="mes-actual"><?php echo $meses_nombre[$mes] . ' ' . $anio; ?></h2>
            <a href="?mes=<?php echo $mes+1; ?>&anio=<?php echo $anio; ?>" class="btn-mes">
                Siguiente ▶
            </a>
        </div>
        <div class="buscar-fecha">
            <label><i class="bi bi-search"></i> Ir a fecha:</label>
            <input type="date" id="inputBuscarFecha" onchange="irAFecha(this.value)">
        </div>
    </div>

    <!-- Estadísticas -->
    <div class="estadisticas">
        <div class="stat-box">
            <div class="stat-icono"><i class="bi bi-clipboard-data"></i></div>
            <div class="stat-info">
                <span class="stat-numero"><?php echo $total_mes; ?></span>
                <span class="stat-label">Total del Mes</span>
            </div>
        </div>

        <div class="stat-box ocupado">
            <div class="stat-icono"><i class="bi bi-calendar-event"></i></div>
            <div class="stat-info">
                <span class="stat-numero"><?php echo count($ocupaciones_por_dia); ?></span>
                <span class="stat-label">Días con Ocupaciones</span>
            </div>
        </div>

        <div class="stat-box disponible">
            <div class="stat-icono"><i class="bi bi-check-circle-fill"></i></div>
            <div class="stat-info">
                <span class="stat-numero"><?php echo $meses_nombre[$mes]; ?></span>
                <span class="stat-label">Mes Actual</span>
            </div>
        </div>

        <div class="stat-box">
            <div class="stat-icono"><i class="bi bi-calendar3"></i></div>
            <div class="stat-info">
                <span class="stat-numero"><?php echo $dias_mes; ?></span>
                <span class="stat-label">Días del Mes</span>
            </div>
        </div>
    </div>

    <!-- Navegación semanal (solo móvil) -->
    <div class="nav-semana" id="navSemana">
        <button class="btn-semana" id="btnSemAnt">&#9664; Anterior</button>
        <span class="lbl-semana" id="lblSemana"></span>
        <button class="btn-semana" id="btnSemSig">Siguiente &#9654;</button>
    </div>

    <!-- Calendario -->
    <div class="calendario-contenedor">
        <div class="calendario">
            <div class="calendario-grid">
            <div class="calendario-header">
                <div class="dia-nombre">Lun</div>
                <div class="dia-nombre">Mar</div>
                <div class="dia-nombre">Mié</div>
                <div class="dia-nombre">Jue</div>
                <div class="dia-nombre">Vie</div>
                <div class="dia-nombre">Sáb</div>
            </div>

            <div class="calendario-dias">
                <?php
                for($i = 0; $i < $offset_cal; $i++) {
                    echo '<div class="dia-vacio" data-week="' . (int)floor($i / 6) . '"></div>';
                }

                $hoy = date('Y-m-d');
                $col_render = $offset_cal;
                for($dia = 1; $dia <= $dias_mes; $dia++) {
                    if(date('N', mktime(0,0,0,$mes,$dia,$anio)) == 7) continue;
                    $w = (int)floor($col_render / 6);
                    $col_render++;
                    $fecha_completa = sprintf("%04d-%02d-%02d", $anio, $mes, $dia);
                    $es_hoy = ($fecha_completa == $hoy);
                    $tiene_ocupaciones = isset($ocupaciones_por_dia[$dia]);
                    $num_ocupaciones = $tiene_ocupaciones ? count($ocupaciones_por_dia[$dia]) : 0;

                    $es_pasado = ($fecha_completa < $hoy);
                    $sin_formacion = isset($dias_sin_formacion[$dia]);
                    $clase_dia = 'dia-celda';
                    if($es_hoy) $clase_dia .= ' hoy';
                    if($es_pasado) $clase_dia .= ' pasado';
                    if($tiene_ocupaciones) $clase_dia .= ' tiene-ocupaciones';
                    if($sin_formacion) $clase_dia .= ' sin-formacion';

                    echo '<div class="' . $clase_dia . '" data-week="' . $w . '" data-dia="' . $dia . '" onclick="clickDia(' . $dia . ', \'' . $fecha_completa . '\', ' . ($tiene_ocupaciones ? 'true' : 'false') . ')">';
                    echo '<div class="dia-numero">' . $dia . '</div>';

                    if($sin_formacion) {
                        echo '<div class="dia-badge-sf"><i class="bi bi-calendar-x-fill"></i> Día sin formación</div>';
                        foreach(array_slice($dias_sin_formacion[$dia], 0, 2) as $sf) {
                            $et = $sf['codigo_ficha'] ? 'Ficha ' . htmlspecialchars($sf['codigo_ficha']) : 'Clase';
                            if(!empty($sf['jornada'])) $et .= ' · ' . htmlspecialchars($sf['jornada']);
                            echo '<div class="dia-sf-mini"><i class="bi bi-x-circle"></i> ' . $et . '</div>';
                        }
                    }

                    if($tiene_ocupaciones) {
                        echo '<div class="dia-badge">' . $num_ocupaciones . ' ocupación' . ($num_ocupaciones > 1 ? 'es' : '') . '</div>';

                        $preview = array_slice($ocupaciones_por_dia[$dia], 0, 2);
                        foreach($preview as $ocup) {
                            $icono_jornada = '<i class="bi bi-calendar-event"></i>';
                            if($ocup['jornada'] == 'mañana') $icono_jornada = '<i class="bi bi-sunrise"></i>';
                            elseif($ocup['jornada'] == 'tarde') $icono_jornada = '<i class="bi bi-sun"></i>';
                            elseif($ocup['jornada'] == 'noche') $icono_jornada = '<i class="bi bi-moon-stars"></i>';

                            $hora_inicio = date('H:i', strtotime($ocup['fecha_inicio']));
                            $clase_mini = $ocup['estado'] == 'finalizado' ? 'dia-ocupacion-mini finalizado' : 'dia-ocupacion-mini';
                            echo '<div class="' . $clase_mini . '">';
                            echo $icono_jornada . ' ' . $hora_inicio . ' - ' . htmlspecialchars($ocup['ambiente_nombre']);
                            echo '</div>';
                            if(!empty($ocup['instructor_nombre'])) {
                                echo '<div class="dia-ocupacion-instr"><i class="bi bi-person"></i> ' . htmlspecialchars($ocup['instructor_nombre']) . '</div>';
                            }
                        }

                        if($num_ocupaciones > 2) {
                            echo '<div class="dia-mas">+' . ($num_ocupaciones - 2) . ' más</div>';
                        }
                    } elseif(!$es_pasado && !$sin_formacion) {
                        echo '<div class="dia-solicitar-hint"><i class="bi bi-plus-circle-fill"></i> Solicitar ocupación</div>';
                    }

                    echo '</div>';
                }
                ?>
            </div>
            </div><!-- /calendario-grid -->
        </div>
    </div>

    <!-- MODAL SOLICITAR OCUPACIÓN (cualquier día, aunque no haya clase) -->
    <div class="modal" id="modalSolicitar">
        <div class="modal-contenido">
            <div class="modal-encabezado">
                <h2><i class="bi bi-box-arrow-in-down"></i> Solicitar ocupación</h2>
                <button class="modal-cerrar" type="button" onclick="cerrarModalSolicitar()"><i class="bi bi-x-lg"></i></button>
            </div>
            <?php if($ficha_ambiente_id): ?>
            <form method="POST" action="../../controllers/SolicitarOcupacionVocero.php" id="formSolicitar">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="fecha" id="solFecha" value="">

                <div class="form-grupo">
                    <label>Ambiente de tu ficha</label>
                    <input type="text" value="<?php echo htmlspecialchars($ficha_ambiente_txt); ?>" readonly style="background:#f3f4f6;cursor:not-allowed;">
                </div>
                <div class="form-grupo">
                    <label>Día</label>
                    <input type="text" id="solFechaTexto" readonly style="background:#f3f4f6;cursor:not-allowed;">
                </div>
                <div class="form-grupo">
                    <label>Jornada *</label>
                    <select name="jornada" required>
                        <option value="">Seleccionar jornada...</option>
                        <option value="mañana">Mañana (06:00 - 12:00)</option>
                        <option value="tarde">Tarde (12:00 - 18:00)</option>
                        <option value="noche">Noche (18:00 - 22:00)</option>
                    </select>
                </div>
                <div class="form-grupo">
                    <label>Motivo *</label>
                    <textarea name="motivo" rows="2" required placeholder="¿Por qué solicitas la ocupación? p. ej. el instructor no ha llegado"></textarea>
                </div>
                <div class="form-botones">
                    <button type="button" class="btn-cancelar" onclick="cerrarModalSolicitar()">Cancelar</button>
                    <button type="submit" class="btn-guardar"><i class="bi bi-send"></i> Solicitar ocupación</button>
                </div>
            </form>
            <?php else: ?>
            <p style="padding:10px 4px;color:#6b7280;">Tu ficha no tiene un ambiente asignado. Avísale al administrador.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- TOAST NOTIFICACIÓN -->
    <div id="toastNotificacion" style="
        position:fixed; bottom:20px; left:50%; transform:translateX(-50%) translateY(100px);
        background:#1f2937; color:#fff; padding:10px 18px; border-radius:10px;
        font-size:13px; font-weight:600; z-index:9999; display:flex; align-items:center;
        gap:6px; box-shadow:0 4px 14px rgba(0,0,0,0.3); max-width:92vw; width:92vw; text-align:center;
        transition:transform 0.35s cubic-bezier(0.34,1.56,0.64,1), opacity 0.35s ease;
        opacity:0; pointer-events:none; white-space:normal;">
    </div>

    <!-- MODAL DETALLE DEL DÍA -->
    <div class="modal" id="modalDetalleDia">
        <div class="modal-contenido-grande">
            <button class="modal-cerrar" onclick="cerrarModalDetalle()"><i class="bi bi-x-lg"></i></button>
            <h2 id="detalleTitulo" style="color:var(--gris-oscuro);font-size:28px;font-weight:800;margin-bottom:25px;padding-bottom:15px;border-bottom:3px solid var(--verde-acento);"></h2>

            <div id="detalleListaOcupaciones"></div>
        </div>
    </div>

    <!-- MODAL NUEVA OCUPACIÓN -->
    <div class="modal" id="modalNuevaOcupacion">
        <div class="modal-contenido">
            <div class="modal-encabezado">
                <h2><i class="bi bi-plus-lg"></i> Nueva Ocupación</h2>
                <button class="modal-cerrar" type="button" onclick="cerrarModalNuevaOcupacion()"><i class="bi bi-x-lg"></i></button>
            </div>

            <form action="../../controllers/RegistrarOcupacionMultiple.php" method="POST" id="formOcupacion">
                <?php echo csrf_field(); ?>

                <!-- Checkbox para múltiples días -->
                <div class="form-grupo">
                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                        <input type="checkbox" id="checkMultiple" onchange="toggleModoMultiple()" style="width: auto; cursor: pointer;">
                        <span><i class="bi bi-calendar-event"></i> Registrar en múltiples días</span>
                    </label>
                </div>

                <!-- MODO SIMPLE: Una fecha -->
                <div id="modoSimple">
                    <div class="form-grupo">
                        <label>Fecha *</label>
                        <input type="date" name="fecha" id="fechaOcupacion" required readonly style="background: var(--gris-claro); cursor: not-allowed;">
                    </div>
                </div>

                <!-- MODO MÚLTIPLE: Varias fechas -->
                <div id="modoMultiple" style="display: none;">
                    <div class="form-grupo">
                        <label>Selecciona los días *</label>
                        <div class="info-multiple">
                            <i class="bi bi-lightbulb"></i> Selecciona múltiples días para registrar la misma ocupación
                        </div>
                        <div id="selectorFechas" class="selector-fechas">
                            <!-- Se generará dinámicamente con JavaScript -->
                        </div>
                        <input type="hidden" name="fechas_seleccionadas" id="fechasMultiples">
                    </div>
                </div>

                <?php if($vocero_tipo_contrato == 'contratista'): ?>
                <!-- CONTRATISTA: Primero sede, luego ambientes dinámicos -->
                <div class="form-grupo">
                    <label>Sede *</label>
                    <select id="sedeSelect" onchange="cargarAmbientesPorSede(this.value)">
                        <option value="">Seleccionar sede...</option>
                        <?php while($sede = mysqli_fetch_assoc($sedes_result)): ?>
                            <option value="<?php echo $sede['id']; ?>">
                                <?php echo htmlspecialchars($sede['nombre'] . ' — ' . $sede['ciudad']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-grupo">
                    <label>Ambiente *</label>
                    <select name="ambiente_id" required id="ambienteSelect" disabled>
                        <option value="">Primero selecciona una sede...</option>
                    </select>
                </div>
                <?php else: ?>
                <!-- PLANTA: ambientes de su sede fija -->
                <div class="form-grupo">
                    <label>Ambiente *</label>
                    <select name="ambiente_id" required id="ambienteSelect">
                        <option value="">Seleccionar ambiente...</option>
                        <?php while($amb = mysqli_fetch_assoc($ambientes_result)): ?>
                            <option value="<?php echo $amb['id']; ?>">
                                <?php echo htmlspecialchars($amb['piso_nombre'] . ' - ' . $amb['nombre']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="form-grupo">
                    <label>Jornada *</label>
                    <select name="jornada" id="jornadaSelect" required onchange="toggleHorarioPersonalizado()">
                        <option value="">Seleccionar jornada...</option>
                        <option value="mañana"> Mañana (06:00 - 12:00)</option>
                        <option value="tarde"> Tarde (12:00 - 18:00)</option>
                        <option value="noche"> Noche (18:00 - 22:00)</option>
                        <option value="otro">Otro horario (personalizado)</option>
                    </select>
                </div>

                <!-- HORARIO PERSONALIZADO -->
                <div id="horarioPersonalizado" style="display: none;">
                    <div class="form-row" style="display: flex; gap: 15px;">
                        <div class="form-grupo" style="flex: 1;">
                            <label>Hora Inicio *</label>
                            <input type="time" name="hora_inicio" id="horaInicio" min="06:00" max="22:00">
                            <small>Rango: 06:00 - 22:00</small>
                        </div>
                        <div class="form-grupo" style="flex: 1;">
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
                    <button type="button" class="btn-cancelar" onclick="cerrarModalNuevaOcupacion()">Cancelar</button>
                    <button type="submit" class="btn-guardar" id="btnGuardar">Registrar Ocupación</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const btnMenu = document.getElementById('btnMenu');
        const modalMenu = document.getElementById('modalMenu');

        if(btnMenu && modalMenu) {
            btnMenu.addEventListener('click', () => modalMenu.classList.add('active'));
            modalMenu.addEventListener('click', (e) => {
                if(e.target === modalMenu) modalMenu.classList.remove('active');
            });
        }

        let fechaSeleccionada = '';
        let fechasSeleccionadas = [];
        const mesActual = <?php echo $mes; ?>;
        const anioActual = <?php echo $anio; ?>;
        const csrfToken = <?php echo json_encode(csrf_token()); ?>;

        const voceroNombre = <?php echo json_encode($vocero_nombre); ?>;

        // Datos de ocupaciones por día pasados desde PHP
        const ocupacionesPorDia = <?php
            $datos_js = [];
            foreach($ocupaciones_por_dia as $dia_num => $lista) {
                $datos_js[$dia_num] = array_map(function($o) {
                    return [
                        'id'              => $o['id'],
                        'ambiente_nombre' => $o['ambiente_nombre'],
                        'piso_nombre'     => $o['piso_nombre'],
                        'sede_nombre'     => $o['sede_nombre'],
                        'jornada'         => $o['jornada'],
                        'estado'          => $o['estado'],
                        'fecha_inicio'    => $o['fecha_inicio'],
                        'fecha_fin'       => $o['fecha_fin'],
                        'observaciones'   => $o['observaciones'],
                        'instructor'      => $o['instructor_nombre'],
                    ];
                }, $lista);
            }
            echo json_encode($datos_js);
        ?>;

        function esFechaPasada(fecha) {
            const hoy = new Date();
            hoy.setHours(0, 0, 0, 0);
            return new Date(fecha + 'T12:00:00') < hoy;
        }

        let toastTimer = null;
        function mostrarToast(mensaje, tipo = 'error') {
            const toast = document.getElementById('toastNotificacion');
            const colores = {
                error:   { bg: '#dc2626', icon: '<i class="bi bi-slash-circle"></i>' },
                aviso:   { bg: '#d97706', icon: '<i class="bi bi-exclamation-triangle-fill"></i>' },
                exito:   { bg: '#059669', icon: '<i class="bi bi-check-circle-fill"></i>' },
            };
            const c = colores[tipo] || colores.error;
            toast.style.background = c.bg;
            toast.innerHTML = `${c.icon} ${mensaje}`;
            toast.style.opacity = '1';
            toast.style.transform = 'translateX(-50%) translateY(0)';
            if(toastTimer) clearTimeout(toastTimer);
            toastTimer = setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateX(-50%) translateY(100px)';
            }, 3500);
        }

        function clickDia(dia, fecha, tieneOcupaciones) {
            fechaSeleccionada = fecha;
            if(tieneOcupaciones) {
                mostrarDetalleDia(dia, fecha);
                return;
            }
            // Sin clase registrada: puedes solicitar ocupar el ambiente de tu ficha (hoy o a futuro).
            if(esFechaPasada(fecha)) {
                mostrarToast('No puedes solicitar ocupación en días pasados', 'aviso');
                return;
            }
            abrirModalSolicitar(fecha);
        }

        function abrirModalSolicitar(fecha) {
            const modal = document.getElementById('modalSolicitar');
            if(!modal) return;
            const form = document.getElementById('formSolicitar');
            if(form) form.reset();
            const inputFecha = document.getElementById('solFecha');
            const inputTexto = document.getElementById('solFechaTexto');
            if(inputFecha) inputFecha.value = fecha;
            if(inputTexto) {
                const d = new Date(fecha + 'T12:00:00');
                let txt = d.toLocaleDateString('es-ES', { weekday:'long', year:'numeric', month:'long', day:'numeric' });
                inputTexto.value = txt.charAt(0).toUpperCase() + txt.slice(1);
            }
            modal.style.display = 'flex';
        }

        function cerrarModalSolicitar() {
            const modal = document.getElementById('modalSolicitar');
            if(modal) modal.style.display = 'none';
        }

        function mostrarDetalleDia(dia, fecha) {
            const fechaObj = new Date(fecha + 'T12:00:00');
            const opciones = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
            const fechaFormato = fechaObj.toLocaleDateString('es-ES', opciones);
            document.getElementById('detalleTitulo').innerHTML = '<i class="bi bi-calendar-event"></i> ' + fechaFormato.charAt(0).toUpperCase() + fechaFormato.slice(1);

            const hoyStr = (function(){ const d = new Date(); const p = n => String(n).padStart(2,'0'); return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate()); })();
            const esHoy = (fecha === hoyStr);

            const ocupaciones = ocupacionesPorDia[dia] || [];
            let html = '<div class="ocupaciones-dia-lista">';

            ocupaciones.forEach(o => {
                const horaInicio = o.fecha_inicio.split(' ')[1].substring(0, 5);
                const horaFin    = o.fecha_fin.split(' ')[1].substring(0, 5);

                let icono_estado = '', clase_estado = '', texto_estado = '';
                switch(o.estado) {
                    case 'ocupado':             icono_estado = '<i class="bi bi-circle-fill text-danger" style="font-size:.7rem"></i>'; clase_estado = 'ocupado';    texto_estado = 'OCUPADO'; break;
                    case 'proximo_a_desocupar': icono_estado = '<i class="bi bi-circle-fill text-warning" style="font-size:.7rem"></i>'; clase_estado = 'proximo';    texto_estado = 'PRÓXIMO A DESOCUPAR'; break;
                    case 'disponible':          icono_estado = '<i class="bi bi-circle-fill text-success" style="font-size:.7rem"></i>'; clase_estado = 'disponible'; texto_estado = 'DISPONIBLE'; break;
                    case 'finalizado':          icono_estado = '<i class="bi bi-circle-fill text-danger" style="font-size:.7rem"></i>'; clase_estado = 'finalizado'; texto_estado = 'FINALIZADO'; break;
                }

                let icono_jornada = '<i class="bi bi-calendar-event"></i>', texto_jornada = o.jornada;
                if(o.jornada == 'mañana')      { icono_jornada = '<i class="bi bi-sunrise"></i>'; texto_jornada = 'Mañana'; }
                else if(o.jornada == 'tarde')  { icono_jornada = '<i class="bi bi-sun"></i>'; texto_jornada = 'Tarde'; }
                else if(o.jornada == 'noche')  { icono_jornada = '<i class="bi bi-moon-stars"></i>'; texto_jornada = 'Noche'; }
                else if(o.jornada == 'personalizado') { icono_jornada = '<i class="bi bi-alarm"></i>'; texto_jornada = 'Personalizado'; }

                html += `
                    <div class="ocupacion-card-modal ${clase_estado}">
                        <div class="ocupacion-header">
                            <span class="ocupacion-estado">${icono_estado} ${texto_estado}</span>
                            <span class="ocupacion-jornada">${icono_jornada} ${texto_jornada}</span>
                        </div>
                        <div class="ocupacion-body">
                            <h3 class="ocupacion-ambiente">${o.piso_nombre} - ${o.ambiente_nombre}</h3>
                            <p class="ocupacion-instructor"><i class="bi bi-person"></i> ${o.instructor || 'Por confirmar'}</p>
                            <p class="ocupacion-horario"><i class="bi bi-clock"></i> ${horaInicio} - ${horaFin}</p>
                            ${o.observaciones ? '<p class="ocupacion-obs"><i class="bi bi-card-text"></i> ' + o.observaciones + '</p>' : ''}
                        </div>
                        ${(esHoy && (o.estado === 'ocupado' || o.estado === 'proximo_a_desocupar')) ?
                            `<div class="ocupacion-footer">
                                <form method="POST" action="../../controllers/SolicitarFinalizacionVocero.php"
                                      onsubmit="return confirm('¿Solicitar al celador finalizar esta ocupación?')">
                                    <input type="hidden" name="csrf_token" value="${csrfToken}">
                                    <input type="hidden" name="id" value="${o.id}">
                                    <input type="hidden" name="origen" value="calendario">
                                    <button type="submit" class="btn-finalizar"><i class="bi bi-check-circle-fill"></i> Solicitar finalización</button>
                                </form>
                            </div>` : ''}
                    </div>
                `;
            });

            html += '</div>';
            document.getElementById('detalleListaOcupaciones').innerHTML = html;
            document.getElementById('modalDetalleDia').style.display = 'flex';
        }

        function cerrarModalDetalle() {
            document.getElementById('modalDetalleDia').style.display = 'none';
        }

        function abrirNuevaDesdeDetalle() {
            if(esFechaPasada(fechaSeleccionada)) {
                mostrarToast('No se pueden registrar ocupaciones en fechas pasadas', 'aviso');
                return;
            }
            cerrarModalDetalle();
            document.getElementById('fechaOcupacion').value = fechaSeleccionada;
            document.getElementById('modalNuevaOcupacion').style.display = 'flex';
        }

        function cerrarModalNuevaOcupacion() {
            document.getElementById('modalNuevaOcupacion').style.display = 'none';
            document.getElementById('formOcupacion').reset();
            document.getElementById('checkMultiple').checked = false;
            toggleModoMultiple();
            fechasSeleccionadas = [];
            const sedeSelect     = document.getElementById('sedeSelect');
            const ambienteSelect = document.getElementById('ambienteSelect');
            if(sedeSelect) sedeSelect.value = '';
            if(ambienteSelect && sedeSelect) {
                ambienteSelect.innerHTML = '<option value="">Primero selecciona una sede...</option>';
                ambienteSelect.disabled  = true;
            }
        }

        function cargarAmbientesPorSede(sedeId) {
            const select = document.getElementById('ambienteSelect');
            if(!sedeId) {
                select.innerHTML = '<option value="">Primero selecciona una sede...</option>';
                select.disabled  = true;
                return;
            }
            select.innerHTML = '<option value="">Cargando ambientes...</option>';
            select.disabled  = true;
            fetch(`../../controllers/ObtenerAmbiente.php?sede_id=${sedeId}`)
                .then(r => r.json())
                .then(ambientes => {
                    if(!Array.isArray(ambientes) || ambientes.length === 0) {
                        select.innerHTML = '<option value="">No hay ambientes en esta sede</option>';
                        return;
                    }
                    select.innerHTML = '<option value="">Seleccionar ambiente...</option>';
                    ambientes.forEach(a => {
                        const opt = document.createElement('option');
                        opt.value       = a.id;
                        opt.textContent = a.piso_nombre + ' - ' + a.nombre;
                        select.appendChild(opt);
                    });
                    select.disabled = false;
                })
                .catch(() => {
                    select.innerHTML = '<option value="">Error al cargar ambientes</option>';
                });
        }

        function toggleHorarioPersonalizado() {
            const jornada = document.getElementById('jornadaSelect').value;
            const horarioDiv = document.getElementById('horarioPersonalizado');
            const horaInicio = document.getElementById('horaInicio');
            const horaFin = document.getElementById('horaFin');

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

        function toggleModoMultiple() {
            const checkMultiple = document.getElementById('checkMultiple');
            const modoSimple = document.getElementById('modoSimple');
            const modoMultiple = document.getElementById('modoMultiple');
            const fechaSimple = document.getElementById('fechaOcupacion');
            const btnGuardar = document.getElementById('btnGuardar');

            if(checkMultiple.checked) {
                modoSimple.style.display = 'none';
                modoMultiple.style.display = 'block';
                fechaSimple.removeAttribute('required');
                btnGuardar.innerHTML = '<i class="bi bi-check-circle-fill"></i> Registrar en Días Seleccionados';
                generarSelectorFechas();
            } else {
                modoSimple.style.display = 'block';
                modoMultiple.style.display = 'none';
                fechaSimple.setAttribute('required', 'required');
                btnGuardar.textContent = 'Registrar Ocupación';
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
            const diasMes = new Date(anioActual, mesActual, 0).getDate();
            const hoy = new Date();
            hoy.setHours(0, 0, 0, 0);

            let html = '<div class="dias-grid">';

            for(let dia = 1; dia <= diasMes; dia++) {
                const fecha    = new Date(anioActual, mesActual - 1, dia);
                const fechaStr = `${anioActual}-${String(mesActual).padStart(2,'0')}-${String(dia).padStart(2,'0')}`;
                const esPasado  = fecha < hoy;
                const esDomingo = fecha.getDay() === 0;
                const esFestivo = FESTIVOS_CO.includes(fechaStr);

                if(!esPasado && !esDomingo && !esFestivo) {
                    html += `
                        <div class="dia-selector ${fechaStr === fechaSeleccionada ? 'seleccionado' : ''}"
                             data-fecha="${fechaStr}"
                             onclick="toggleFecha('${fechaStr}')">
                            <div class="dia-num">${dia}</div>
                            <div class="dia-mes">${DIAS_SEMANA[fecha.getDay()]}</div>
                        </div>`;
                }
            }

            html += '</div>';
            html += '<div class="contador-seleccion" id="contadorSeleccion">0 días seleccionados</div>';
            container.innerHTML = html;
        }

        function toggleFecha(fecha) {
            const index = fechasSeleccionadas.indexOf(fecha);
            const elemento = document.querySelector(`[data-fecha="${fecha}"]`);

            if(index > -1) {
                fechasSeleccionadas.splice(index, 1);
                elemento.classList.remove('seleccionado');
            } else {
                fechasSeleccionadas.push(fecha);
                elemento.classList.add('seleccionado');
            }

            document.getElementById('fechasMultiples').value = JSON.stringify(fechasSeleccionadas);
            document.getElementById('contadorSeleccion').textContent =
                `${fechasSeleccionadas.length} día${fechasSeleccionadas.length !== 1 ? 's' : ''} seleccionado${fechasSeleccionadas.length !== 1 ? 's' : ''}`;
        }

        function obtenerNombreMes(mes) {
            const meses = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
            return meses[mes - 1];
        }

        const formOcupacion = document.getElementById('formOcupacion');
        if(formOcupacion) {
            formOcupacion.addEventListener('submit', function(e) {
                const checkMultiple = document.getElementById('checkMultiple');

                if(checkMultiple && checkMultiple.checked && fechasSeleccionadas.length === 0) {
                    e.preventDefault();
                    alert('Debes seleccionar al menos un día.');
                    return false;
                }
            });
        }

        document.addEventListener('keydown', (e) => {
            if(e.key === 'Escape') { cerrarModalSolicitar(); cerrarModalDetalle(); }
        });

        // Cerrar el modal de solicitud al hacer clic fuera de su contenido
        document.getElementById('modalSolicitar')?.addEventListener('click', (e) => {
            if(e.target === e.currentTarget) cerrarModalSolicitar();
        });

        setTimeout(() => {
            const alert = document.getElementById('mensajeAlert');
            if(alert) alert.style.display = 'none';
        }, 5000);

        function irAFecha(fecha) {
            if(!fecha) return;
            const [y, m, d] = fecha.split('-');
            window.location.href = '?mes=' + parseInt(m) + '&anio=' + y + '&dia=' + parseInt(d);
        }

        const diaSeleccionado = <?php echo $dia_seleccionado; ?>;
        if(diaSeleccionado > 0) {
            const pad = n => String(n).padStart(2,'0');
            const fechaAuto = anioActual + '-' + pad(mesActual) + '-' + pad(diaSeleccionado);
            document.getElementById('inputBuscarFecha').value = fechaAuto;
            const celda = document.querySelector('[data-dia="' + diaSeleccionado + '"]');
            if(celda) {
                celda.classList.add('buscado');
                setTimeout(() => celda.scrollIntoView({ behavior: 'smooth', block: 'center' }), 100);
            }
        }

        // Navegación semanal (solo móvil)
        (function(){
            if(window.innerWidth > 768) return;
            let semana = <?php echo $semana_inicial_cal; ?>;
            const total = <?php echo $total_semanas_cal; ?>;
            const urlAnt = '?mes=<?php echo $mes_ant; ?>&anio=<?php echo $anio_ant; ?>&semana=last';
            const urlSig = '?mes=<?php echo $mes_sig; ?>&anio=<?php echo $anio_sig; ?>';
            const mesAnio = '<?php echo $meses_nombre[$mes] . ' ' . $anio; ?>';
            const celdas = document.querySelectorAll('.calendario-dias [data-week]');
            const lbl = document.getElementById('lblSemana');
            function mostrar(n) {
                celdas.forEach(el => { el.style.display = (parseInt(el.dataset.week) === n) ? 'block' : 'none'; });
                lbl.textContent = mesAnio + ' · Semana ' + (n+1) + ' / ' + total;
            }
            document.getElementById('btnSemAnt').onclick = function() {
                if(semana > 0) { semana--; mostrar(semana); } else window.location.href = urlAnt;
            };
            document.getElementById('btnSemSig').onclick = function() {
                if(semana < total-1) { semana++; mostrar(semana); } else window.location.href = urlSig;
            };
            mostrar(semana);
        })();
    </script>
</body>
</html>
