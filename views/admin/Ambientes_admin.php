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

// ⭐ OBTENER AMBIENTES FILTRADOS POR SEDE (estado calculado desde historial_ocupacion de HOY)
$query = "SELECT a.id, a.nombre, a.descripcion, a.piso_id,
          p.nombre as piso_nombre, p.descripcion as piso_descripcion,
          CASE
              WHEN EXISTS (
                  SELECT 1 FROM historial_ocupacion ho
                  WHERE ho.ambiente_id = a.id
                  AND ho.estado IN ('ocupado', 'proximo_a_desocupar')
                  AND DATE(ho.fecha_inicio) = CURDATE()
              ) THEN 'ocupado'
              ELSE 'disponible'
          END AS estado
          FROM ambientes a
          INNER JOIN pisos p ON a.piso_id = p.id
          WHERE p.sede_id = $sede_id
          ORDER BY p.nombre ASC, a.nombre ASC";

$ambientes = mysqli_query($con, $query);

// ⭐ OBTENER PISOS FILTRADOS POR SEDE
$pisos_query = "SELECT * FROM pisos WHERE sede_id = $sede_id ORDER BY nombre ASC";
$pisos = mysqli_query($con, $pisos_query);

// ⭐ ESTADÍSTICAS FILTRADAS POR SEDE
$stats_total = mysqli_fetch_assoc(mysqli_query($con,"
    SELECT COUNT(*) total 
    FROM ambientes a
    JOIN pisos p ON a.piso_id = p.id
    WHERE p.sede_id = $sede_id
"))['total'];

$stats_ocupados = mysqli_fetch_assoc(mysqli_query($con,"
    SELECT COUNT(DISTINCT a.id) total
    FROM ambientes a
    JOIN pisos p ON a.piso_id = p.id
    WHERE p.sede_id = $sede_id
    AND EXISTS (
        SELECT 1 FROM historial_ocupacion ho
        WHERE ho.ambiente_id = a.id
        AND ho.estado IN ('ocupado','proximo_a_desocupar')
        AND DATE(ho.fecha_inicio) = CURDATE()
    )
"))['total'];

$stats_disponibles = $stats_total - $stats_ocupados;

$porcentaje_ocupacion = 0;
if($stats_total > 0) {
    $porcentaje_ocupacion = round(($stats_ocupados / $stats_total) * 100);
}

$stats_pisos = mysqli_fetch_assoc(mysqli_query($con,"SELECT COUNT(*) total FROM pisos WHERE sede_id = $sede_id"))['total'];
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Ambientes - <?php echo $sede_nombre; ?></title>
    <link rel="stylesheet" href="../../assets/css/ambientes_admin.css?v=20261013">
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
            <h1>Gestión de Ambientes - <?php echo $sede_nombre; ?></h1>
            <p>Administra todos los ambientes académicos de esta sede</p>
        </div>
        <div class="encabezado-botones">
            <a href="carga_masiva_ambientes.php?sede_id=<?php echo $sede_id; ?>" class="btn-carga-masiva">
                <i class="bi bi-upload"></i> Carga Masiva
            </a>
            <button class="btn-nuevo" onclick="abrirModal()">
                <i class="bi bi-plus-lg"></i> Nuevo Ambiente
            </button>
        </div>
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
    <div class="estadisticas">
        <div class="stat-box">
            <div class="stat-icono"><i class="bi bi-door-open"></i></div>
            <div class="stat-info">
                <span class="stat-numero"><?php echo $stats_total; ?></span>
                <span class="stat-label">Total Ambientes</span>
            </div>
        </div>

        <div class="stat-box disponibles">
            <div class="stat-icono"><i class="bi bi-circle-fill text-success" style="font-size:.7rem"></i></div>
            <div class="stat-info">
                <span class="stat-numero"><?php echo $stats_disponibles; ?></span>
                <span class="stat-label">Disponibles</span>
            </div>
        </div>

        <div class="stat-box ocupados">
            <div class="stat-icono"><i class="bi bi-circle-fill text-danger" style="font-size:.7rem"></i></div>
            <div class="stat-info">
                <span class="stat-numero"><?php echo $stats_ocupados; ?></span>
                <span class="stat-label">Ocupados</span>
            </div>
        </div>

        <div class="stat-box">
            <div class="stat-icono"><i class="bi bi-bar-chart-line"></i></div>
            <div class="stat-info">
                <span class="stat-numero"><?php echo $porcentaje_ocupacion; ?>%</span>
                <span class="stat-label">Ocupación</span>
            </div>
        </div>
    </div>

    <!-- Filtros -->
    <div class="filtros">
        <input type="text" id="buscarAmbiente" placeholder=" Buscar ambiente..." onkeyup="filtrar()">
        
        <select id="filtrarPiso" onchange="filtrar()">
            <option value="">Todos los pisos</option>
            <?php 
            mysqli_data_seek($pisos, 0);
            while($p = mysqli_fetch_assoc($pisos)): 
            ?>
                <option value="<?php echo strtolower($p['nombre']); ?>"><?php echo $p['nombre']; ?></option>
            <?php endwhile; ?>
        </select>

        <select id="filtrarEstado" onchange="filtrar()">
            <option value="">Todos los estados</option>
            <option value="disponible">Disponibles</option>
            <option value="ocupado">Ocupados</option>
        </select>

        <select id="ordenar" onchange="ordenar()">
            <option value="">Ordenar por...</option>
            <option value="nombre">Nombre (A-Z)</option>
            <option value="piso">Piso (A-Z)</option>
            <option value="estado">Estado</option>
        </select>
    </div>

    <!-- Vista de Ambientes -->
    <div class="contenedor-vista">
        <!-- Controles de Vista -->
        <div class="controles-vista">
            <div class="vista-botones">
                <button class="btn-vista active" data-vista="grid" onclick="cambiarVista('grid')">
                    <span>⊞</span> Tarjetas
                </button>
                <button class="btn-vista" data-vista="table" onclick="cambiarVista('table')">
                    <span><i class="bi bi-list"></i></span> Tabla
                </button>
            </div>
            <div class="contador">
                <span id="contadorAmbientes"><?php echo $stats_total; ?></span> ambiente(s)
            </div>
        </div>

        <!-- Vista Grid (Tarjetas) -->
        <div class="ambientes-grid" id="vistaGrid">
            <?php 
            if(mysqli_num_rows($ambientes) > 0) {
                mysqli_data_seek($ambientes, 0);
                while($ambiente = mysqli_fetch_assoc($ambientes)) {
                    $estado_clase = $ambiente['estado'] == 'ocupado' ? 'ocupado' : 'disponible';
                    $estado_texto = $ambiente['estado'] == 'ocupado' ? 'Ocupado' : 'Disponible';
                    $estado_icono = $ambiente['estado'] == 'ocupado' ? '<i class="bi bi-circle-fill text-danger" style="font-size:.7rem"></i>' : '<i class="bi bi-circle-fill text-success" style="font-size:.7rem"></i>';

                    // Consultar info de ocupación activa de HOY solo si el ambiente está ocupado
                    $ocup_info = null;
                    if($ambiente['estado'] == 'ocupado') {
                        $ocup_q = mysqli_query($con,
                            "SELECT ho.id, ho.fecha_inicio, ho.fecha_fin, ho.jornada,
                                    CONCAT_WS(' ', u.nombre, u.apellido) AS instructor_nombre
                             FROM historial_ocupacion ho
                             LEFT JOIN usuarios u ON ho.usuario_id = u.id
                             WHERE ho.ambiente_id = {$ambiente['id']}
                               AND ho.estado IN ('ocupado', 'proximo_a_desocupar')
                               AND DATE(ho.fecha_inicio) = CURDATE()
                             ORDER BY ho.fecha_inicio ASC
                             LIMIT 1");
                        if($ocup_q && mysqli_num_rows($ocup_q) > 0) {
                            $ocup_info = mysqli_fetch_assoc($ocup_q);
                        }
                    }
            ?>
                <div class="ambiente-card <?php echo $estado_clase; ?>"
                     data-nombre="<?php echo strtolower($ambiente['nombre']); ?>"
                     data-piso="<?php echo strtolower($ambiente['piso_nombre']); ?>"
                     data-estado="<?php echo $ambiente['estado']; ?>">

                    <div class="card-header">
                        <div class="estado-badge <?php echo $estado_clase; ?>">
                            <?php echo $estado_icono; ?> <?php echo $estado_texto; ?>
                        </div>
                        <div class="card-acciones">
                            <button class="btn-accion edit" onclick="editar(<?php echo $ambiente['id']; ?>)"><i class="bi bi-pencil"></i></button>
                            <button class="btn-accion delete" onclick="eliminar(<?php echo $ambiente['id']; ?>, '<?php echo addslashes($ambiente['nombre']); ?>', <?php echo $ambiente['piso_id']; ?>)"><i class="bi bi-trash"></i></button>
                        </div>
                    </div>

                    <div class="card-body">
                        <div class="ambiente-icono"><i class="bi bi-door-open"></i></div>
                        <h3 class="ambiente-nombre"><?php echo $ambiente['nombre']; ?></h3>
                        <p class="ambiente-descripcion"><?php echo $ambiente['descripcion']; ?></p>
                        <div class="ambiente-piso">
                            <span class="piso-tag"><i class="bi bi-building"></i> <?php echo $ambiente['piso_nombre']; ?></span>
                        </div>
                        <?php if($ambiente['estado'] == 'ocupado'): ?>
                        <div class="card-ocupacion-info">
                            <?php if($ocup_info): ?>
                            <div class="ocup-mini-info">
                                <span><i class="bi bi-person-fill"></i> <?php echo htmlspecialchars($ocup_info['instructor_nombre']); ?></span>
                                <span><i class="bi bi-clock"></i> <?php echo date('H:i', strtotime($ocup_info['fecha_inicio'])); ?> - <?php echo date('H:i', strtotime($ocup_info['fecha_fin'])); ?></span>
                            </div>
                            <?php endif; ?>
                            <?php
                                $url_ocup = "ocupaciones_admin.php?sede_id={$sede_id}";
                                if($ocup_info) $url_ocup .= "&resaltar={$ocup_info['id']}";
                            ?>
                            <a href="<?php echo $url_ocup; ?>" class="btn-ver-ocupacion">
                                <i class="bi bi-eye-fill"></i> Ver ocupación
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php 
                }
            } else {
            ?>
                <div class="estado-vacio">
                    <div class="vacio-icono"><i class="bi bi-door-open"></i></div>
                    <h3 class="vacio-titulo">No hay ambientes en esta sede</h3>
                    <p class="vacio-texto">Comienza agregando el primer ambiente o usa carga masiva para crear varios a la vez</p>
                    <div class="vacio-botones">
                        <button class="btn-nuevo" onclick="abrirModal()"><i class="bi bi-plus-lg"></i> Crear Ambiente</button>
                        <a href="carga_masiva_ambientes.php?sede_id=<?php echo $sede_id; ?>" class="btn-carga-vacio">
                            <i class="bi bi-upload"></i> Carga Masiva
                        </a>
                    </div>
                </div>
            <?php } ?>
        </div>

        <!-- Vista Tabla -->
        <div class="ambientes-tabla" id="vistaTable" style="display: none;">
            <table>
                <thead>
                    <tr>
                        <th>Ambiente</th>
                        <th>Piso</th>
                        <th>Estado</th>
                        <th>Descripción</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    if(mysqli_num_rows($ambientes) > 0) {
                        mysqli_data_seek($ambientes, 0);
                        while($ambiente = mysqli_fetch_assoc($ambientes)) {
                            $estado_clase = $ambiente['estado'] == 'ocupado' ? 'ocupado' : 'disponible';
                            $estado_texto = $ambiente['estado'] == 'ocupado' ? 'Ocupado' : 'Disponible';
                            $estado_icono = $ambiente['estado'] == 'ocupado' ? '<i class="bi bi-circle-fill text-danger" style="font-size:.7rem"></i>' : '<i class="bi bi-circle-fill text-success" style="font-size:.7rem"></i>';

                            $ocup_id_tabla = null;
                            if($ambiente['estado'] == 'ocupado') {
                                $oq = mysqli_query($con,
                                    "SELECT id FROM historial_ocupacion
                                     WHERE ambiente_id = {$ambiente['id']}
                                       AND estado IN ('ocupado', 'proximo_a_desocupar')
                                       AND DATE(fecha_inicio) = CURDATE()
                                     ORDER BY fecha_inicio ASC LIMIT 1");
                                if($oq && mysqli_num_rows($oq) > 0)
                                    $ocup_id_tabla = mysqli_fetch_assoc($oq)['id'];
                            }
                    ?>
                        <tr data-nombre="<?php echo strtolower($ambiente['nombre']); ?>"
                            data-piso="<?php echo strtolower($ambiente['piso_nombre']); ?>"
                            data-estado="<?php echo $ambiente['estado']; ?>">
                            <td>
                                <div class="tabla-nombre"><?php echo $ambiente['nombre']; ?></div>
                            </td>
                            <td>
                                <span class="tabla-piso"><i class="bi bi-building"></i> <?php echo $ambiente['piso_nombre']; ?></span>
                            </td>
                            <td>
                                <span class="tabla-estado <?php echo $estado_clase; ?>">
                                    <?php echo $estado_icono; ?> <?php echo $estado_texto; ?>
                                </span>
                            </td>
                            <td class="tabla-descripcion"><?php echo $ambiente['descripcion']; ?></td>
                            <td>
                                <div class="tabla-acciones">
                                    <button class="btn-accion-tabla edit" onclick="editar(<?php echo $ambiente['id']; ?>)"><i class="bi bi-pencil"></i></button>
                                    <button class="btn-accion-tabla delete" onclick="eliminar(<?php echo $ambiente['id']; ?>, '<?php echo addslashes($ambiente['nombre']); ?>', <?php echo $ambiente['piso_id']; ?>)"><i class="bi bi-trash"></i></button>
                                    <?php if($ambiente['estado'] == 'ocupado'): ?>
                                    <?php
                                        $url_ocup_t = "ocupaciones_admin.php?sede_id={$sede_id}";
                                        if($ocup_id_tabla) $url_ocup_t .= "&resaltar={$ocup_id_tabla}";
                                    ?>
                                    <a href="<?php echo $url_ocup_t; ?>"
                                       class="btn-accion-tabla ver-ocup" title="Ver ocupación">
                                        <i class="bi bi-eye-fill"></i>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php 
                        }
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal -->
    <div class="modal" id="modalAmbiente">
        <div class="modal-contenido">
            <span class="modal-cerrar" onclick="cerrarModal()"><i class="bi bi-x-lg"></i></span>
            
            <h2 id="modalTitulo">Nuevo Ambiente</h2>

            <form action="../../controllers/GuardarAmbienteGeneral.php" method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="accion" id="accion" value="crear">
                <input type="hidden" name="ambiente_id" id="ambienteId">
                <input type="hidden" name="sede_id" value="<?php echo $sede_id; ?>">

                <div class="form-grupo">
                    <label>Nombre del Ambiente *</label>
                    <input type="text" name="nombre" id="ambienteNombre" placeholder="Ej: A-201, Lab 1..." required>
                </div>

                <div class="form-grupo">
                    <label>Piso *</label>
                    <select name="piso_id" id="ambientePiso" required>
                        <option value="">Seleccione un piso</option>
                        <?php 
                        mysqli_data_seek($pisos, 0);
                        while($p = mysqli_fetch_assoc($pisos)): 
                        ?>
                            <option value="<?php echo $p['id']; ?>"><?php echo $p['nombre']; ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="form-grupo">
                    <label>Descripción *</label>
                    <textarea name="descripcion" id="ambienteDescripcion" rows="4" placeholder="Describe el ambiente..." required></textarea>
                </div>

                <input type="hidden" name="estado" id="ambienteEstado" value="disponible">

                <div class="form-botones">
                    <button type="button" class="btn-cancelar" onclick="cerrarModal()">Cancelar</button>
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

        const btnMenu = document.getElementById("btnMenu");
        const modalMenu = document.getElementById("modalMenu");

        btnMenu.addEventListener("click", () => modalMenu.classList.add("active"));
        modalMenu.addEventListener("click", (e) => {
            if(e.target === modalMenu) modalMenu.classList.remove("active");
        });

        const mensajeAlert = document.getElementById('mensajeAlert');
        if(mensajeAlert) {
            setTimeout(() => {
                mensajeAlert.style.opacity = '0';
                setTimeout(() => mensajeAlert.style.display = 'none', 300);
            }, 5000);
        }

        function abrirModal() {
            document.getElementById('modalAmbiente').style.display = 'flex';
            document.getElementById('modalTitulo').textContent = 'Nuevo Ambiente';
            document.getElementById('btnTexto').innerHTML = '<i class="bi bi-floppy"></i> Guardar Ambiente';
            document.getElementById('accion').value = 'crear';
            document.getElementById('ambienteId').value = '';
            document.getElementById('ambienteNombre').value = '';
            document.getElementById('ambientePiso').value = '';
            document.getElementById('ambienteDescripcion').value = '';
            document.getElementById('ambienteEstado').value = 'disponible';
        }

        function cerrarModal() {
            document.getElementById('modalAmbiente').style.display = 'none';
        }

        function editar(id) {
            document.getElementById('modalAmbiente').style.display = 'flex';
            document.getElementById('modalTitulo').textContent = 'Editar Ambiente';
            document.getElementById('btnTexto').innerHTML = '<i class="bi bi-floppy"></i> Actualizar Ambiente';
            document.getElementById('accion').value = 'editar';
            document.getElementById('ambienteId').value = id;

            fetch(`../../controllers/ObtenerAmbiente.php?id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if(data.success) {
                        document.getElementById('ambienteNombre').value = data.ambiente.nombre;
                        document.getElementById('ambientePiso').value = data.ambiente.piso_id;
                        document.getElementById('ambienteDescripcion').value = data.ambiente.descripcion;
                        document.getElementById('ambienteEstado').value = data.ambiente.estado;
                    }
                });
        }

        // ⭐ ELIMINAR CON SEDE_ID
        function eliminar(id, nombre, pisoId) {
            if(confirm(`¿Eliminar el ambiente "${nombre}"?`)) {
                window.location.href = `../../controllers/Eliminarambiente.php?id=${id}&piso_id=${pisoId}&sede_id=${sedeId}&csrf_token=${csrfToken}`;
            }
        }

        function cambiarVista(vista) {
            document.querySelectorAll('.btn-vista').forEach(btn => btn.classList.remove('active'));
            document.querySelector(`[data-vista="${vista}"]`).classList.add('active');
            
            if(vista === 'grid') {
                document.getElementById('vistaGrid').style.display = 'grid';
                document.getElementById('vistaTable').style.display = 'none';
            } else {
                document.getElementById('vistaGrid').style.display = 'none';
                document.getElementById('vistaTable').style.display = 'block';
            }
        }

        function filtrar() {
            const busqueda = document.getElementById('buscarAmbiente').value.toLowerCase();
            const piso = document.getElementById('filtrarPiso').value;
            const estado = document.getElementById('filtrarEstado').value;
            
            const cards = document.querySelectorAll('.ambiente-card');
            const rows = document.querySelectorAll('.ambientes-tabla tbody tr');
            let visibles = 0;

            cards.forEach(card => {
                const nombre = card.dataset.nombre;
                const pisoCard = card.dataset.piso;
                const estadoCard = card.dataset.estado;
                
                const matchBusqueda = nombre.includes(busqueda);
                const matchPiso = !piso || pisoCard === piso;
                const matchEstado = !estado || estadoCard === estado;
                
                if(matchBusqueda && matchPiso && matchEstado) {
                    card.style.display = 'block';
                    visibles++;
                } else {
                    card.style.display = 'none';
                }
            });

            rows.forEach(row => {
                const nombre = row.dataset.nombre;
                const pisoRow = row.dataset.piso;
                const estadoRow = row.dataset.estado;
                
                const matchBusqueda = nombre.includes(busqueda);
                const matchPiso = !piso || pisoRow === piso;
                const matchEstado = !estado || estadoRow === estado;
                
                if(matchBusqueda && matchPiso && matchEstado) {
                    row.style.display = 'table-row';
                } else {
                    row.style.display = 'none';
                }
            });

            document.getElementById('contadorAmbientes').textContent = visibles;
        }

        function ordenar() {
            const criterio = document.getElementById('ordenar').value;
            if(!criterio) return;

            const gridContainer = document.getElementById('vistaGrid');
            const tableBody = document.querySelector('.ambientes-tabla tbody');
            
            const cards = Array.from(document.querySelectorAll('.ambiente-card'));
            const rows = Array.from(document.querySelectorAll('.ambientes-tabla tbody tr'));

            cards.sort((a, b) => {
                if(criterio === 'nombre') return a.dataset.nombre.localeCompare(b.dataset.nombre);
                if(criterio === 'piso') return a.dataset.piso.localeCompare(b.dataset.piso);
                if(criterio === 'estado') return a.dataset.estado.localeCompare(b.dataset.estado);
            });

            rows.sort((a, b) => {
                if(criterio === 'nombre') return a.dataset.nombre.localeCompare(b.dataset.nombre);
                if(criterio === 'piso') return a.dataset.piso.localeCompare(b.dataset.piso);
                if(criterio === 'estado') return a.dataset.estado.localeCompare(b.dataset.estado);
            });

            cards.forEach(card => gridContainer.appendChild(card));
            rows.forEach(row => tableBody.appendChild(row));
        }

        document.addEventListener('keydown', (e) => {
            if(e.key === 'Escape') cerrarModal();
        });

        const modalAmbiente = document.getElementById('modalAmbiente');
        if(modalAmbiente) {
            modalAmbiente.addEventListener('click', function(e) {
                if(e.target === this) cerrarModal();
            });
        }
    </script>
</body>
</html>
