<?php
session_start();

if(!isset($_SESSION['id']) || $_SESSION['rol'] != 'admin') {
    header("Location: ../home.php");
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

// Verificar que se recibió el ID del piso
if(!isset($_GET['piso_id'])) {
    header("Location: pisos_admin.php?sede_id=$sede_id");
    exit();
}

$piso_id = intval($_GET['piso_id']);

// ⭐ VALIDAR QUE EL PISO PERTENEZCA A LA SEDE
$query_piso = "SELECT * FROM pisos WHERE id = $piso_id AND sede_id = $sede_id";
$result_piso = mysqli_query($con, $query_piso);

if(mysqli_num_rows($result_piso) == 0) {
    header("Location: pisos_admin.php?sede_id=$sede_id");
    exit();
}

$piso = mysqli_fetch_assoc($result_piso);

// Obtener ambientes del piso
$query_ambientes = "SELECT * FROM ambientes WHERE piso_id = $piso_id ORDER BY nombre ASC";
$ambientes = mysqli_query($con, $query_ambientes);

// Estadísticas del piso
$total_ambientes = mysqli_num_rows($ambientes);
$stats_ocupados = mysqli_fetch_assoc(mysqli_query($con, "SELECT COUNT(*) total FROM ambientes WHERE piso_id = $piso_id AND estado = 'ocupado'"))['total'];
$stats_disponibles = $total_ambientes - $stats_ocupados;

$porcentaje_ocupacion = 0;
if($total_ambientes > 0) {
    $porcentaje_ocupacion = round(($stats_ocupados / $total_ambientes) * 100);
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Ambientes - <?php echo $piso['nombre']; ?></title>
    <link rel="stylesheet" href="../../assets/css/ambientes_piso.css?v=20261013">
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

    <!-- Breadcrumb -->
    <div class="breadcrumb">
        <a href="pisos_admin.php?sede_id=<?php echo $sede_id; ?>">← Volver a Pisos</a>
        <span class="separador">/</span>
        <span class="actual"><?php echo $piso['nombre']; ?></span>
    </div>

    <!-- Encabezado del Piso -->
    <div class="encabezado-piso">
        <div class="piso-info">
            <div class="piso-icono-grande"><i class="bi bi-building"></i></div>
            <div>
                <h1><?php echo $piso['nombre']; ?></h1>
                <p class="piso-desc"><?php echo $piso['descripcion']; ?></p>
            </div>
        </div>
        <button class="btn-nuevo-ambiente" onclick="abrirModalAmbiente()">
            <i class="bi bi-plus-lg"></i> Nuevo Ambiente
        </button>
    </div>

    <!-- Mensajes -->
    <?php if(isset($_GET['msg'])): ?>
        <div class="mensaje-alert mensaje-exito" id="mensajeAlert">
            <?php 
            switch($_GET['msg']) {
                case 'deleted':
                    echo '<i class="bi bi-check-circle-fill"></i> Ambiente eliminado exitosamente';
                    break;
                case 'created':
                    echo '<i class="bi bi-check-circle-fill"></i> Ambiente creado exitosamente';
                    break;
                case 'updated':
                    echo '<i class="bi bi-check-circle-fill"></i> Ambiente actualizado exitosamente';
                    break;
            }
            ?>
        </div>
    <?php endif; ?>

    <?php if(isset($_GET['error'])): ?>
        <div class="mensaje-alert mensaje-error" id="mensajeAlert">
            <i class="bi bi-x-circle-fill"></i> Error en la operación
        </div>
    <?php endif; ?>

    <!-- Estadísticas -->
    <div class="estadisticas-ambientes">
        <div class="stat-card">
            <div class="stat-icono"><i class="bi bi-door-open"></i></div>
            <div class="stat-info">
                <span class="stat-numero"><?php echo $total_ambientes; ?></span>
                <span class="stat-label">Total Ambientes</span>
            </div>
        </div>

        <div class="stat-card ocupados">
            <div class="stat-icono"><i class="bi bi-circle-fill text-danger" style="font-size:.7rem"></i></div>
            <div class="stat-info">
                <span class="stat-numero"><?php echo $stats_ocupados; ?></span>
                <span class="stat-label">Ocupados</span>
            </div>
        </div>

        <div class="stat-card disponibles">
            <div class="stat-icono"><i class="bi bi-circle-fill text-success" style="font-size:.7rem"></i></div>
            <div class="stat-info">
                <span class="stat-numero"><?php echo $stats_disponibles; ?></span>
                <span class="stat-label">Disponibles</span>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icono"><i class="bi bi-bar-chart-line"></i></div>
            <div class="stat-info">
                <span class="stat-numero"><?php echo $porcentaje_ocupacion; ?>%</span>
                <span class="stat-label">Ocupación</span>
            </div>
        </div>
    </div>

    <!-- Filtros -->
    <div class="filtros-ambientes">
        <input type="text" id="buscarAmbiente" placeholder=" Buscar ambiente..." onkeyup="filtrarAmbientes()">
        
        <select id="filtrarEstado" onchange="filtrarPorEstado()">
            <option value="">Todos los estados</option>
            <option value="disponible">Disponibles</option>
            <option value="ocupado">Ocupados</option>
        </select>

        <select id="ordenarAmbientes" onchange="ordenarAmbientes()">
            <option value="">Ordenar por...</option>
            <option value="nombre">Nombre (A-Z)</option>
            <option value="estado">Estado</option>
        </select>
    </div>

    <!-- Grid de Ambientes -->
    <div class="ambientes-grid" id="ambientesGrid">
        <?php 
        if($total_ambientes > 0) {
            mysqli_data_seek($ambientes, 0);
            while($ambiente = mysqli_fetch_assoc($ambientes)) {
                $estado_clase = $ambiente['estado'] == 'ocupado' ? 'ocupado' : 'disponible';
                $estado_texto = $ambiente['estado'] == 'ocupado' ? 'Ocupado' : 'Disponible';
                $estado_icono = $ambiente['estado'] == 'ocupado' ? '<i class="bi bi-circle-fill text-danger" style="font-size:.7rem"></i>' : '<i class="bi bi-circle-fill text-success" style="font-size:.7rem"></i>';
        ?>
            <div class="ambiente-card <?php echo $estado_clase; ?>" 
                 data-nombre="<?php echo strtolower($ambiente['nombre']); ?>"
                 data-estado="<?php echo $ambiente['estado']; ?>">
                
                <div class="ambiente-header">
                    <div class="ambiente-estado-badge <?php echo $estado_clase; ?>">
                        <?php echo $estado_icono; ?> <?php echo $estado_texto; ?>
                    </div>
                    <div class="ambiente-acciones">
                        <button class="btn-accion-small editar" onclick="editarAmbiente(<?php echo $ambiente['id']; ?>)" title="Editar">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button class="btn-accion-small eliminar" onclick="eliminarAmbiente(<?php echo $ambiente['id']; ?>, '<?php echo addslashes($ambiente['nombre']); ?>')" title="Eliminar">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </div>

                <div class="ambiente-body">
                    <div class="ambiente-icono-wrapper">
                        <div class="ambiente-icono"><i class="bi bi-door-open"></i></div>
                    </div>
                    <h3 class="ambiente-nombre"><?php echo $ambiente['nombre']; ?></h3>
                    <p class="ambiente-descripcion"><?php echo $ambiente['descripcion']; ?></p>
                </div>

                <?php if($ambiente['estado'] == 'ocupado'): ?>
                    <div class="ambiente-footer ocupado-info">
                        <div class="info-item">
                            <span class="info-label">En uso</span>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="ambiente-footer disponible-info">
                        <div class="info-item">
                            <span class="info-label">Listo para usar</span>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php 
            }
        } else {
        ?>
            <div class="estado-vacio">
                <div class="vacio-icono"><i class="bi bi-door-open"></i></div>
                <h3 class="vacio-titulo">No hay ambientes en este piso</h3>
                <p class="vacio-texto">Comienza agregando el primer ambiente</p>
                <button class="btn-nuevo-ambiente" onclick="abrirModalAmbiente()">
                    <i class="bi bi-plus-lg"></i> Crear Primer Ambiente
                </button>
            </div>
        <?php } ?>
    </div>

    <!-- Modal Nuevo/Editar Ambiente -->
    <div class="modal" id="modalAmbiente">
        <div class="modal-contenido">
            <span class="modal-cerrar" onclick="cerrarModalAmbiente()"><i class="bi bi-x-lg"></i></span>
            
            <h2 id="modalTitulo">Nuevo Ambiente</h2>

            <form action="../../controllers/GuardarAmbiente.php" method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="accion" id="accion" value="crear">
                <input type="hidden" name="ambiente_id" id="ambienteId">
                <input type="hidden" name="piso_id" value="<?php echo $piso_id; ?>">
                <input type="hidden" name="sede_id" value="<?php echo $sede_id; ?>">

                <div class="form-grupo">
                    <label>Nombre del Ambiente *</label>
                    <input type="text" name="nombre" id="ambienteNombre" placeholder="Ej: A-201, Lab 1..." required>
                </div>

                <div class="form-grupo">
                    <label>Descripción *</label>
                    <textarea name="descripcion" id="ambienteDescripcion" rows="4" placeholder="Describe el ambiente..." required></textarea>
                </div>

                <div class="form-grupo">
                    <label>Estado *</label>
                    <select name="estado" id="ambienteEstado" required>
                        <option value="disponible">Disponible</option>
                        <option value="ocupado">Ocupado</option>
                    </select>
                </div>

                <div class="form-botones">
                    <button type="button" class="btn-cancelar" onclick="cerrarModalAmbiente()">Cancelar</button>
                    <button type="submit" class="btn-guardar">
                        <span id="btnTexto"><i class="bi bi-floppy"></i> Guardar Ambiente</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const sedeId = <?php echo $sede_id; ?>;
        const csrfToken = <?php echo json_encode(csrf_token()); ?>;
        const pisoId = <?php echo $piso_id; ?>;

        const btnMenu = document.getElementById("btnMenu");
        const modalMenu = document.getElementById("modalMenu");

        btnMenu.addEventListener("click", function(){
            modalMenu.classList.add("active");
        });

        modalMenu.addEventListener("click", function(e){
            if(e.target === modalMenu){
                modalMenu.classList.remove("active");
            }
        });

        const mensajeAlert = document.getElementById('mensajeAlert');
        if(mensajeAlert) {
            setTimeout(() => {
                mensajeAlert.style.opacity = '0';
                setTimeout(() => {
                    mensajeAlert.style.display = 'none';
                }, 300);
            }, 5000);
        }

        function abrirModalAmbiente() {
            document.getElementById('modalAmbiente').style.display = 'flex';
            document.getElementById('modalTitulo').textContent = 'Nuevo Ambiente';
            document.getElementById('btnTexto').innerHTML = '<i class="bi bi-floppy"></i> Guardar Ambiente';
            document.getElementById('accion').value = 'crear';
            document.getElementById('ambienteId').value = '';
            document.getElementById('ambienteNombre').value = '';
            document.getElementById('ambienteDescripcion').value = '';
            document.getElementById('ambienteEstado').value = 'disponible';
        }

        function cerrarModalAmbiente() {
            document.getElementById('modalAmbiente').style.display = 'none';
        }

        function editarAmbiente(ambienteId) {
            document.getElementById('modalAmbiente').style.display = 'flex';
            document.getElementById('modalTitulo').textContent = 'Editar Ambiente';
            document.getElementById('btnTexto').innerHTML = '<i class="bi bi-floppy"></i> Actualizar Ambiente';
            document.getElementById('accion').value = 'editar';
            document.getElementById('ambienteId').value = ambienteId;

            fetch(`../../controllers/ObtenerAmbiente.php?id=${ambienteId}`)
                .then(response => response.json())
                .then(data => {
                    if(data.success) {
                        document.getElementById('ambienteNombre').value = data.ambiente.nombre;
                        document.getElementById('ambienteDescripcion').value = data.ambiente.descripcion;
                        document.getElementById('ambienteEstado').value = data.ambiente.estado;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error al cargar los datos del ambiente');
                });
        }

        // ⭐ ELIMINAR CON SEDE_ID
        function eliminarAmbiente(ambienteId, ambienteNombre) {
            if(confirm(`¿Estás seguro de eliminar el ambiente "${ambienteNombre}"?`)) {
                window.location.href = `../../controllers/Eliminarambiente.php?id=${ambienteId}&piso_id=${pisoId}&sede_id=${sedeId}&csrf_token=${csrfToken}`;
            }
        }

        function filtrarAmbientes() {
            const busqueda = document.getElementById('buscarAmbiente').value.toLowerCase();
            const cards = document.querySelectorAll('.ambiente-card');

            cards.forEach(card => {
                const nombre = card.dataset.nombre;
                if(nombre.includes(busqueda)) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        function filtrarPorEstado() {
            const estado = document.getElementById('filtrarEstado').value;
            const cards = document.querySelectorAll('.ambiente-card');

            cards.forEach(card => {
                if(estado === '' || card.dataset.estado === estado) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        function ordenarAmbientes() {
            const ordenar = document.getElementById('ordenarAmbientes').value;
            const grid = document.getElementById('ambientesGrid');
            const cards = Array.from(document.querySelectorAll('.ambiente-card'));

            if(!ordenar) return;

            cards.sort((a, b) => {
                if(ordenar === 'nombre') {
                    return a.dataset.nombre.localeCompare(b.dataset.nombre);
                } else if(ordenar === 'estado') {
                    return a.dataset.estado.localeCompare(b.dataset.estado);
                }
            });

            cards.forEach(card => grid.appendChild(card));
        }

        document.addEventListener('keydown', function(e) {
            if(e.key === 'Escape') {
                cerrarModalAmbiente();
            }
        });

        const modalAmbiente = document.getElementById('modalAmbiente');
        if(modalAmbiente) {
            modalAmbiente.addEventListener('click', function(e) {
                if(e.target === this) {
                    cerrarModalAmbiente();
                }
            });
        }
    </script>
</body>
</html>
