<?php
session_start();

// Verificar sesión de celador
if(!isset($_SESSION['id']) || $_SESSION['rol'] != 'celador') {
    header("Location: ../../views/home.php");
    exit();
}

require_once "../../config/conexion.php";
require_once "../../config/csrf.php";
require_once "../../config/llegada.php";
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
    header("Location: ../../controllers/logout.php");
    exit();
}

alertar_no_llegadas($con, intval($celador_sede_id));

$hoy = date('Y-m-d');

// ⭐ OCUPACIONES DEL DÍA DE LA SEDE
$query_ocupaciones = "SELECT ho.*, u.rol AS usuario_rol, u.cedula AS instructor_cedula, a.nombre AS ambiente_nombre, p.nombre AS piso_nombre, CONCAT_WS(' ', u.nombre, u.apellido) AS instructor_nombre
                      FROM historial_ocupacion ho
                      LEFT JOIN ambientes a ON ho.ambiente_id = a.id
                      LEFT JOIN pisos p ON a.piso_id = p.id
                      LEFT JOIN usuarios u ON ho.usuario_id = u.id
                      WHERE p.sede_id = $celador_sede_id
                      AND DATE(ho.fecha_inicio) = '$hoy'
                      AND ho.estado <> 'cancelado'
                      ORDER BY CASE ho.estado WHEN 'ocupado' THEN 1 WHEN 'proximo_a_desocupar' THEN 2 WHEN 'disponible' THEN 3 WHEN 'finalizado' THEN 4 END, ho.fecha_inicio ASC";
$result_ocupaciones = mysqli_query($con, $query_ocupaciones);

$total_hoy = mysqli_num_rows($result_ocupaciones);
$ocupados = mysqli_fetch_assoc(mysqli_query($con,"SELECT COUNT(*) total FROM historial_ocupacion ho LEFT JOIN ambientes a ON ho.ambiente_id = a.id LEFT JOIN pisos p ON a.piso_id = p.id WHERE DATE(ho.fecha_inicio)='$hoy' AND ho.estado='ocupado' AND p.sede_id = $celador_sede_id"))['total'];
$proximos = mysqli_fetch_assoc(mysqli_query($con,"SELECT COUNT(*) total FROM historial_ocupacion ho LEFT JOIN ambientes a ON ho.ambiente_id = a.id LEFT JOIN pisos p ON a.piso_id = p.id WHERE DATE(ho.fecha_inicio)='$hoy' AND ho.estado='proximo_a_desocupar' AND p.sede_id = $celador_sede_id"))['total'];
$finalizados = mysqli_fetch_assoc(mysqli_query($con,"SELECT COUNT(*) total FROM historial_ocupacion ho LEFT JOIN ambientes a ON ho.ambiente_id = a.id LEFT JOIN pisos p ON a.piso_id = p.id WHERE DATE(ho.fecha_inicio)='$hoy' AND ho.estado='finalizado' AND p.sede_id = $celador_sede_id"))['total'];

// ⭐ AMBIENTES DE LA SEDE
$ambientes_result = mysqli_query($con, "SELECT a.id, a.nombre, p.nombre AS piso_nombre FROM ambientes a LEFT JOIN pisos p ON a.piso_id = p.id WHERE p.sede_id = $celador_sede_id ORDER BY p.nombre, a.nombre");

$instructores_result = mysqli_query($con, "SELECT id, nombre, apellido, cedula FROM usuarios WHERE rol = 'instructor' AND estado = 'activo' AND (sede_id = $celador_sede_id OR tipo_contrato = 'contratista') ORDER BY nombre, apellido");

$msg = $_GET['msg'] ?? '';
$error = $_GET['error'] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SAGA - Ocupaciones Celador</title>
    <link rel="stylesheet" href="../../assets/css/ocupaciones_celador.css?v=20261013">
    <link rel="stylesheet" href="../../assets/css/llegada.css?v=20261013-2">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="../../assets/js/calendario_habil.js"></script>
    <link rel="stylesheet" href="../../assets/css/escaner_carnet.css?v=20261010-5">
    <script src="../../assets/js/escaner_carnet.js?v=20261010-5" defer></script>
    <script src="../../assets/js/buscador_instructor.js?v=20261013" defer></script>
    <script src="../../assets/js/llegada_modal.js?v=20261013-2" defer></script>
    <script src="../../assets/js/llegada_instructor.js?v=20261014" defer></script>
