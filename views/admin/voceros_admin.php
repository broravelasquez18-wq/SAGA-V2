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

// OBTENER VOCEROS FILTRADOS POR SEDE
$query = "SELECT u.*, f.id AS ficha_id, f.codigo_ficha, f.programa AS ficha_programa
          FROM usuarios u
          LEFT JOIN fichas f ON f.vocero_id = u.id
          WHERE u.rol = 'vocero' AND u.sede_id = $sede_id
          ORDER BY u.nombre ASC";
$voceros = mysqli_query($con, $query);

// FICHAS DISPONIBLES DE LA SEDE PARA EL DESPLEGABLE DEL VOCERO
$fichas_sede = [];
$fichas_q = mysqli_query($con, "SELECT id, codigo_ficha, programa, vocero_id
                                FROM fichas
                                WHERE sede_id = $sede_id
                                ORDER BY codigo_ficha ASC");
if($fichas_q) {
    while($fila = mysqli_fetch_assoc($fichas_q)) {
        $fichas_sede[] = $fila;
    }
}

// ESTADÍSTICAS FILTRADAS POR SEDE
$stats_total = mysqli_fetch_assoc(mysqli_query($con,"SELECT COUNT(*) total FROM usuarios WHERE rol='vocero' AND sede_id = $sede_id"))['total'];
$stats_activos = mysqli_fetch_assoc(mysqli_query($con,"SELECT COUNT(*) total FROM usuarios WHERE rol='vocero' AND estado='activo' AND sede_id = $sede_id"))['total'];
$stats_inactivos = mysqli_fetch_assoc(mysqli_query($con,"SELECT COUNT(*) total FROM usuarios WHERE rol='vocero' AND estado='inactivo' AND sede_id = $sede_id"))['total'];
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Voceros - <?php echo $sede_nombre; ?></title>
    <link rel="stylesheet" href="../../assets/css/voceros_admin.css?v=20261013">
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
            <h1>Gestión de Voceros - <?php echo $sede_nombre; ?></h1>
            <p>Administra los voceros de esta sede</p>
        </div>
        <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
            <button class="btn-nuevo" onclick="abrirModal()">
                <i class="bi bi-plus-lg"></i> Nuevo Vocero
            </button>
        </div>
    </div>

    <!-- Mensajes -->
    <?php if(isset($_GET['msg'])): ?>
        <div class="mensaje-alert mensaje-exito" id="mensajeAlert">
            <?php
            switch($_GET['msg']) {
                case 'deleted':
                    echo '<i class="bi bi-check-circle-fill text-success"></i> Vocero eliminado exitosamente';
                    break;
                case 'vocero_creado':
                    echo '<i class="bi bi-check-circle-fill text-success"></i> Vocero creado exitosamente';
                    break;
                case 'updated':
                    echo '<i class="bi bi-check-circle-fill text-success"></i> Vocero actualizado exitosamente';
                    break;
                case 'activated':
                    echo '<i class="bi bi-check-circle-fill text-success"></i> Vocero activado exitosamente';
                    break;
                case 'deactivated':
                    echo '<i class="bi bi-check-circle-fill text-success"></i> Vocero desactivado exitosamente';
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
                    echo '<i class="bi bi-x-circle-fill"></i> Ya existe un usuario con ese email';
                    break;
                case 'email_invalido':
                    echo '<i class="bi bi-x-circle-fill"></i> El formato del email no es válido';
                    break;
                case 'email_vacio':
                    echo '<i class="bi bi-x-circle-fill"></i> El email es obligatorio';
                    break;
                case 'password_required':
                    echo '<i class="bi bi-x-circle-fill"></i> La contraseña es obligatoria al crear un vocero';
                    break;
                case 'create_failed':
                    echo '<i class="bi bi-x-circle-fill"></i> Error al crear el vocero. Intente nuevamente';
                    break;
                case 'update_failed':
                    echo '<i class="bi bi-x-circle-fill"></i> Error al actualizar el vocero. Intente nuevamente';
                    break;
                case 'ficha_invalida':
                    echo '<i class="bi bi-x-circle-fill"></i> La ficha seleccionada no pertenece a esta sede o no existe';
                    break;
                case 'ficha_ocupada':
                    echo '<i class="bi bi-x-circle-fill"></i> La ficha seleccionada ya tiene otro vocero asignado';
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
            <div class="stat-icono"><i class="bi bi-megaphone-fill"></i></div>
            <div class="stat-info">
                <span class="stat-numero"><?php echo $stats_total; ?></span>
                <span class="stat-label">Total Voceros</span>
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

    </div>

    <!-- Filtros -->
    <div class="filtros">
        <input type="text" id="buscarCelador" placeholder=" Buscar vocero..." onkeyup="filtrar()">

        <select id="filtrarEstado" onchange="filtrar()">
            <option value="">Todos los estados</option>
            <option value="activo">Activos</option>
            <option value="inactivo">Inactivos</option>
        </select>

        <select id="ordenar" onchange="ordenar()">
            <option value="">Ordenar por...</option>
            <option value="nombre">Nombre (A-Z)</option>
            <option value="cedula">Cédula</option>
            <option value="estado">Estado</option>
        </select>
    </div>

    <!-- Grid de Voceros -->
    <div class="celadores-grid" id="verocerosGrid">
        <?php
        if(mysqli_num_rows($voceros) > 0) {
            while($vocero = mysqli_fetch_assoc($voceros)) {
                $estado_clase = $vocero['estado'];
                $nombre_completo = $vocero['nombre'] . ' ' . $vocero['apellido'];
        ?>
            <div class="celador-card <?php echo $estado_clase; ?>"
                 data-nombre="<?php echo strtolower($nombre_completo); ?>"
                 data-cedula="<?php echo $vocero['cedula']; ?>"
                 data-estado="<?php echo $vocero['estado']; ?>">

                <div class="card-header">
                    <div class="badges-container">
                        <div class="estado-badge <?php echo $estado_clase; ?>">
                            <?php echo $vocero['estado'] == 'activo' ? '<i class="bi bi-check-circle-fill text-success"></i> Activo' : '<i class="bi bi-dash-circle text-secondary"></i> Inactivo'; ?>
                        </div>
                    </div>
                    <div class="card-acciones">
                        <?php if($vocero['estado'] == 'inactivo'): ?>
                            <button class="btn-accion activate" onclick="activar(<?php echo $vocero['id']; ?>)" title="Activar"><i class="bi bi-unlock-fill"></i></button>
                        <?php else: ?>
                            <button class="btn-accion deactivate" onclick="desactivar(<?php echo $vocero['id']; ?>)" title="Desactivar"><i class="bi bi-lock-fill"></i></button>
                        <?php endif; ?>
                        <button class="btn-accion edit" onclick="editar(<?php echo $vocero['id']; ?>)" title="Editar"><i class="bi bi-pencil"></i></button>
                        <button class="btn-accion delete" onclick="eliminar(<?php echo $vocero['id']; ?>, '<?php echo addslashes($nombre_completo); ?>')" title="Eliminar"><i class="bi bi-trash"></i></button>
                    </div>
                </div>

                <div class="card-body">
                    <div class="celador-avatar <?php echo $estado_clase; ?>">
                        <div class="avatar-icono"><i class="bi bi-megaphone-fill"></i></div>
                    </div>
                    <h3 class="celador-nombre"><?php echo $nombre_completo; ?></h3>
                    <div class="celador-cedula">CC: <?php echo htmlspecialchars($vocero['cedula']); ?></div>

                    <div class="celador-info">
                        <?php if(!empty($vocero['programa_estudio'])): ?>
                        <div class="info-item">
                            <span class="info-icono"><i class="bi bi-book-fill"></i></span>
                            <span class="info-texto"><?php echo htmlspecialchars($vocero['programa_estudio']); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if(!empty($vocero['codigo_ficha'])): ?>
                        <div class="info-item">
                            <span class="info-icono"><i class="bi bi-journal-bookmark-fill"></i></span>
                            <span class="info-texto">
                                Ficha <?php echo htmlspecialchars($vocero['codigo_ficha']); ?>
                                <?php if(!empty($vocero['ficha_programa'])): ?>
                                    · <?php echo htmlspecialchars($vocero['ficha_programa']); ?>
                                <?php endif; ?>
                            </span>
                        </div>
                        <?php endif; ?>
                        <?php if(!empty($vocero['fecha_inicio_contrato']) && !empty($vocero['fecha_fin_contrato'])): ?>
                        <div class="info-item">
                            <span class="info-icono"><i class="bi bi-calendar-range"></i></span>
                            <span class="info-texto">
                                <?php echo date('d/m/Y', strtotime($vocero['fecha_inicio_contrato'])); ?> —
                                <?php echo date('d/m/Y', strtotime($vocero['fecha_fin_contrato'])); ?>
                            </span>
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
                <div class="vacio-icono"><i class="bi bi-megaphone-fill"></i></div>
                <h3 class="vacio-titulo">No hay voceros en esta sede</h3>
                <p class="vacio-texto">Comienza agregando el primer vocero</p>
                <button class="btn-nuevo" onclick="abrirModal()"><i class="bi bi-plus-lg"></i> Crear Primer Vocero</button>
            </div>
        <?php } ?>
    </div>

    <!-- Modal -->
    <div class="modal" id="modalVocero">
        <div class="modal-contenido">
            <span class="modal-cerrar" onclick="cerrarModal()"><i class="bi bi-x-lg"></i></span>

            <h2 id="modalTitulo">Nuevo Vocero</h2>

            <form action="../../controllers/CrearVocero.php" method="POST" id="formVocero">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="accion" id="accion" value="crear">
                <input type="hidden" name="vocero_id" id="voceroId">
                <input type="hidden" name="sede_id" value="<?php echo $sede_id; ?>">

                <!-- Información Personal -->
                <div class="form-seccion">
                    <h3 class="form-seccion-titulo"><i class="bi bi-clipboard"></i> Información Personal</h3>

                    <div class="form-grupo">
                        <label>Nombre completo *</label>
                        <input type="text" name="nombre" id="voceroNombre" placeholder="Ej: Pedro Ramírez Silva" required>
                    </div>

                    <div class="form-grupo">
                        <label>Cédula *</label>
                        <input type="number" name="cedula" id="voceroCedula" placeholder="Ej: 1234567890" required>
                    </div>

                    <div class="form-grupo">
                        <label>Email *</label>
                        <input type="email" name="email" id="voceroEmail" placeholder="ejemplo@correo.com" required>
                    </div>

                    <div class="form-grupo">
                        <label>Teléfono / Celular</label>
                        <input type="tel" name="telefono" id="voceroTelefono" placeholder="Ej: 3001234567">
                        <small class="form-ayuda">Sin prefijo +57. Requerido para notificaciones.</small>
                    </div>

                    <div class="form-grupo">
                        <label>Contraseña *</label>
                        <input type="password" name="contrasena" id="voceroContrasena" placeholder="Mínimo 6 caracteres" minlength="6" required>
                        <small class="form-ayuda" id="contrasenaAyuda" style="display:none">Deja vacío para mantener la contraseña actual</small>
                    </div>
                </div>

                <!-- Formación -->
                <div class="form-seccion">
                    <h3 class="form-seccion-titulo"><i class="bi bi-mortarboard-fill"></i> Formación</h3>

                    <div class="form-grupo">
                        <label>Programa de Estudio *</label>
                        <input type="text" name="programa_estudio" id="voceroPrograma" placeholder="Ej: Análisis y Desarrollo de Software" required>
                    </div>

                    <div class="form-grupo">
                        <label>Ficha asignada</label>
                        <select name="ficha_id" id="voceroFicha">
                            <option value="">Sin ficha asignada</option>
                            <?php foreach($fichas_sede as $ficha): ?>
                                <option value="<?php echo (int)$ficha['id']; ?>">
                                    <?php
                                    echo 'Ficha ' . htmlspecialchars($ficha['codigo_ficha']);
                                    if(!empty($ficha['programa'])) {
                                        echo ' · ' . htmlspecialchars($ficha['programa']);
                                    }
                                    if(!empty($ficha['vocero_id'])) {
                                        echo ' · ya asignada';
                                    }
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="form-ayuda">Selecciona la ficha que representará este vocero.</small>
                    </div>
                </div>

                <div class="form-botones">
                    <button type="button" class="btn-cancelar" onclick="cerrarModal()">Cancelar</button>
                    <button type="submit" class="btn-guardar">
                        <span id="btnTexto"><i class="bi bi-floppy"></i> Guardar Vocero</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const sedeId = <?php echo $sede_id; ?>;
        const csrfToken = <?php echo json_encode(csrf_token()); ?>;
    </script>
    <script src="../../assets/js/voceros_admin.js"></script>
</body>
</html>
