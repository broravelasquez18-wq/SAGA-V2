<?php
session_start();

// Verificar sesión de admin
if(!isset($_SESSION['id']) || $_SESSION['rol'] != 'admin') {
    header("Location: ../../views/home.php");
    exit();
}

require_once "../../config/conexion.php";
require_once "../../config/csrf.php";
require_once "../../config/estados.php";
$con = conexion();
expirar_ocupaciones($con);

// VALIDAR SEDE_ID
$sede_id = isset($_GET['sede_id']) ? intval($_GET['sede_id']) : 0;
if($sede_id <= 0) {
    header("Location: index_sedes.php");
    exit();
}

// Obtener información de la sede
$sede_query = mysqli_query($con, "SELECT nombre, ciudad FROM sedes WHERE id = $sede_id");
if(mysqli_num_rows($sede_query) > 0) {
    $sede_info = mysqli_fetch_assoc($sede_query);
    $sede_nombre = $sede_info['nombre'];
    $sede_ciudad = $sede_info['ciudad'];
} else {
    header("Location: index_sedes.php");
    exit();
}

// ⭐ FIX: Usar CURDATE() de MySQL en lugar de date() de PHP
$query_ocupaciones = "SELECT ho.*, 
                      a.nombre AS ambiente_nombre,
                      p.nombre AS piso_nombre,
                      CONCAT_WS(' ', u.nombre, u.apellido) AS instructor_nombre
                      FROM historial_ocupacion ho
                      LEFT JOIN ambientes a ON ho.ambiente_id = a.id
                      LEFT JOIN pisos p ON a.piso_id = p.id
                      LEFT JOIN usuarios u ON ho.usuario_id = u.id
                      WHERE DATE(ho.fecha_inicio) = CURDATE()
                      AND p.sede_id = $sede_id
                      ORDER BY 
                        CASE ho.estado
                          WHEN 'ocupado' THEN 1
                          WHEN 'proximo_a_desocupar' THEN 2
                          WHEN 'disponible' THEN 3
                          WHEN 'finalizado' THEN 4
                        END,
                        ho.fecha_inicio ASC";

$result_ocupaciones = mysqli_query($con, $query_ocupaciones);

// ESTADÍSTICAS FILTRADAS POR SEDE
$ocupados = mysqli_fetch_assoc(mysqli_query($con,"SELECT COUNT(*) total FROM historial_ocupacion ho LEFT JOIN ambientes a ON ho.ambiente_id = a.id LEFT JOIN pisos p ON a.piso_id = p.id WHERE DATE(ho.fecha_inicio)=CURDATE() AND ho.estado='ocupado' AND p.sede_id=$sede_id"))['total'];
$proximos = mysqli_fetch_assoc(mysqli_query($con,"SELECT COUNT(*) total FROM historial_ocupacion ho LEFT JOIN ambientes a ON ho.ambiente_id = a.id LEFT JOIN pisos p ON a.piso_id = p.id WHERE DATE(ho.fecha_inicio)=CURDATE() AND ho.estado='proximo_a_desocupar' AND p.sede_id=$sede_id"))['total'];
$disponibles = mysqli_fetch_assoc(mysqli_query($con,"SELECT COUNT(*) total FROM historial_ocupacion ho LEFT JOIN ambientes a ON ho.ambiente_id = a.id LEFT JOIN pisos p ON a.piso_id = p.id WHERE DATE(ho.fecha_inicio)=CURDATE() AND ho.estado='disponible' AND p.sede_id=$sede_id"))['total'];
$total_hoy = mysqli_num_rows($result_ocupaciones);

// OBTENER AMBIENTES DISPONIBLES DE ESTA SEDE
$ambientes_query = "SELECT a.id, a.nombre, p.nombre AS piso_nombre
                    FROM ambientes a
                    LEFT JOIN pisos p ON a.piso_id = p.id
                    WHERE p.sede_id = $sede_id
                    ORDER BY p.nombre, a.nombre";
$ambientes_result = mysqli_query($con, $ambientes_query);

