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

// Auto-finalizar ocupaciones cuya fecha_fin ya pasó (usando hora PHP para respetar timezone Bogotá)
$ahora = date('Y-m-d H:i:s');
mysqli_query($con, "UPDATE historial_ocupacion SET estado = 'finalizado' WHERE fecha_fin <= '$ahora' AND estado NOT IN ('finalizado', 'cancelado')");

// ⭐ OBTENER SEDE DEL CELADOR
$celador_sede_id = $_SESSION['sede_id'];

// Obtener información de la sede
$sede_query = mysqli_query($con, "SELECT nombre, ciudad FROM sedes WHERE id = $celador_sede_id");
if(mysqli_num_rows($sede_query) > 0) {
    $sede_info = mysqli_fetch_assoc($sede_query);
    $sede_nombre = $sede_info['nombre'];
    $sede_ciudad = $sede_info['ciudad'];
} else {
    header("Location: ../../controllers/logout.php");
    exit();
}

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
    $col_cnt++;
    if($d == $hoy_dia) $semana_hoy_cal = (int)floor(($col_cnt - 1) / 6);
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

// OBTENER TODAS LAS OCUPACIONES DEL MES DE LA SEDE DEL CELADOR
$fecha_inicio_mes = "$anio-$mes-01";
$fecha_fin_mes = "$anio-$mes-$dias_mes";

$query_ocupaciones = "SELECT ho.*, 
                      a.nombre AS ambiente_nombre,
                      p.nombre AS piso_nombre,
                      CONCAT_WS(' ', u.nombre, u.apellido) AS instructor_nombre,
                      DATE(ho.fecha_inicio) as fecha_ocupacion
                      FROM historial_ocupacion ho
                      LEFT JOIN ambientes a ON ho.ambiente_id = a.id
                      LEFT JOIN pisos p ON a.piso_id = p.id
                      LEFT JOIN usuarios u ON ho.usuario_id = u.id
                      WHERE DATE(ho.fecha_inicio) BETWEEN '$fecha_inicio_mes' AND '$fecha_fin_mes'
                      AND p.sede_id = $celador_sede_id
                      AND ho.estado <> 'cancelado'
                      ORDER BY ho.fecha_inicio ASC";

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
$ocupados_mes = 0;
$finalizados_mes = 0;

mysqli_data_seek($result_ocupaciones, 0);
while($o = mysqli_fetch_assoc($result_ocupaciones)) {
    if($o['estado'] == 'ocupado' || $o['estado'] == 'proximo_a_desocupar') $ocupados_mes++;
    if($o['estado'] == 'finalizado') $finalizados_mes++;
}

// OBTENER AMBIENTES DE LA SEDE
$ambientes_query = "SELECT a.id, a.nombre, p.nombre AS piso_nombre
                    FROM ambientes a
                    LEFT JOIN pisos p ON a.piso_id = p.id
                    WHERE p.sede_id = $celador_sede_id
                    ORDER BY p.nombre, a.nombre";
$ambientes_result = mysqli_query($con, $ambientes_query);

// OBTENER INSTRUCTORES ACTIVOS
$instructores_query = "SELECT id, nombre, apellido, cedula
                       FROM usuarios
                       WHERE rol = 'instructor' AND estado = 'activo' AND (sede_id = $celador_sede_id OR tipo_contrato = 'contratista')
                       ORDER BY nombre, apellido";
$instructores_result = mysqli_query($con, $instructores_query);

// Mensajes
$msg = $_GET['msg'] ?? '';
$error = $_GET['error'] ?? '';
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Calendario - <?php echo $sede_nombre; ?></title>
    <link rel="stylesheet" href="../../assets/css/calendario_celador.css?v=20261013">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="../../assets/js/calendario_habil.js"></script>
    <link rel="stylesheet" href="../../assets/css/escaner_carnet.css?v=20261010-5">
    <script src="../../assets/js/escaner_carnet.js?v=20261010-5" defer></script>