</head>
<body>
    <div class="header">
        <div class="logo">
            <button class="btn-menu" id="btnMenu"><i class="bi bi-list"></i></button>
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
            <a href="dashboard_celador.php"><i class="bi bi-bar-chart-fill"></i>Dashboard</a>
            <a href="ocupaciones_celador.php" class="active"><i class="bi bi-calendar-check"></i>Ocupaciones</a>
            <a href="calendario_celador.php"><i class="bi bi-calendar3"></i>Calendario</a>
            <a href="historial_celador.php"><i class="bi bi-clock-history"></i>Historial</a>
        </div>
    </div>

    <div class="encabezado">
        <div class="encabezado-contenido">
            <h1><i class="bi bi-calendar-event"></i> Ocupaciones - <?php echo $sede_nombre; ?></h1>
            <p>Registra y controla las ocupaciones de hoy</p>
        </div>
        <div class="encabezado-botones">
            <a href="calendario_celador.php" class="btn-calendario">
                <i class="bi bi-calendar-event"></i> Ver Calendario
            </a>
            <button class="btn-nueva-ocupacion" onclick="abrirModal()"><i class="bi bi-plus-lg"></i> Nueva Ocupación</button>
        </div>
    </div>

    <?php if($msg): ?>
        <div class="mensaje-alert mensaje-exito">
            <?php 
            if($msg == 'ocupacion_registrada') echo '<i class="bi bi-check-circle-fill"></i> Ocupación registrada correctamente';
            if($msg == 'ocupacion_finalizada') echo '<i class="bi bi-check-circle-fill"></i> Ocupación finalizada correctamente';
            elseif($msg == 'ocupacion_cancelada') echo '<i class="bi bi-trash3-fill"></i> Ocupación eliminada: el ambiente quedó libre';
            elseif($msg == 'llegada_ok') echo '<i class="bi bi-person-check-fill"></i> Llegada del instructor registrada';
            elseif($msg == 'llegada_tarde') echo '<i class="bi bi-clock-history"></i> Llegada registrada: el instructor llegó tarde';
            if($msg == 'ocupaciones_multiples') {
                $total = isset($_GET['total']) ? intval($_GET['total']) : 0;
                echo '<i class="bi bi-check-circle-fill"></i> ' . $total . ' ocupaciones registradas correctamente';
            }
            ?>
        </div>
    <?php endif; ?>

    <?php if($error): ?>
        <div class="mensaje-alert mensaje-error">
            <?php 
            if($error == 'campos_vacios') echo '<i class="bi bi-exclamation-triangle-fill"></i> Complete todos los campos obligatorios';
            if($error == 'ambiente_ocupado') echo '<i class="bi bi-exclamation-triangle-fill"></i> El ambiente ya está ocupado en esa jornada';
            if($error == 'registro_fallido') echo '<i class="bi bi-x-circle-fill"></i> Error al registrar la ocupación';
            if($error == 'fecha_pasada') echo '<i class="bi bi-exclamation-triangle-fill"></i> No se pueden registrar ocupaciones en fechas pasadas';
            elseif($error == 'dia_no_habil') echo '<i class="bi bi-calendar-x-fill"></i> No se pueden registrar ocupaciones en domingos ni días festivos';
            if($error == 'hora_pasada') echo '<i class="bi bi-exclamation-triangle-fill"></i> No se pueden registrar ocupaciones con horas que ya pasaron';
            if($error == 'jornada_pasada') echo '<i class="bi bi-exclamation-triangle-fill"></i> La jornada seleccionada ya pasó. Selecciona otra jornada u otra fecha';
            $err_llegada = ['llegada_ya_registrada' => 'La llegada de ese instructor ya estaba registrada.', 'llegada_fuera_horario' => 'La llegada solo se puede registrar desde 1 hora antes y hasta el final de la clase.', 'llegada_invalida' => 'No se pudo registrar la llegada.', 'llegada_carnet_no_coincide' => 'El carnet escaneado no corresponde al instructor de esa clase. No se registró la llegada.'];
            if(isset($err_llegada[$error])) echo '<i class="bi bi-person-x-fill"></i> ' . $err_llegada[$error];
            ?>
        </div>
    <?php endif; ?>

    <div class="estadisticas">
        <div class="stat-box"><div class="stat-icono"><i class="bi bi-clipboard-data"></i></div><div class="stat-info"><span class="stat-numero"><?php echo $total_hoy; ?></span><span class="stat-label">Total Hoy</span></div></div>
        <div class="stat-box ocupado"><div class="stat-icono"><i class="bi bi-circle-fill text-danger" style="font-size:.7rem"></i></div><div class="stat-info"><span class="stat-numero"><?php echo $ocupados; ?></span><span class="stat-label">Ocupados</span></div></div>
        <div class="stat-box proximo"><div class="stat-icono"><i class="bi bi-circle-fill text-warning" style="font-size:.7rem"></i></div><div class="stat-info"><span class="stat-numero"><?php echo $proximos; ?></span><span class="stat-label">Próximos a Desocupar</span></div></div>
        <div class="stat-box disponible"><div class="stat-icono"><i class="bi bi-check-circle-fill"></i></div><div class="stat-info"><span class="stat-numero"><?php echo $finalizados; ?></span><span class="stat-label">Finalizados</span></div></div>
    </div>

    <div class="ocupaciones-contenedor">
        <h2 class="seccion-titulo">Ocupaciones de Hoy - <?php echo date('d/m/Y'); ?></h2>
        <div class="ocupaciones-grid">
            <?php if($result_ocupaciones && mysqli_num_rows($result_ocupaciones) > 0):
                mysqli_data_seek($result_ocupaciones, 0);
                while($ocup = mysqli_fetch_assoc($result_ocupaciones)):
                    $clase = $texto = $icono = '';
                    switch($ocup['estado']) {
                        case 'ocupado': $clase = 'ocupado'; $texto = 'OCUPADO'; $icono = '<i class="bi bi-circle-fill text-danger" style="font-size:.7rem"></i>'; break;
                        case 'proximo_a_desocupar': $clase = 'proximo'; $texto = 'PRÓXIMO A DESOCUPAR'; $icono = '<i class="bi bi-circle-fill text-warning" style="font-size:.7rem"></i>'; break;
                        case 'disponible': $clase = 'disponible'; $texto = 'YA PASÓ ESTA JORNADA'; $icono = '<i class="bi bi-clock-history" style="font-size:.85rem"></i>'; break;
                        case 'finalizado': $clase = 'finalizado'; $texto = 'FINALIZADO'; $icono = '<i class="bi bi-check-circle-fill"></i>'; break;
                    }
                    $icono_jornada = ($ocup['jornada'] == 'mañana') ? '<i class="bi bi-sunrise"></i>' : (($ocup['jornada'] == 'tarde') ? '<i class="bi bi-sun"></i>' : '<i class="bi bi-moon-stars"></i>');
            ?>
                <div class="ocupacion-card <?php echo $clase; ?>">
                    <div class="ocupacion-header">
                        <span class="ocupacion-estado"><?php echo $icono; ?> <?php echo $texto; ?></span>
                        <span class="ocupacion-jornada"><?php echo $icono_jornada; ?> <?php echo ucfirst($ocup['jornada']); ?></span>
                    </div>
                    <div class="ocupacion-body">
                        <h3 class="ocupacion-ambiente"><?php echo $ocup['piso_nombre'] . ' - ' . $ocup['ambiente_nombre']; ?></h3>
                        <p class="ocupacion-instructor"><i class="bi bi-person"></i> <?php echo $ocup['instructor_nombre']; ?></p>
                        <p class="ocupacion-horario"><i class="bi bi-clock"></i> <?php echo date('H:i', strtotime($ocup['fecha_inicio'])); ?> - <?php echo date('H:i', strtotime($ocup['fecha_fin'])); ?></p>
                        <?php if($ocup['observaciones']): ?>
                            <p class="ocupacion-obs"><i class="bi bi-card-text"></i> <?php echo htmlspecialchars($ocup['observaciones']); ?></p>
                        <?php endif; ?>
                        <?php echo llegada_badge($ocup); ?>
                    </div>
                    <?php if(in_array($ocup['estado'], ['ocupado','proximo_a_desocupar'], true)):
                        $ya_inicio = strtotime($ocup['fecha_inicio']) <= time(); ?>
                        <div class="ocupacion-footer">
                            <?php if(llegada_registrable($ocup)): ?>
                            <form method="post" action="../../controllers/RegistrarLlegada.php" class="form-llegada" data-cedula="<?php echo htmlspecialchars(trim((string)($ocup['instructor_cedula'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>" data-instructor="<?php echo htmlspecialchars($ocup['instructor_nombre'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" data-horario="<?php echo date('H:i', strtotime($ocup['fecha_inicio'])) . ' - ' . date('H:i', strtotime($ocup['fecha_fin'])); ?>">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="id" value="<?php echo (int)$ocup['id']; ?>">
                                <input type="hidden" name="origen" value="ocupaciones">
                                <button type="submit" class="btn-llegada"><i class="bi bi-person-check-fill"></i> Registrar llegada</button>
                            </form>
                            <?php endif; ?>
                            <form method="post" action="../../controllers/FinalizarOcupacion.php"
                                  onsubmit="return confirm('<?php echo $ya_inicio ? '¿Finalizar esta ocupación?' : '¿Eliminar esta ocupación? Aún no ha empezado; el ambiente quedará libre.'; ?>')">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="id" value="<?php echo (int)$ocup['id']; ?>">
                                <input type="hidden" name="sede_id" value="<?php echo (int)$celador_sede_id; ?>">
                                <?php if($ya_inicio): ?>
                                    <button type="submit" class="btn-finalizar"><i class="bi bi-check-circle-fill"></i> Finalizar</button>
                                <?php else: ?>
                                    <button type="submit" class="btn-finalizar btn-eliminar-ocup"><i class="bi bi-trash3-fill"></i> Eliminar</button>
                                <?php endif; ?>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endwhile; else: ?>
                <div class="estado-vacio">
                    <div class="vacio-icono"><i class="bi bi-calendar-event"></i></div>
                    <h3>No hay ocupaciones registradas hoy</h3>
                    <p>Haz clic en "Nueva Ocupación" para registrar la primera</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- MODAL NUEVA OCUPACIÓN CON REGISTRO MÚLTIPLE -->
    <div class="modal" id="modalOcupacion">
        <div class="modal-contenido">
            <button class="modal-cerrar" onclick="cerrarModal()"><i class="bi bi-x-lg"></i></button>
            <h2><i class="bi bi-plus-lg"></i> Nueva Ocupación</h2>
            <form action="../../controllers/RegistrarOcupacion.php" method="POST" id="formOcupacion">
                <?php include __DIR__ . '/_escaner_carnet.php'; ?>
                <fieldset id="datosOcupacion" hidden disabled>
                    <div class="carnet-resumen"><p id="resumenCarnet" role="status"></p><button type="button" class="carnet-boton" id="volverEscaneo">Volver a escanear</button></div>
                <?php echo csrf_field(); ?>
                <input type="hidden" name="sede_id" value="<?php echo $celador_sede_id; ?>">
                <input type="hidden" name="return" value="ocupaciones">
                
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
                        <input type="date" name="fecha" id="fechaSimple" value="<?php echo date('Y-m-d'); ?>" required onchange="validarJornada()">
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
                        <?php mysqli_data_seek($ambientes_result, 0); while($amb = mysqli_fetch_assoc($ambientes_result)): ?>
                            <option value="<?php echo $amb['id']; ?>"><?php echo $amb['piso_nombre'] . ' - ' . $amb['nombre']; ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-grupo">
                    <label>Instructor *</label>
                    <select name="usuario_id" required>
                        <option value="">Seleccionar instructor...</option>
                        <?php mysqli_data_seek($instructores_result, 0); while($inst = mysqli_fetch_assoc($instructores_result)): ?>
                            <option value="<?php echo (int)$inst['id']; ?>" data-cedula="<?php echo htmlspecialchars(trim($inst['cedula']), ENT_QUOTES, 'UTF-8'); ?>"><?php echo $inst['nombre'] . ' ' . $inst['apellido'] . ' - ' . $inst['cedula']; ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-grupo">
                    <label>Jornada *</label>
                    <select name="jornada" id="jornadaSelect" required onchange="toggleHorarioPersonalizado(); validarJornada();">
                        <option value="">Seleccionar jornada...</option>
                        <option value="mañana"> Mañana (06:00 - 12:00)</option>
                        <option value="tarde"> Tarde (12:00 - 18:00)</option>
                        <option value="noche"> Noche (18:00 - 22:00)</option>
                        <option value="otro">Otro horario (personalizado)</option>
                    </select>
                    <div id="avisoJornada" style="display:none; margin-top:8px; padding:10px 14px; background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; border-radius:8px; font-size:13px; font-weight:600;">
                        <i class="bi bi-slash-circle"></i> No se puede registrar esta ocupación porque la jornada ya pasó.
                    </div>
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
                    <button type="button" class="btn-cancelar" onclick="cerrarModal()">Cancelar</button>
                    <button type="submit" class="btn-guardar" id="btnGuardar">Registrar Ocupación</button>
                </div>
                            </fieldset>
            </form>
        </div>
    </div>

    <script>
        function abrirModal() {
            document.getElementById('modalOcupacion').style.display = 'flex';
        }

        function cerrarModal() {
            document.getElementById('modalOcupacion').style.display = 'none';
            document.getElementById('avisoJornada').style.display = 'none';
            document.getElementById('btnGuardar').disabled = false;
            document.getElementById('btnGuardar').style.opacity = '1';
            document.getElementById('formOcupacion').reset();
            // Reset modo múltiple
            document.getElementById('checkMultiple').checked = false;
            document.getElementById('modoSimple').style.display = 'block';
            document.getElementById('modoMultiple').style.display = 'none';
            document.getElementById('fechaSimple').setAttribute('required', 'required');
            fechasSeleccionadas = [];
            // Reset horario personalizado
            document.getElementById('horarioPersonalizado').style.display = 'none';
            document.getElementById('horaInicio').removeAttribute('required');
            document.getElementById('horaFin').removeAttribute('required');
        }

        function validarJornada() {
            const fecha   = document.getElementById('fechaSimple').value;
            const jornada = document.getElementById('jornadaSelect').value;
            const aviso   = document.getElementById('avisoJornada');
            const btnGuardar = document.getElementById('btnGuardar');

            const hoy = new Date();
            const horaActual = hoy.getHours() * 60 + hoy.getMinutes();
            const fechaHoy = hoy.toISOString().split('T')[0];

            const finesPorJornada = { 'mañana': 12*60, 'tarde': 18*60, 'noche': 22*60 };

            let jornadaPasada = false;
            if(fecha === fechaHoy && jornada && finesPorJornada[jornada] !== undefined) {
                jornadaPasada = horaActual >= finesPorJornada[jornada];
            }

            aviso.style.display   = jornadaPasada ? 'block' : 'none';
            btnGuardar.disabled   = jornadaPasada;
            btnGuardar.style.opacity = jornadaPasada ? '0.5' : '1';
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
        const mesActual = new Date().getMonth() + 1;
        const anioActual = new Date().getFullYear();

        function toggleModoMultiple() {
            const checkMultiple = document.getElementById('checkMultiple');
            const modoSimple = document.getElementById('modoSimple');
            const modoMultiple = document.getElementById('modoMultiple');
            const fechaSimple = document.getElementById('fechaSimple');
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
                        <div class="dia-selector"
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
                    alert('Debes seleccionar al menos un día.');
                    return false;
                }
            });
        }

        document.addEventListener('keydown', (e) => {
            if(e.key === 'Escape') {
                cerrarModal();
            }
        });

        const btnMenu = document.getElementById('btnMenu');
        const modalMenu = document.getElementById('modalMenu');
        if(btnMenu && modalMenu) {
            btnMenu.addEventListener('click', () => modalMenu.classList.add('active'));
            modalMenu.addEventListener('click', (e) => {
                if(e.target === modalMenu) modalMenu.classList.remove('active');
            });
        }

        setInterval(() => {
            location.reload();
        }, 120000);
    </script>
</body>
</html>
