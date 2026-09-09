<?php
session_start();

// Verificar sesión de admin
if(!isset($_SESSION['id']) || $_SESSION['rol'] != 'admin') {
    header("Location: ../../views/home.php");
    exit();
}

require_once "../../config/conexion.php";
require_once "../../config/csrf.php";
$con = conexion();

// ⭐ VALIDAR SEDE_ID
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

// ⭐ OBTENER INSTRUCTORES FILTRADOS POR SEDE
// Instructores planta: tienen sede_id asignada
// Instructores contratistas: sede_id NULL (acceso a todas)
$query = "SELECT * FROM usuarios 
          WHERE rol = 'instructor' 
          AND (sede_id = $sede_id OR (tipo_contrato = 'contratista' AND sede_id IS NULL))
          ORDER BY nombre ASC";
$instructores = mysqli_query($con, $query);

// ⭐ ESTADÍSTICAS FILTRADAS POR SEDE
$stats_total = mysqli_fetch_assoc(mysqli_query($con,"SELECT COUNT(*) total FROM usuarios WHERE rol='instructor' AND (sede_id = $sede_id OR (tipo_contrato = 'contratista' AND sede_id IS NULL))"))['total'];
$stats_activos = mysqli_fetch_assoc(mysqli_query($con,"SELECT COUNT(*) total FROM usuarios WHERE rol='instructor' AND estado='activo' AND (sede_id = $sede_id OR (tipo_contrato = 'contratista' AND sede_id IS NULL))"))['total'];
$stats_inactivos = mysqli_fetch_assoc(mysqli_query($con,"SELECT COUNT(*) total FROM usuarios WHERE rol='instructor' AND estado='inactivo' AND (sede_id = $sede_id OR (tipo_contrato = 'contratista' AND sede_id IS NULL))"))['total'];