</head>
<body>
    <div id="toastNotificacion" style="
        position:fixed; bottom:20px; left:50%; transform:translateX(-50%) translateY(100px);
        background:#d97706; color:#fff; padding:10px 18px; border-radius:10px;
        font-size:13px; font-weight:600; z-index:9999; display:flex; align-items:center;
        gap:6px; box-shadow:0 4px 14px rgba(0,0,0,0.3); max-width:92vw; width:92vw; text-align:center;
        transition:transform 0.35s cubic-bezier(0.34,1.56,0.64,1), opacity 0.35s ease;
        opacity:0; pointer-events:none; white-space:normal;">
    </div>
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
                <span class="usuario-nombre"><?php echo htmlspecialchars($_SESSION['nombre'] . ' ' . ($_SESSION['apellido'] ?? '')); ?></span>
                <span class="usuario-tipo">Celador</span>
            </div>
            <a class="btn-logout" href="../../controllers/logout.php">
                <i class="bi bi-box-arrow-right"></i>
            </a>
        </div>
    </div>

    <!-- Sidebar -->
    <div class="modal-menu" id="modalMenu">
        <div class="sidebar">
            <a href="dashboard_celador.php">
                <i class="bi bi-bar-chart-fill"></i>Dashboard
            </a>
            <a href="ocupaciones_celador.php">
                <i class="bi bi-calendar-check"></i>Ocupaciones
            </a>
            <a href="calendario_celador.php" class="active">
                <i class="bi bi-calendar3"></i>Calendario
            </a>
            <a href="historial_celador.php">
                <i class="bi bi-clock-history"></i>Historial
            </a>
        </div>
    </div>

    <!-- Mensajes -->
    <?php if($msg): ?>
        <div class="mensaje-alert mensaje-exito" id="mensajeAlert" style="max-width: 1600px; margin: 20px auto; padding: 0 40px;">
            <div style="background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; padding: 16px 24px; border-radius: 12px; font-size: 15px; font-weight: 600;">
                <?php
                if($msg == 'ocupacion_registrada') echo '<i class="bi bi-check-circle-fill"></i> Ocupación registrada correctamente';
                if($msg == 'ocupacion_finalizada') echo '<i class="bi bi-check-circle-fill"></i> Ocupación finalizada correctamente';
            elseif($msg == 'ocupacion_cancelada') echo '<i class="bi bi-trash3-fill"></i> Ocupación eliminada: el ambiente quedó libre';
                if($msg == 'ocupaciones_multiples') {
                    $total = isset($_GET['total']) ? intval($_GET['total']) : 0;
                    echo '<i class="bi bi-check-circle-fill"></i> ' . $total . ' ocupaciones registradas correctamente';
                }
                ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if($error): ?>
        <div class="mensaje-alert mensaje-error" id="mensajeAlert" style="max-width: 1600px; margin: 20px auto; padding: 0 40px;">
            <div style="background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; padding: 16px 24px; border-radius: 12px; font-size: 15px; font-weight: 600;">
                <?php
                if($error == 'campos_vacios') echo '<i class="bi bi-exclamation-triangle-fill"></i> Complete todos los campos obligatorios';
                if($error == 'ambiente_ocupado') echo '<i class="bi bi-exclamation-triangle-fill"></i> El ambiente ya está ocupado en ese horario';
                if($error == 'registro_fallido') echo '<i class="bi bi-x-circle-fill"></i> Error al registrar la ocupación';
                if($error == 'fecha_pasada') echo '<i class="bi bi-exclamation-triangle-fill"></i> No se pueden registrar ocupaciones en fechas pasadas';
                elseif($error == 'dia_no_habil') echo '<i class="bi bi-calendar-x-fill"></i> No se pueden registrar ocupaciones en domingos ni días festivos';
                if($error == 'hora_pasada') echo '<i class="bi bi-exclamation-triangle-fill"></i> No se pueden registrar ocupaciones con horas que ya pasaron';
                if($error == 'jornada_pasada') echo '<i class="bi bi-exclamation-triangle-fill"></i> La jornada seleccionada ya pasó';
                ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Encabezado -->
    <div class="encabezado">
        <div class="encabezado-contenido">
            <h1><i class="bi bi-calendar-event"></i> Calendario - <?php echo $sede_nombre; ?></h1>
            <p>Haz clic en un día para ver ocupaciones o registrar una nueva</p>
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
            <div class="stat-icono"><i class="bi bi-circle-fill text-danger" style="font-size:.7rem"></i></div>
            <div class="stat-info">
                <span class="stat-numero"><?php echo $ocupados_mes; ?></span>
                <span class="stat-label">Activas/Programadas</span>
            </div>
        </div>

        <div class="stat-box disponible">
            <div class="stat-icono"><i class="bi bi-check-circle-fill"></i></div>
            <div class="stat-info">
                <span class="stat-numero"><?php echo $finalizados_mes; ?></span>
                <span class="stat-label">Finalizadas</span>
            </div>
        </div>

        <div class="stat-box">
            <div class="stat-icono"><i class="bi bi-bar-chart-line"></i></div>
            <div class="stat-info">
                <span class="stat-numero"><?php echo count($ocupaciones_por_dia); ?></span>
                <span class="stat-label">Días con Ocupaciones</span>
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
                    $clase_dia = 'dia-celda';
                    if($es_hoy) $clase_dia .= ' hoy';
                    if($es_pasado) $clase_dia .= ' pasado';
                    if($tiene_ocupaciones) $clase_dia .= ' tiene-ocupaciones';

                    echo '<div class="' . $clase_dia . '" data-week="' . $w . '" data-dia="' . $dia . '" onclick="clickDia(' . $dia . ', \'' . $fecha_completa . '\', ' . ($tiene_ocupaciones ? 'true' : 'false') . ')">';
                    echo '<div class="dia-numero">' . $dia . '</div>';
                    
                    if($tiene_ocupaciones) {
                        echo '<div class="dia-badge">' . $num_ocupaciones . ' ocupación' . ($num_ocupaciones > 1 ? 'es' : '') . '</div>';
                        
                        $preview = array_slice($ocupaciones_por_dia[$dia], 0, 2);
                        foreach($preview as $ocup) {
                            $icono_jornada = '<i class="bi bi-calendar-event"></i>';
                            if($ocup['jornada'] == 'mañana') $icono_jornada = '<i class="bi bi-sunrise"></i>';
                            elseif($ocup['jornada'] == 'tarde') $icono_jornada = '<i class="bi bi-sun"></i>';
                            elseif($ocup['jornada'] == 'noche') $icono_jornada = '<i class="bi bi-moon-stars"></i>';
                            elseif($ocup['jornada'] == 'personalizado') $icono_jornada = '<i class="bi bi-alarm"></i>';
                            
                            $hora_inicio = date('H:i', strtotime($ocup['fecha_inicio']));
                            $clase_mini = $ocup['estado'] == 'finalizado' ? 'dia-ocupacion-mini finalizado' : 'dia-ocupacion-mini';
                            echo '<div class="' . $clase_mini . '">';
                            echo $icono_jornada . ' ' . $hora_inicio . ' - ' . $ocup['ambiente_nombre'];
                            echo '</div>';
                        }
                        
                        if($num_ocupaciones > 2) {
                            echo '<div class="dia-mas">+' . ($num_ocupaciones - 2) . ' más</div>';
                        }
                    } else {
                        echo '<div class="dia-vacio-hint"><i class="bi bi-plus-lg"></i> Agregar</div>';
                    }
                    
                    echo '</div>';
                }
                ?>
            </div>
            </div><!-- /calendario-grid -->
        </div>
    </div>

    <!-- MODAL OCUPACIONES DEL DÍA -->
    <div class="modal" id="modalDia">
        <div class="modal-contenido-grande">
            <button class="modal-cerrar" onclick="cerrarModalDia()"><i class="bi bi-x-lg"></i></button>
            <h2 id="tituloModalDia"></h2>
            <div id="contenidoOcupaciones"></div>
            <div class="modal-footer-botones">
                <button class="btn-modal-accion btn-nueva" onclick="abrirModalNuevaOcupacion()">
                    <i class="bi bi-plus-lg"></i> Registrar Nueva Ocupación
                </button>
            </div>
        </div>
    </div>

    <!-- MODAL NUEVA OCUPACIÓN CON REGISTRO MÚLTIPLE -->
    <div class="modal" id="modalNuevaOcupacion">
        <div class="modal-contenido">
            <button class="modal-cerrar" onclick="cerrarModalNuevaOcupacion()"><i class="bi bi-x-lg"></i></button>
            <h2><i class="bi bi-plus-lg"></i> Nueva Ocupación</h2>
            
            <form action="../../controllers/RegistrarOcupacion.php" method="POST" id="formOcupacion">
                <?php include __DIR__ . '/_escaner_carnet.php'; ?>
                <fieldset id="datosOcupacion" hidden disabled>
                    <div class="carnet-resumen"><p id="resumenCarnet" role="status"></p><button type="button" class="carnet-boton" id="volverEscaneo">Volver a escanear</button></div>
                <?php echo csrf_field(); ?>
                <input type="hidden" name="sede_id" value="<?php echo $celador_sede_id; ?>">
                <input type="hidden" name="return" value="calendario">

                <!-- ⭐ NUEVO: Selector de modo de registro -->
                <div class="form-grupo">
                    <label style="display: flex; align-items: center; gap: 10px;">
                        <input type="checkbox" id="checkMultiple" onchange="toggleModoMultiple()" style="width: auto;">
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

                <!-- ⭐ MODO MÚLTIPLE: Varias fechas -->
                <div id="modoMultiple" style="display: none;">
                    <div class="form-grupo">
                        <label>Selecciona los días *</label>
                        <div class="info-multiple">
                            <i class="bi bi-lightbulb"></i> Selecciona múltiples días para registrar la misma ocupación
                        </div>
                        <div id="selectorFechas" class="selector-fechas">
                            <!-- Se generará dinámicamente con JavaScript -->
                        </div>
                        <input type="hidden" name="fechas_multiples" id="fechasMultiples">
                    </div>
                </div>

                <div class="form-grupo">
                    <label>Ambiente *</label>
                    <select name="ambiente_id" required>
                        <option value="">Seleccionar ambiente...</option>
                        <?php 
                        mysqli_data_seek($ambientes_result, 0);
                        while($amb = mysqli_fetch_assoc($ambientes_result)): 
                        ?>
                            <option value="<?php echo $amb['id']; ?>">
                                <?php echo $amb['piso_nombre'] . ' - ' . $amb['nombre']; ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="form-grupo">
                    <label>Instructor *</label>
                    <select name="usuario_id" required>
                        <option value="">Seleccionar instructor...</option>
                        <?php 
                        mysqli_data_seek($instructores_result, 0);
                        while($inst = mysqli_fetch_assoc($instructores_result)): 
                        ?>
                            <option value="<?php echo (int)$inst['id']; ?>" data-cedula="<?php echo htmlspecialchars(trim($inst['cedula']), ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo $inst['nombre'] . ' ' . $inst['apellido'] . ' - ' . $inst['cedula']; ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

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
                            </fieldset>
            </form>
        </div>
    </div>

    <script>
        const sedeId = <?php echo $celador_sede_id; ?>;
        const ocupacionesPorDia = <?php echo json_encode($ocupaciones_por_dia); ?>;
        let fechaSeleccionada = '';

        // ⭐ FUNCIÓN PARA VALIDAR SI UNA FECHA ES PASADA
        function esFechaPasada(fecha) {
            const hoy = new Date();
            hoy.setHours(0, 0, 0, 0);
            const fechaComparar = new Date(fecha + 'T12:00:00');
            return fechaComparar < hoy;
        }

        // CLICK EN DÍA DEL CALENDARIO
        function clickDia(dia, fecha, tieneOcupaciones) {
            fechaSeleccionada = fecha;
            
            // ⭐ Si es fecha pasada, solo permitir VER ocupaciones (no registrar nuevas)
            if(esFechaPasada(fecha)) {
                if(tieneOcupaciones) {
                    verOcupacionesDia(dia, fecha);
                } else {
                    mostrarAviso('No se pueden registrar ocupaciones en fechas pasadas');
                }
                return;
            }
            
            // Fecha válida (hoy o futura)
            if(tieneOcupaciones) {
                verOcupacionesDia(dia, fecha);
            } else {
                abrirModalRegistroDirecto(fecha);
            }
        }

        // ABRIR MODAL REGISTRO DIRECTO
        function abrirModalRegistroDirecto(fecha) {
            document.getElementById('fechaOcupacion').value = fecha;
            document.getElementById('modalNuevaOcupacion').style.display = 'flex';
        }

        // VER OCUPACIONES DEL DÍA
        function verOcupacionesDia(dia, fecha) {
            const ocupaciones = ocupacionesPorDia[dia];
            
            if(!ocupaciones || ocupaciones.length === 0) return;

            const fechaObj = new Date(fecha + 'T12:00:00');
            const opciones = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
            const fechaFormato = fechaObj.toLocaleDateString('es-ES', opciones);
            
            document.getElementById('tituloModalDia').innerHTML = '<i class="bi bi-calendar-event"></i> ' + fechaFormato.charAt(0).toUpperCase() + fechaFormato.slice(1);
            
            let html = '<div class="ocupaciones-dia-lista">';
            
            ocupaciones.forEach(ocup => {
                let icono_estado = '', clase_estado = '', texto_estado = '';
                
                switch(ocup.estado) {
                    case 'ocupado': icono_estado = '<i class="bi bi-circle-fill text-danger" style="font-size:.7rem"></i>'; clase_estado = 'ocupado'; texto_estado = 'OCUPADO'; break;
                    case 'proximo_a_desocupar': icono_estado = '<i class="bi bi-circle-fill text-warning" style="font-size:.7rem"></i>'; clase_estado = 'proximo'; texto_estado = 'PRÓXIMO A DESOCUPAR'; break;
                    case 'disponible': icono_estado = '<i class="bi bi-circle-fill text-success" style="font-size:.7rem"></i>'; clase_estado = 'disponible'; texto_estado = 'DISPONIBLE'; break;
                    case 'finalizado': icono_estado = '<i class="bi bi-circle-fill text-danger" style="font-size:.7rem"></i>'; clase_estado = 'finalizado'; texto_estado = 'FINALIZADO'; break;
                }
                
                let icono_jornada = '<i class="bi bi-calendar-event"></i>', texto_jornada = ocup.jornada;
                
                if(ocup.jornada == 'mañana') { icono_jornada = '<i class="bi bi-sunrise"></i>'; texto_jornada = 'Mañana'; }
                else if(ocup.jornada == 'tarde') { icono_jornada = '<i class="bi bi-sun"></i>'; texto_jornada = 'Tarde'; }
                else if(ocup.jornada == 'noche') { icono_jornada = '<i class="bi bi-moon-stars"></i>'; texto_jornada = 'Noche'; }
                else if(ocup.jornada == 'personalizado') { icono_jornada = '<i class="bi bi-alarm"></i>'; texto_jornada = 'Personalizado'; }
                
                const horaInicio = ocup.fecha_inicio.split(' ')[1].substring(0, 5);
                const horaFin = ocup.fecha_fin.split(' ')[1].substring(0, 5);
                
                html += `
                    <div class="ocupacion-card-modal ${clase_estado}">
                        <div class="ocupacion-header">
                            <span class="ocupacion-estado">${icono_estado} ${texto_estado}</span>
                            <span class="ocupacion-jornada">${icono_jornada} ${texto_jornada}</span>
                        </div>
                        <div class="ocupacion-body">
                            <h3 class="ocupacion-ambiente">${ocup.piso_nombre} - ${ocup.ambiente_nombre}</h3>
                            <p class="ocupacion-instructor"><i class="bi bi-person"></i> ${ocup.instructor_nombre}</p>
                            <p class="ocupacion-horario"><i class="bi bi-clock"></i> ${horaInicio} - ${horaFin}</p>
                            ${ocup.observaciones ? '<p class="ocupacion-obs"><i class="bi bi-card-text"></i> ' + ocup.observaciones + '</p>' : ''}
                        </div>
                        ${['ocupado','proximo_a_desocupar'].includes(ocup.estado) ?
                            `<div class="ocupacion-footer">
                                <form method="post" action="../../controllers/FinalizarOcupacion.php" onsubmit="return confirm('${new Date(ocup.fecha_inicio.replace(' ', 'T')) <= new Date() ? '¿Finalizar esta ocupación?' : '¿Eliminar esta ocupación? Aún no ha empezado; el ambiente quedará libre.'}')">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="id" value="${Number(ocup.id)}">
                                    <input type="hidden" name="sede_id" value="${Number(sedeId)}">
                                    <input type="hidden" name="return" value="calendario">
                                    <input type="hidden" name="mes" value="<?php echo (int)$mes; ?>">
                                    <input type="hidden" name="anio" value="<?php echo (int)$anio; ?>">
                                    ${new Date(ocup.fecha_inicio.replace(' ', 'T')) <= new Date()
                                        ? `<button type="submit" class="btn-finalizar"><i class="bi bi-check-circle-fill"></i> Finalizar</button>`
                                        : `<button type="submit" class="btn-finalizar btn-eliminar-ocup"><i class="bi bi-trash3-fill"></i> Eliminar</button>`}
                                </form>
                            </div>` 
                            : ''}
                    </div>
                `;
            });
            
            html += '</div>';
            
            document.getElementById('contenidoOcupaciones').innerHTML = html;
            document.getElementById('modalDia').style.display = 'flex';
        }

        function cerrarModalDia() {
            document.getElementById('modalDia').style.display = 'none';
        }

        function abrirModalNuevaOcupacion() {
            // ⭐ Validar nuevamente que no sea fecha pasada
            if(esFechaPasada(fechaSeleccionada)) {
                mostrarAviso('No se pueden registrar ocupaciones en fechas pasadas');
                return;
            }

            cerrarModalDia();
            document.getElementById('fechaOcupacion').value = fechaSeleccionada;
            document.getElementById('modalNuevaOcupacion').style.display = 'flex';
        }

        function cerrarModalNuevaOcupacion() {
            document.getElementById('modalNuevaOcupacion').style.display = 'none';
            document.getElementById('formOcupacion').reset();
            // Reset modo múltiple
            document.getElementById('checkMultiple').checked = false;
            document.getElementById('modoSimple').style.display = 'block';
            document.getElementById('modoMultiple').style.display = 'none';
            document.getElementById('fechaOcupacion').setAttribute('required', 'required');
            fechasSeleccionadas = [];
            // Reset horario personalizado
            document.getElementById('horarioPersonalizado').style.display = 'none';
            document.getElementById('horaInicio').removeAttribute('required');
            document.getElementById('horaFin').removeAttribute('required');
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

        // ═══════════════════════════════════════════════════════════════
        // FUNCIONES DE REGISTRO MÚLTIPLE
        // ═══════════════════════════════════════════════════════════════
        
        let fechasSeleccionadas = [];
        const mesActual = <?php echo $mes; ?>;
        const anioActual = <?php echo $anio; ?>;

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

        const FESTIVOS_CO = { includes: sagaEsFestivo };
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
            
            document.getElementById('fechasMultiples').value = fechasSeleccionadas.join(',');
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
                    mostrarAviso('Debes seleccionar al menos un día');
                    return false;
                }
            });
        }

        document.addEventListener('keydown', (e) => {
            if(e.key === 'Escape') {
                cerrarModalDia();
                cerrarModalNuevaOcupacion();
            }
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

        const btnMenu = document.getElementById('btnMenu');
        const modalMenu = document.getElementById('modalMenu');
        btnMenu.addEventListener('click', () => modalMenu.classList.add('active'));
        modalMenu.addEventListener('click', (e) => {
            if(e.target === modalMenu) modalMenu.classList.remove('active');
        });

        let toastTimer = null;
        function mostrarAviso(mensaje) {
            const toast = document.getElementById('toastNotificacion');
            toast.innerHTML = '<i class="bi bi-exclamation-triangle-fill"></i> ' + mensaje;
            toast.style.opacity = '1';
            toast.style.transform = 'translateX(-50%) translateY(0)';
            if(toastTimer) clearTimeout(toastTimer);
            toastTimer = setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateX(-50%) translateY(100px)';
            }, 3500);
        }
    </script>
</body>
</html>