// OBTENER INSTRUCTORES ACTIVOS
$instructores_query = "SELECT id, nombre, apellido, cedula, tipo_contrato
                       FROM usuarios
                       WHERE rol = 'instructor' AND estado = 'activo'
                       AND (sede_id = $sede_id OR (tipo_contrato = 'contratista' AND sede_id IS NULL))
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
<title>Ocupaciones - <?php echo $sede_nombre; ?></title>
    <link rel="stylesheet" href="../../assets/css/ocupaciones_admin.css">
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
        <div class="header-info"><?php include __DIR__ . '/../_campana.php'; ?>
            <div class="usuario-info">
                <span class="usuario-nombre"><?php echo $_SESSION['nombre']; ?></span>
                <span class="usuario-tipo">Administrador</span>
            </div>
            <a class="btn-logout" href="../../controllers/logout.php">
                <i class="bi bi-box-arrow-right"></i>
            </a>

        </div>
    </div>

    <!-- Sidebar -->
    <?php $pagina_actual = basename($_SERVER['PHP_SELF']); ?>
        <?php include __DIR__ . '/_sidebar.php'; ?>

    <!-- Encabezado -->
    <div class="encabezado">
        <div class="encabezado-contenido">
            <h1>Ocupaciones - <?php echo $sede_nombre; ?></h1>
            <p>Registra y controla las ocupaciones de ambientes</p>
        </div>
        <div class="encabezado-botones">
            <a href="carga_masiva_ocupaciones.php?sede_id=<?php echo $sede_id; ?>" class="btn-carga-masiva">
                <i class="bi bi-upload"></i> Carga Masiva
            </a>
            <a href="calendario_ocupaciones.php?sede_id=<?php echo $sede_id; ?>" class="btn-calendario">
                <i class="bi bi-calendar-event"></i> Ver Calendario
            </a>
            <button class="btn-nueva-ocupacion" onclick="abrirModal()"><i class="bi bi-plus-lg"></i> Nueva Ocupación</button>
        </div>
    </div>

    <!-- Mensajes -->
    <?php if($msg): ?>
        <div class="mensaje-alert mensaje-exito" id="mensajeAlert">
            <?php
            if($msg == 'ocupacion_registrada') echo '<i class="bi bi-check-circle-fill"></i> Ocupación registrada correctamente';
            if($msg == 'ocupacion_finalizada') echo '<i class="bi bi-check-circle-fill"></i> Ocupación finalizada correctamente';
            if($msg == 'ocupaciones_multiples') {
                $total = isset($_GET['total']) ? intval($_GET['total']) : 0;
                echo '<i class="bi bi-check-circle-fill"></i> ' . $total . ' ocupaciones registradas correctamente';
            }
            ?>
        </div>
    <?php endif; ?>

    <?php if($error): ?>
        <div class="mensaje-alert mensaje-error" id="mensajeAlert">
            <?php
            if($error == 'campos_vacios') echo '<i class="bi bi-exclamation-triangle-fill"></i> Complete todos los campos obligatorios';
            if($error == 'ambiente_ocupado') echo '<i class="bi bi-exclamation-triangle-fill"></i> El ambiente ya está ocupado en ese horario';
            if($error == 'instructor_ocupado') {
                $ubicacion = isset($_GET['ubicacion']) ? htmlspecialchars($_GET['ubicacion']) : 'otra sede';
                echo '<i class="bi bi-person"></i> <i class="bi bi-x-circle-fill"></i> El instructor ya está ocupado en esta fecha/jornada en: ' . $ubicacion;
            }
            if($error == 'registro_fallido') echo '<i class="bi bi-x-circle-fill"></i> Error al registrar la ocupación';
            if($error == 'finalizar_fallido') echo '<i class="bi bi-x-circle-fill"></i> Error al finalizar la ocupación';
            if($error == 'hora_invalida') echo '<i class="bi bi-exclamation-triangle-fill"></i> La hora de fin debe ser mayor que la de inicio';
            if($error == 'horario_fuera') echo '<i class="bi bi-exclamation-triangle-fill"></i> El horario debe estar entre 06:00 y 22:00';
            if($error == 'fecha_pasada') echo '<i class="bi bi-exclamation-triangle-fill"></i> No se pueden registrar ocupaciones en fechas pasadas';
            elseif($error == 'dia_no_habil') echo '<i class="bi bi-calendar-x-fill"></i> No se pueden registrar ocupaciones en domingos ni días festivos';
            if($error == 'hora_pasada') echo '<i class="bi bi-exclamation-triangle-fill"></i> No se pueden registrar ocupaciones con horas que ya pasaron';
            if($error == 'jornada_pasada') echo '<i class="bi bi-exclamation-triangle-fill"></i> La jornada seleccionada ya pasó. Selecciona otra jornada u otra fecha';
            ?>
        </div>
    <?php endif; ?>

    <!-- Estadísticas -->
    <div class="estadisticas">
        <div class="stat-box">
            <div class="stat-icono"><i class="bi bi-clipboard-data"></i></div>
            <div class="stat-info">
                <span class="stat-numero"><?php echo $total_hoy; ?></span>
                <span class="stat-label">Total Hoy</span>
            </div>
        </div>

        <div class="stat-box ocupado">
            <div class="stat-icono"><i class="bi bi-circle-fill text-danger" style="font-size:.7rem"></i></div>
            <div class="stat-info">
                <span class="stat-numero"><?php echo $ocupados; ?></span>
                <span class="stat-label">Ocupados</span>
            </div>
        </div>

        <div class="stat-box proximo">
            <div class="stat-icono"><i class="bi bi-circle-fill text-warning" style="font-size:.7rem"></i></div>
            <div class="stat-info">
                <span class="stat-numero"><?php echo $proximos; ?></span>
                <span class="stat-label">Próximos a Desocupar</span>
            </div>
        </div>

        <div class="stat-box disponible">
            <div class="stat-icono"><i class="bi bi-circle-fill text-success" style="font-size:.7rem"></i></div>
            <div class="stat-info">
                <span class="stat-numero"><?php echo $disponibles; ?></span>
                <span class="stat-label">Disponibles</span>
            </div>
        </div>
    </div>

    <!-- Lista de Ocupaciones de Hoy -->
    <div class="ocupaciones-contenedor">
        <h2 class="seccion-titulo">Ocupaciones de Hoy - <?php echo date('d/m/Y'); ?></h2>
        
        <div class="ocupaciones-grid">
            <?php 
            if($result_ocupaciones && mysqli_num_rows($result_ocupaciones) > 0):
                mysqli_data_seek($result_ocupaciones, 0);
                while($ocup = mysqli_fetch_assoc($result_ocupaciones)):
                    $clase_estado = '';
                    $texto_estado = '';
                    $icono_estado = '';
                    
                    switch($ocup['estado']) {
                        case 'ocupado':
                            $clase_estado = 'ocupado';
                            $texto_estado = 'OCUPADO';
                            $icono_estado = '<i class="bi bi-circle-fill text-danger" style="font-size:.7rem"></i>';
                            break;
                        case 'proximo_a_desocupar':
                            $clase_estado = 'proximo';
                            $texto_estado = 'PRÓXIMO A DESOCUPAR';
                            $icono_estado = '<i class="bi bi-circle-fill text-warning" style="font-size:.7rem"></i>';
                            break;
                        case 'disponible':
                            $clase_estado = 'disponible';
                            $texto_estado = 'DISPONIBLE';
                            $icono_estado = '<i class="bi bi-circle-fill text-success" style="font-size:.7rem"></i>';
                            break;
                        case 'finalizado':
                            $clase_estado = 'finalizado';
                            $texto_estado = 'FINALIZADO';
                            $icono_estado = '<i class="bi bi-check-circle-fill"></i>';
                            break;
                    }
                    
                    $icono_jornada = '<i class="bi bi-calendar-event"></i>';
                    $texto_jornada = ucfirst($ocup['jornada']);
                    
                    if($ocup['jornada'] == 'mañana') {
                        $icono_jornada = '<i class="bi bi-sunrise"></i>';
                    } elseif($ocup['jornada'] == 'tarde') {
                        $icono_jornada = '<i class="bi bi-sun"></i>';
                    } elseif($ocup['jornada'] == 'noche') {
                        $icono_jornada = '<i class="bi bi-moon-stars"></i>';
                    } elseif($ocup['jornada'] == 'personalizado') {
                        $icono_jornada = '<i class="bi bi-alarm"></i>';
                        $texto_jornada = 'Personalizado';
                    }
                    
                    $ambiente_completo = $ocup['piso_nombre'] . ' - ' . $ocup['ambiente_nombre'];
            ?>
                <div class="ocupacion-card <?php echo $clase_estado; ?>" data-ocupacion-id="<?php echo $ocup['id']; ?>">
                    <div class="ocupacion-header">
                        <span class="ocupacion-estado"><?php echo $icono_estado; ?> <?php echo $texto_estado; ?></span>
                        <span class="ocupacion-jornada"><?php echo $icono_jornada; ?> <?php echo $texto_jornada; ?></span>
                    </div>
                    
                    <div class="ocupacion-body">
                        <h3 class="ocupacion-ambiente"><?php echo $ambiente_completo; ?></h3>
                        <p class="ocupacion-instructor"><i class="bi bi-person"></i> <?php echo $ocup['instructor_nombre']; ?></p>
                        <p class="ocupacion-horario">
                            <i class="bi bi-clock"></i> <?php echo date('H:i', strtotime($ocup['fecha_inicio'])); ?> -
                            <?php echo date('H:i', strtotime($ocup['fecha_fin'])); ?>
                        </p>
                        <?php if($ocup['observaciones']): ?>
                            <p class="ocupacion-obs"><i class="bi bi-card-text"></i> <?php echo htmlspecialchars($ocup['observaciones']); ?></p>
                        <?php endif; ?>
                    </div>
                    
                    <?php if($ocup['estado'] != 'finalizado'): ?>
                        <div class="ocupacion-footer">
                            <a href="../../controllers/FinalizarOcupacion.php?id=<?php echo $ocup['id']; ?>&sede_id=<?php echo $sede_id; ?>" 
                               class="btn-finalizar"
                               onclick="return confirm('¿Finalizar esta ocupación?')">
                                <i class="bi bi-check-circle-fill"></i> Finalizar
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php 
                endwhile;
            else:
            ?>
                <div class="estado-vacio">
                    <div class="vacio-icono"><i class="bi bi-calendar-event"></i></div>
                    <h3>No hay ocupaciones registradas hoy en esta sede</h3>
                    <p>Haz clic en "Nueva Ocupación" para registrar una</p>
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
                <?php echo csrf_field(); ?>
                <input type="hidden" name="sede_id" value="<?php echo $sede_id; ?>">
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
                        <input type="date" name="fecha" id="fechaSimple" value="<?php echo date('Y-m-d'); ?>" required>
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
                            <option value="<?php echo $inst['id']; ?>">
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

                <!-- CAMPOS DE HORA -->
                <div id="horarioPersonalizado" style="display: none;">
                    <div class="form-row">
                        <div class="form-grupo">
                            <label>Hora Inicio *</label>
                            <input type="time" name="hora_inicio" id="horaInicio" min="06:00" max="22:00">
                            <small>Rango: 06:00 - 22:00</small>
                        </div>

                        <div class="form-grupo">
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
        const sedeId = <?php echo $sede_id; ?>;

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

        function abrirModal() {
            document.getElementById('modalOcupacion').style.display = 'flex';
        }

        function cerrarModal() {
            document.getElementById('modalOcupacion').style.display = 'none';
            document.getElementById('formOcupacion').reset();
            document.getElementById('horarioPersonalizado').style.display = 'none';
            document.getElementById('horaInicio').removeAttribute('required');
            document.getElementById('horaFin').removeAttribute('required');
            // Reset modo múltiple
            document.getElementById('checkMultiple').checked = false;
            document.getElementById('modoSimple').style.display = 'block';
            document.getElementById('modoMultiple').style.display = 'none';
            document.getElementById('fechaSimple').setAttribute('required', 'required');
            fechasSeleccionadas = [];
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
            if(e.key === 'Escape') cerrarModal();
        });

        setTimeout(() => {
            const alert = document.getElementById('mensajeAlert');
            if(alert) alert.style.display = 'none';
        }, 5000);

        const btnMenu = document.getElementById('btnMenu');
        const modalMenu = document.getElementById('modalMenu');
        if(btnMenu && modalMenu) {
            btnMenu.addEventListener('click', () => modalMenu.classList.add('active'));
            modalMenu.addEventListener('click', (e) => {
                if(e.target === modalMenu) modalMenu.classList.remove('active');
            });
        }

        // Resaltar y scroll a la ocupación indicada desde ambientes
        const params = new URLSearchParams(window.location.search);
        const resaltarId = params.get('resaltar');
        if(resaltarId) {
            const card = document.querySelector(`.ocupacion-card[data-ocupacion-id="${resaltarId}"]`);
            if(card) {
                card.classList.add('resaltada');
                setTimeout(() => card.scrollIntoView({ behavior:'smooth', block:'center' }), 300);
            }
        }
    </script>
</body>
</html>