// Contratos próximos a vencer (30 días)
$fecha_limite = date('Y-m-d', strtotime('+30 days'));
$stats_por_vencer = mysqli_fetch_assoc(mysqli_query($con,"SELECT COUNT(*) total FROM usuarios WHERE rol='instructor' AND tipo_contrato='contratista' AND estado='activo' AND fecha_fin_contrato <= '$fecha_limite' AND fecha_fin_contrato >= CURDATE() AND (sede_id = $sede_id OR sede_id IS NULL)"))['total'];
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Instructores - <?php echo $sede_nombre; ?></title>
    <link rel="stylesheet" href="../../assets/css/instructores_admin.css">
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
            <h1>Gestión de Instructores - <?php echo $sede_nombre; ?></h1>
            <p>Administra el personal docente de esta sede</p>
        </div>
        <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
            <a href="carga_masiva_instructores.php?sede_id=<?php echo $sede_id; ?>" class="btn-carga-masiva">
                <i class="bi bi-upload"></i> Carga Masiva
            </a>
            <button class="btn-nuevo" onclick="abrirModal()">
                <i class="bi bi-plus-lg"></i> Nuevo Instructor
            </button>
        </div>
    </div>

    <!-- Mensajes -->
    <?php if(isset($_GET['msg'])): ?>
        <div class="mensaje-alert mensaje-exito" id="mensajeAlert">
            <?php 
            switch($_GET['msg']) {
                case 'deleted':
                    echo '<i class="bi bi-check-circle-fill"></i> Instructor eliminado exitosamente';
                    break;
                case 'created':
                case 'instructor_creado':
                    echo '<i class="bi bi-check-circle-fill"></i> Instructor creado exitosamente';
                    break;
                case 'updated':
                    echo '<i class="bi bi-check-circle-fill"></i> Instructor actualizado exitosamente';
                    break;
                case 'activated':
                    echo '<i class="bi bi-check-circle-fill"></i> Instructor activado exitosamente';
                    break;
                case 'deactivated':
                    echo '<i class="bi bi-check-circle-fill"></i> Instructor desactivado exitosamente';
                    break;
                case 'carga_exitosa':
                    $cr = intval($_GET['creados'] ?? 0);
                    $fa = intval($_GET['fallidos'] ?? 0);
                    echo '<i class="bi bi-check-circle-fill"></i> Carga masiva completada: ' . $cr . ' instructor' . ($cr != 1 ? 'es' : '') . ' registrado' . ($cr != 1 ? 's' : '');
                    if($fa > 0) echo ' &nbsp;|&nbsp; <i class="bi bi-exclamation-triangle-fill"></i> ' . $fa . ' omitidos por datos inválidos o cédula/email duplicados';
                    break;
            }
            ?>
        </div>
    <?php endif; ?>

    <?php if(isset($_GET['error'])): ?>
        <div class="mensaje-alert mensaje-error" id="mensajeAlert">
            <?php 
            switch($_GET['error']) {
                case 'cedula_exists':
                    echo '<i class="bi bi-x-circle-fill"></i> Ya existe un usuario con esa cédula';
                    break;
                case 'email_duplicado':
                    echo '<i class="bi bi-x-circle-fill"></i> Ya existe un usuario con ese correo electrónico';
                    break;
                case 'email_vacio':
                    echo '<i class="bi bi-x-circle-fill"></i> El correo electrónico es obligatorio';
                    break;
                case 'email_invalido':
                    echo '<i class="bi bi-x-circle-fill"></i> El formato del correo electrónico no es válido';
                    break;
                case 'password_required':
                    echo '<i class="bi bi-x-circle-fill"></i> La contraseña es obligatoria al crear un instructor';
                    break;
                case 'create_failed':
                    echo '<i class="bi bi-x-circle-fill"></i> Error al guardar el instructor. Intenta de nuevo';
                    break;
                case 'update_failed':
                    echo '<i class="bi bi-x-circle-fill"></i> Error al actualizar el instructor. Intenta de nuevo';
                    break;
                case 'invalid_id':
                    echo '<i class="bi bi-x-circle-fill"></i> Instructor no válido';
                    break;
                default:
                    echo '<i class="bi bi-x-circle-fill"></i> Error en la operación';
            }
            ?>
        </div>
    <?php endif; ?>

    <!-- Estadísticas -->
    <div class="estadisticas">
        <div class="stat-box">
            <div class="stat-icono"><i class="bi bi-person-workspace"></i></div>
            <div class="stat-info">
                <span class="stat-numero"><?php echo $stats_total; ?></span>
                <span class="stat-label">Total Instructores</span>
            </div>
        </div>

        <div class="stat-box activos">
            <div class="stat-icono"><i class="bi bi-check-circle-fill"></i></div>
            <div class="stat-info">
                <span class="stat-numero"><?php echo $stats_activos; ?></span>
                <span class="stat-label">Activos</span>
            </div>
        </div>

        <div class="stat-box inactivos">
            <div class="stat-icono"><i class="bi bi-slash-circle"></i></div>
            <div class="stat-info">
                <span class="stat-numero"><?php echo $stats_inactivos; ?></span>
                <span class="stat-label">Inactivos</span>
            </div>
        </div>

        <div class="stat-box alerta">
            <div class="stat-icono"><i class="bi bi-exclamation-triangle-fill"></i></div>
            <div class="stat-info">
                <span class="stat-numero"><?php echo $stats_por_vencer; ?></span>
                <span class="stat-label">Por Vencer (30 días)</span>
            </div>
        </div>
    </div>

    <!-- Filtros -->
    <div class="filtros">
        <input type="text" id="buscarInstructor" placeholder=" Buscar instructor..." onkeyup="filtrar()">
        
        <select id="filtrarEstado" onchange="filtrar()">
            <option value="">Todos los estados</option>
            <option value="activo">Activos</option>
            <option value="inactivo">Inactivos</option>
        </select>

        <select id="filtrarContrato" onchange="filtrar()">
            <option value="">Todos los contratos</option>
            <option value="planta">Planta</option>
            <option value="contratista">Contratista</option>
        </select>

        <select id="filtrarEstudio" onchange="filtrar()">
            <option value="">Todos los estudios</option>
            <option value="tecnico">Técnico</option>
            <option value="tecnologo">Tecnólogo</option>
            <option value="profesional">Profesional</option>
            <option value="especializacion">Especialización</option>
            <option value="maestria">Maestría</option>
            <option value="doctorado">Doctorado</option>
        </select>
    </div>

    <!-- Grid de Instructores -->
    <div class="instructores-grid" id="instructoresGrid">
        <?php 
        if(mysqli_num_rows($instructores) > 0) {
            while($instructor = mysqli_fetch_assoc($instructores)) {
                $contrato_clase = $instructor['tipo_contrato'] ?? '';
                $estado_clase = $instructor['estado'];
                $contrato_texto = $instructor['tipo_contrato'] == 'planta' ? 'Planta' : 'Contratista';
                $contrato_icono = $instructor['tipo_contrato'] == 'planta' ? '<i class="bi bi-clipboard-data"></i>' : '<i class="bi bi-file-earmark-text"></i>';
                $nombre_completo = trim($instructor['nombre'] . ' ' . $instructor['apellido']);
                
                // Calcular estado del contrato
                $estado_contrato = '';
                $dias_restantes = 0;
                if($instructor['tipo_contrato'] == 'contratista' && $instructor['fecha_fin_contrato'] && $instructor['estado'] == 'activo') {
                    $fecha_fin = new DateTime($instructor['fecha_fin_contrato']);
                    $hoy = new DateTime();
                    
                    if($fecha_fin < $hoy) {
                        $estado_contrato = 'vencido';
                        $dias_restantes = 0;
                    } else {
                        $diferencia = $hoy->diff($fecha_fin);
                        $dias_restantes = $diferencia->days;
                        if($dias_restantes <= 30) {
                            $estado_contrato = 'proximo-vencer';
                        } else {
                            $estado_contrato = 'vigente';
                        }
                    }
                }
        ?>
            <div class="instructor-card <?php echo $contrato_clase; ?> <?php echo $estado_clase; ?>" 
                 data-nombre="<?php echo strtolower($nombre_completo); ?>"
                 data-cedula="<?php echo $instructor['cedula']; ?>"
                 data-contrato="<?php echo $instructor['tipo_contrato']; ?>"
                 data-estado="<?php echo $instructor['estado']; ?>"
                 data-estudio="<?php echo $instructor['nivel_estudio']; ?>">
                
                <div class="card-header">
                    <div class="badges-container">
                        <div class="contrato-badge <?php echo $contrato_clase; ?>">
                            <?php echo $contrato_icono; ?> <?php echo $contrato_texto; ?>
                        </div>
                        <div class="estado-badge <?php echo $estado_clase; ?>">
                            <?php echo $instructor['estado'] == 'activo' ? '<i class="bi bi-check-circle-fill text-success"></i> Activo' : '<i class="bi bi-dash-circle text-secondary"></i> Inactivo'; ?>
                        </div>
                    </div>
                    <div class="card-acciones">
                        <?php if($instructor['estado'] == 'inactivo'): ?>
                            <button class="btn-accion activate" onclick="activar(<?php echo $instructor['id']; ?>)" title="Activar"><i class="bi bi-unlock-fill"></i></button>
                        <?php else: ?>
                            <button class="btn-accion deactivate" onclick="desactivar(<?php echo $instructor['id']; ?>)" title="Desactivar"><i class="bi bi-lock-fill"></i></button>
                        <?php endif; ?>
                        <button class="btn-accion edit" onclick="editar(<?php echo $instructor['id']; ?>)" title="Editar"><i class="bi bi-pencil"></i></button>
                        <button class="btn-accion delete" onclick="eliminar(<?php echo $instructor['id']; ?>, '<?php echo addslashes($nombre_completo); ?>')" title="Eliminar"><i class="bi bi-trash"></i></button>
                    </div>
                </div>

                <div class="card-body">
                    <div class="instructor-avatar <?php echo $estado_clase; ?>">
                        <div class="avatar-icono"><i class="bi bi-person-workspace"></i></div>
                    </div>
                    <h3 class="instructor-nombre"><?php echo $nombre_completo; ?></h3>
                    <div class="instructor-cedula">CC: <?php echo number_format($instructor['cedula'], 0, '', '.'); ?></div>
                    
                    <div class="instructor-info">
                        <?php if($instructor['nivel_estudio']): ?>
                        <div class="info-item">
                            <span class="info-icono"><i class="bi bi-mortarboard-fill"></i></span>
                            <span class="info-texto"><?php echo ucfirst($instructor['nivel_estudio']); ?></span>
                        </div>
                        <?php endif; ?>
                        
                        <?php if($instructor['tipo_contrato'] == 'contratista' && $instructor['fecha_inicio_contrato'] && $instructor['fecha_fin_contrato']): ?>
                            <div class="info-item">
                                <span class="info-icono"><i class="bi bi-calendar-event"></i></span>
                                <span class="info-texto">
                                    <?php echo date('d/m/Y', strtotime($instructor['fecha_inicio_contrato'])); ?> - 
                                    <?php echo date('d/m/Y', strtotime($instructor['fecha_fin_contrato'])); ?>
                                </span>
                            </div>
                            
                            <?php if($instructor['estado'] == 'activo'): ?>
                                <?php if($estado_contrato == 'vencido'): ?>
                                    <div class="estado-contrato vencido">
                                        <i class="bi bi-x-circle-fill"></i> Contrato Vencido
                                    </div>
                                <?php elseif($estado_contrato == 'proximo-vencer'): ?>
                                    <div class="estado-contrato alerta">
                                        <i class="bi bi-exclamation-triangle-fill"></i> Vence en <?php echo $dias_restantes; ?> días
                                    </div>
                                <?php else: ?>
                                    <div class="estado-contrato vigente">
                                        <i class="bi bi-check-circle-fill text-success"></i> Vigente (<?php echo $dias_restantes; ?> días)
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="estado-contrato inactivo-msg">
                                    <i class="bi bi-dash-circle"></i> Contrato Finalizado
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="info-item">
                                <span class="info-icono"><i class="bi bi-infinity"></i></span>
                                <span class="info-texto">Contrato Indefinido</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php 
            }
        } else {
        ?>
            <div class="estado-vacio">
                <div class="vacio-icono"><i class="bi bi-person-workspace"></i></div>
                <h3 class="vacio-titulo">No hay instructores en esta sede</h3>
                <p class="vacio-texto">Comienza agregando el primer instructor</p>
                <button class="btn-nuevo" onclick="abrirModal()"><i class="bi bi-plus-lg"></i> Crear Primer Instructor</button>
            </div>
        <?php } ?>
    </div>

    <!-- Modal -->
    <div class="modal" id="modalInstructor">
        <div class="modal-contenido">
            <button class="modal-cerrar" type="button" onclick="cerrarModal()"><i class="bi bi-x-lg"></i></button>
            
            <h2 id="modalTitulo">Nuevo Instructor</h2>

            <form action="../../controllers/CrearInstructor.php" method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="accion" id="accion" value="crear">
                <input type="hidden" name="instructor_id" id="instructorId">
                <input type="hidden" name="sede_id" value="<?php echo $sede_id; ?>">

                <!-- Información Personal -->
                <div class="form-seccion">
                    <h3 class="form-seccion-titulo"><i class="bi bi-clipboard"></i> Información Personal</h3>
                    
                    <div class="form-grupo">
                        <label>Nombre completo *</label>
                        <input type="text" name="nombre" id="instructorNombre" placeholder="Ej: Juan Pérez García" required>
                    </div>

                    <div class="form-grupo">
                        <label>Cédula *</label>
                        <input type="number" name="cedula" id="instructorCedula" placeholder="Ej: 1234567890" required>
                    </div>

                    <!-- ⭐ NUEVO CAMPO EMAIL -->
                    <div class="form-grupo">
                        <label>Email *</label>
                        <input type="email" name="email" id="instructorEmail" placeholder="ejemplo@correo.com" required>
                    </div>

                    <div class="form-grupo">
                        <label>Teléfono / Celular</label>
                        <input type="tel" name="telefono" id="instructorTelefono" placeholder="Ej: 3001234567">
                        <small class="form-ayuda">Requerido para notificaciones SMS. Sin prefijo +57.</small>
                    </div>

                    <div class="form-grupo">
                        <label>Nivel de Estudio *</label>
                        <select name="nivel_estudio" id="instructorEstudio" required>
                            <option value="">Seleccione...</option>
                            <option value="tecnico">Técnico</option>
                            <option value="tecnologo">Tecnólogo</option>
                            <option value="profesional">Profesional</option>
                            <option value="especializacion">Especialización</option>
                            <option value="maestria">Maestría</option>
                            <option value="doctorado">Doctorado</option>
                        </select>
                    </div>

                    <div class="form-grupo">
                        <label>Contraseña *</label>
                        <input type="password" name="contrasena" id="instructorContrasena" placeholder="Mínimo 6 caracteres" minlength="6">
                        <small class="form-ayuda" id="contrasenaAyuda">Deja vacío para mantener la contraseña actual</small>
                    </div>
                </div>

                <!-- Información Contractual -->
                <div class="form-seccion">
                    <h3 class="form-seccion-titulo"><i class="bi bi-file-earmark-text"></i> Información Contractual</h3>
                    
                    <div class="form-grupo">
                        <label>Tipo de Contrato *</label>
                        <select name="tipo_contrato" id="instructorContrato" required onchange="cambiarTipoContrato()">
                            <option value="">Seleccione...</option>
                            <option value="planta">Planta (Indefinido)</option>
                            <option value="contratista">Contratista (Temporal)</option>
                        </select>
                    </div>

                    <div id="fechasContrato" style="display: none;">
                        <div class="form-grupo">
                            <label>Fecha de Inicio *</label>
                            <input type="date" name="fecha_inicio_contrato" id="instructorFechaInicio">
                        </div>

                        <div class="form-grupo">
                            <label>Fecha de Fin *</label>
                            <input type="date" name="fecha_fin_contrato" id="instructorFechaFin">
                        </div>
                    </div>

                    <div id="mensajeIndefinido" style="display: none;">
                        <div class="alerta-info">
                            <i class="bi bi-infinity"></i> El contrato de planta es indefinido y no requiere fechas
                        </div>
                    </div>
                </div>

                <div class="form-botones">
                    <button type="button" class="btn-cancelar" onclick="cerrarModal()">Cancelar</button>
                    <button type="submit" class="btn-guardar">
                        <span id="btnTexto"><i class="bi bi-floppy"></i> Guardar Instructor</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const sedeId = <?php echo $sede_id; ?>;
        const csrfToken = <?php echo json_encode(csrf_token()); ?>;
    </script>
    <script src="../../assets/js/instructores_admin.js"></script>
</body>
</html>
