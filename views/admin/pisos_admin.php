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

// ⭐ OBTENER Y VALIDAR SEDE_ID
$sede_id = isset($_GET['sede_id']) ? intval($_GET['sede_id']) : null;

// Si no hay sede_id, redirigir al índice de sedes
if(!$sede_id) {
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

// ⭐ OBTENER PISOS FILTRADOS POR SEDE
$query = "SELECT p.*, 
          COUNT(a.id) as total_ambientes,
          SUM(CASE WHEN a.estado = 'ocupado' THEN 1 ELSE 0 END) as ambientes_ocupados
          FROM pisos p 
          LEFT JOIN ambientes a ON p.id = a.piso_id 
          WHERE p.sede_id = $sede_id
          GROUP BY p.id 
          ORDER BY p.nombre ASC";

$pisos = mysqli_query($con, $query);

// ⭐ ESTADÍSTICAS FILTRADAS POR SEDE
$stats_pisos = mysqli_fetch_assoc(mysqli_query($con,"SELECT COUNT(*) total FROM pisos WHERE sede_id = $sede_id"))['total'];

$stats_ambientes = mysqli_fetch_assoc(mysqli_query($con,"
    SELECT COUNT(*) total 
    FROM ambientes a
    JOIN pisos p ON a.piso_id = p.id
    WHERE p.sede_id = $sede_id
"))['total'];

$stats_ocupados = mysqli_fetch_assoc(mysqli_query($con,"
    SELECT COUNT(*) total 
    FROM ambientes a
    JOIN pisos p ON a.piso_id = p.id
    WHERE a.estado='ocupado' AND p.sede_id = $sede_id
"))['total'];

$porcentaje_ocupacion = 0;
if($stats_ambientes > 0) {
    $porcentaje_ocupacion = round(($stats_ocupados / $stats_ambientes) * 100);
}

$stats_disponibles = $stats_ambientes - $stats_ocupados;
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pisos - <?php echo $sede_nombre; ?></title>
    <link rel="stylesheet" href="../../assets/css/pisos_admin.css?v=20261013">
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
            <div class="linea"></div>
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

    <!-- Sidebar Menu -->
    <?php $pagina_actual = basename($_SERVER['PHP_SELF']); ?>
        <?php include __DIR__ . '/_sidebar.php'; ?>

    <!-- Encabezado -->
    <div class="encabezado">
        <div class="encabezado-contenido">
            <h1>Resumen de Pisos - <?php echo $sede_nombre; ?></h1>
            <p>Administre las infraestructuras de los diferentes niveles de la sede.</p>
        </div>
        <button class="btn-nuevo-piso" onclick="abrirModalPiso()">
            <i class="bi bi-plus-lg"></i> Nuevo Piso
        </button>
    </div>

    <!-- Mensajes de éxito/error -->
    <?php if(isset($_GET['msg'])): ?>
        <div class="mensaje-alert mensaje-exito" id="mensajeAlert">
            <?php 
            switch($_GET['msg']) {
                case 'deleted':
                    echo '<i class="bi bi-check-circle-fill"></i> Piso eliminado exitosamente';
                    break;
                case 'created':
                    echo '<i class="bi bi-check-circle-fill"></i> Piso creado exitosamente';
                    break;
                case 'updated':
                    echo '<i class="bi bi-check-circle-fill"></i> Piso actualizado exitosamente';
                    break;
            }
            ?>
        </div>
    <?php endif; ?>

    <?php if(isset($_GET['error'])): ?>
        <div class="mensaje-alert mensaje-error" id="mensajeAlert">
            <?php 
            switch($_GET['error']) {
                case 'delete_failed':
                    echo '<i class="bi bi-x-circle-fill"></i> Error al eliminar el piso';
                    break;
                case 'create_failed':
                    echo '<i class="bi bi-x-circle-fill"></i> Error al crear el piso';
                    break;
                case 'update_failed':
                    echo '<i class="bi bi-x-circle-fill"></i> Error al actualizar el piso';
                    break;
            }
            ?>
        </div>
    <?php endif; ?>

    <!-- Estadísticas -->
    <div class="estadisticas">
        <div class="stat-box">
            <div class="stat-icono"><i class="bi bi-building"></i></div>
            <div class="stat-info">
                <span class="stat-numero"><?php echo $stats_pisos; ?></span>
                <span class="stat-label">Total Pisos</span>
            </div>
        </div>

        <div class="stat-box">
            <div class="stat-icono"><i class="bi bi-door-open"></i></div>
            <div class="stat-info">
                <span class="stat-numero"><?php echo $stats_ambientes; ?></span>
                <span class="stat-label">Total Ambientes</span>
            </div>
        </div>

        <div class="stat-box">
            <div class="stat-icono"><i class="bi bi-bar-chart-line"></i></div>
            <div class="stat-info">
                <span class="stat-numero"><?php echo $porcentaje_ocupacion; ?>%</span>
                <span class="stat-label">Ocupación</span>
            </div>
        </div>

        <div class="stat-box">
            <div class="stat-icono"><i class="bi bi-check-circle-fill"></i></div>
            <div class="stat-info">
                <span class="stat-numero"><?php echo $stats_disponibles; ?></span>
                <span class="stat-label">Disponibles</span>
            </div>
        </div>
    </div>

    <!-- Filtros -->
    <div class="filtros">
        <input type="text" id="buscarPiso" placeholder=" Buscar piso..." onkeyup="filtrarPisos()">
        
        <select id="ordenarPor" onchange="ordenarPisos()">
            <option value="">Ordenar por...</option>
            <option value="nombre">Nombre (A-Z)</option>
            <option value="ambientes">Más Ambientes</option>
            <option value="ocupacion">Mayor Ocupación</option>
        </select>
    </div>

    <!-- Grid de Pisos (Tarjetas) -->
    <div class="pisos-contenedor" id="pisosContenedor">
        <?php 
        if(mysqli_num_rows($pisos) > 0) {
            while($piso = mysqli_fetch_assoc($pisos)) {
                $total_ambientes = $piso['total_ambientes'];
                $ambientes_ocupados = $piso['ambientes_ocupados'];
                $porcentaje_piso = 0;
                
                if($total_ambientes > 0) {
                    $porcentaje_piso = round(($ambientes_ocupados / $total_ambientes) * 100);
                }
                
                $disponibles = $total_ambientes - $ambientes_ocupados;
        ?>
            <div class="tarjeta-piso" 
                 data-nombre="<?php echo strtolower($piso['nombre']); ?>" 
                 data-descripcion="<?php echo strtolower($piso['descripcion']); ?>"
                 data-ambientes="<?php echo $total_ambientes; ?>"
                 data-ocupacion="<?php echo $porcentaje_piso; ?>">
                
                <!-- Header de la Tarjeta -->
                <div class="tarjeta-header">
                    <div class="header-icono">
                        <i class="bi bi-house"></i>
                    </div>
                    <div class="header-acciones">
                        <button class="btn-icono editar" onclick="editarPiso(<?php echo $piso['id']; ?>)" title="Editar">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button class="btn-icono eliminar" onclick="eliminarPiso(<?php echo $piso['id']; ?>, '<?php echo addslashes($piso['nombre']); ?>')" title="Eliminar">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </div>

                <!-- Cuerpo de la Tarjeta -->
                <div class="tarjeta-body">
                    <h3 class="tarjeta-titulo"><?php echo $piso['nombre']; ?></h3>
                    <p class="tarjeta-descripcion"><?php echo $piso['descripcion']; ?></p>

                    <!-- Estadísticas del Piso -->
                    <div class="tarjeta-stats">
                        <div class="mini-stat">
                            <span class="mini-icono"><i class="bi bi-door-open"></i></span>
                            <div>
                                <span class="mini-numero"><?php echo $total_ambientes; ?></span>
                                <span class="mini-label">Ambientes</span>
                            </div>
                        </div>

                        <div class="mini-stat">
                            <span class="mini-icono"><i class="bi bi-bar-chart-line"></i></span>
                            <div>
                                <span class="mini-numero"><?php echo $porcentaje_piso; ?>%</span>
                                <span class="mini-label">Ocupación</span>
                            </div>
                        </div>
                    </div>

                    <!-- Barra de Progreso -->
                    <div class="barra-progreso">
                        <div class="barra-fill" style="width: <?php echo $porcentaje_piso; ?>%;"></div>
                    </div>
                    <div class="barra-texto">
                        <?php echo $ambientes_ocupados; ?> de <?php echo $total_ambientes; ?> ocupados
                    </div>

                    <!-- Detalles Adicionales -->
                    <div class="detalles-extra">
                        <div class="detalle-item">
                            <span class="detalle-icono ocupados"><i class="bi bi-circle-fill text-danger" style="font-size:.7rem"></i></span>
                            <span><?php echo $ambientes_ocupados; ?> Ocupados</span>
                        </div>
                        <div class="detalle-item">
                            <span class="detalle-icono disponibles"><i class="bi bi-circle-fill text-success" style="font-size:.7rem"></i></span>
                            <span><?php echo $disponibles; ?> Disponibles</span>
                        </div>
                    </div>
                </div>

                <!-- Footer de la Tarjeta -->
                <div class="tarjeta-footer">
                    <a href="Ambientes_piso.php?piso_id=<?php echo $piso['id']; ?>&sede_id=<?php echo $sede_id; ?>" class="btn-ver-ambientes">
                        Ver Ambientes →
                    </a>
                </div>
            </div>
        <?php 
            }
        } else {
        ?>
            <!-- Estado Vacío -->
            <div class="estado-vacio">
                <div class="vacio-icono"><i class="bi bi-building"></i></div>
                <h3 class="vacio-titulo">No hay pisos registrados en esta sede</h3>
                <p class="vacio-texto">Comienza agregando tu primer piso al sistema</p>
                <button class="btn-nuevo-piso" onclick="abrirModalPiso()">
                    <i class="bi bi-plus-lg"></i> Crear Primer Piso
                </button>
            </div>
        <?php } ?>
    </div>

    <!-- Modal Nuevo/Editar Piso -->
    <div class="modal" id="modalPiso">
        <div class="modal-contenido">
            <span class="modal-cerrar" onclick="cerrarModalPiso()"><i class="bi bi-x-lg"></i></span>
            
            <h2 id="modalTitulo">Nuevo Piso</h2>

            <form action="../../controllers/GuardarPiso.php" method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="accion" id="accion" value="crear">
                <input type="hidden" name="piso_id" id="pisoId">
                <input type="hidden" name="sede_id" value="<?php echo $sede_id; ?>">

                <div class="form-grupo">
                    <label>Nombre del Piso *</label>
                    <input type="text" name="nombre" id="pisoNombre" placeholder="Ej: Piso 1, Piso 2..." required>
                </div>

                <div class="form-grupo">
                    <label>Descripción *</label>
                    <textarea name="descripcion" id="pisoDescripcion" rows="4" placeholder="Describe el piso..." required></textarea>
                </div>

                <div class="form-botones">
                    <button type="button" class="btn-cancelar" onclick="cerrarModalPiso()">Cancelar</button>
                    <button type="submit" class="btn-guardar">
                        <span id="btnTexto"><i class="bi bi-floppy"></i> Guardar Piso</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const sedeId = <?php echo $sede_id; ?>;
        const csrfToken = <?php echo json_encode(csrf_token()); ?>;

        // Menu toggle
        let btnMenu = document.getElementById("btnMenu");
        let modalMenu = document.getElementById("modalMenu");

        btnMenu.addEventListener("click", function(){
            modalMenu.classList.add("active");
        });

        modalMenu.addEventListener("click", function(e){
            if(e.target === modalMenu){
                modalMenu.classList.remove("active");
            }
        });

        // Auto-ocultar mensajes después de 5 segundos
        const mensajeAlert = document.getElementById('mensajeAlert');
        if(mensajeAlert) {
            setTimeout(() => {
                mensajeAlert.style.opacity = '0';
                setTimeout(() => {
                    mensajeAlert.style.display = 'none';
                }, 300);
            }, 5000);
        }

        // Modal Piso
        function abrirModalPiso() {
            document.getElementById('modalPiso').style.display = 'flex';
            document.getElementById('modalTitulo').textContent = 'Nuevo Piso';
            document.getElementById('btnTexto').innerHTML = '<i class="bi bi-floppy"></i> Guardar Piso';
            document.getElementById('accion').value = 'crear';
            document.getElementById('pisoId').value = '';
            document.getElementById('pisoNombre').value = '';
            document.getElementById('pisoDescripcion').value = '';
        }

        function cerrarModalPiso() {
            document.getElementById('modalPiso').style.display = 'none';
        }

        function editarPiso(pisoId) {
            document.getElementById('modalPiso').style.display = 'flex';
            document.getElementById('modalTitulo').textContent = 'Editar Piso';
            document.getElementById('btnTexto').innerHTML = '<i class="bi bi-floppy"></i> Actualizar Piso';
            document.getElementById('accion').value = 'editar';
            document.getElementById('pisoId').value = pisoId;

            // Cargar datos del piso
            fetch(`../../controllers/ObtenerPiso.php?id=${pisoId}`)
                .then(response => response.json())
                .then(data => {
                    if(data.success) {
                        document.getElementById('pisoNombre').value = data.piso.nombre;
                        document.getElementById('pisoDescripcion').value = data.piso.descripcion;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error al cargar los datos del piso');
                });
        }

        function eliminarPiso(pisoId, pisoNombre) {
            if(confirm(`¿Estás seguro de eliminar el piso "${pisoNombre}"?\n\n<i class="bi bi-exclamation-triangle-fill"></i> ADVERTENCIA: Esto también eliminará todos los ambientes asociados.`)) {
                window.location.href = `../../controllers/EliminarPiso.php?id=${pisoId}&sede_id=${sedeId}&csrf_token=${csrfToken}`;
            }
        }

        // Filtrar pisos
        function filtrarPisos() {
            const busqueda = document.getElementById('buscarPiso').value.toLowerCase();
            const tarjetas = document.querySelectorAll('.tarjeta-piso');

            tarjetas.forEach(tarjeta => {
                const nombre = tarjeta.dataset.nombre;
                const descripcion = tarjeta.dataset.descripcion;

                if(nombre.includes(busqueda) || descripcion.includes(busqueda)) {
                    tarjeta.style.display = 'block';
                } else {
                    tarjeta.style.display = 'none';
                }
            });
        }

        // Ordenar pisos
        function ordenarPisos() {
            const ordenar = document.getElementById('ordenarPor').value;
            const contenedor = document.getElementById('pisosContenedor');
            const tarjetas = Array.from(document.querySelectorAll('.tarjeta-piso'));

            if(!ordenar) return;

            tarjetas.sort((a, b) => {
                if(ordenar === 'nombre') {
                    return a.dataset.nombre.localeCompare(b.dataset.nombre);
                } else if(ordenar === 'ambientes') {
                    return parseInt(b.dataset.ambientes) - parseInt(a.dataset.ambientes);
                } else if(ordenar === 'ocupacion') {
                    return parseInt(b.dataset.ocupacion) - parseInt(a.dataset.ocupacion);
                }
            });

            tarjetas.forEach(tarjeta => contenedor.appendChild(tarjeta));
        }

        // Cerrar modal con ESC
        document.addEventListener('keydown', function(e) {
            if(e.key === 'Escape') {
                cerrarModalPiso();
            }
        });

        // Cerrar modal al hacer click fuera
        const modalPiso = document.getElementById('modalPiso');
        if(modalPiso) {
            modalPiso.addEventListener('click', function(e) {
                if(e.target === this) {
                    cerrarModalPiso();
                }
            });
        }
    </script>
</body>
</html>
