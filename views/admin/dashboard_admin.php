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
    // Sede no encontrada
    header("Location: index_sedes.php");
    exit();
}

// ⭐ ESTADÍSTICAS FILTRADAS POR SEDE
$pisos = mysqli_fetch_assoc(mysqli_query($con,"SELECT COUNT(*) total FROM pisos WHERE sede_id = $sede_id"))['total'];

$ambientes = mysqli_fetch_assoc(mysqli_query($con,"
    SELECT COUNT(*) total 
    FROM ambientes a
    JOIN pisos p ON a.piso_id = p.id
    WHERE p.sede_id = $sede_id
"))['total'];

$ocupados = mysqli_fetch_assoc(mysqli_query($con,"
    SELECT COUNT(*) total 
    FROM ambientes a
    JOIN pisos p ON a.piso_id = p.id
    WHERE a.estado='ocupado' AND p.sede_id = $sede_id
"))['total'];

$celadores = mysqli_fetch_assoc(mysqli_query($con,"
    SELECT COUNT(*) total 
    FROM usuarios 
    WHERE rol='celador' AND estado='activo' AND sede_id = $sede_id
"))['total'];

$desocupados = $ambientes - $ocupados;

$porcentajeOcupados = 0;
if($ambientes > 0){
    $porcentajeOcupados = ($ocupados / $ambientes) * 100;
}
$porcentajeDisponibles = 100 - $porcentajeOcupados;

$ocupaciones_activas = mysqli_fetch_assoc(mysqli_query($con,"
    SELECT COUNT(*) total 
    FROM ambientes a
    JOIN pisos p ON a.piso_id = p.id
    WHERE a.estado='ocupado' AND p.sede_id = $sede_id
"))['total'];

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo $sede_nombre; ?> - SAGA</title>
    <link rel="stylesheet" href="../../assets/css/dashboard_admin.css?v=20261013">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body>
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

    <?php $pagina_actual = basename($_SERVER['PHP_SELF']); ?>
        <?php include __DIR__ . '/_sidebar.php'; ?>

    <div class="encabezado">
        <h1>Panel de Control - <?php echo $sede_nombre; ?></h1>
        <p>Vista general del sistema SAGA en tiempo real</p>
    </div>

    <div class="contenedor">
        <div class="cards">
            <div class="card">
                <p>Pisos</p>
                <div class="icono">
                    <i class="bi bi-house"></i>
                </div>
                <h2><?php echo $pisos ?></h2>
            </div>

            <div class="card">
                <p>Ambientes</p>
                <div class="icono">
                    <i class="bi bi-grid-3x3-gap"></i>
                </div>
                <h2><?php echo $ambientes ?></h2>
            </div>

            <div class="card">
                <p>Ocupaciones activas</p>
                <div class="icono">
                    <i class="bi bi-bar-chart-fill"></i>
                </div>
                <h2><?php echo $ocupaciones_activas ?></h2>
            </div>

            <div class="card">
                <p>Celadores</p>
                <div class="icono">
                    <i class="bi bi-person-fill"></i>
                </div>
                <h2><?php echo $celadores ?></h2>
            </div>
        </div>

        <div class="grafico">
            <div class="card-header">
                <h3>Estado de Ambientes</h3>
                <p>Ocupación actual del sistema</p>
            </div>

            <div class="card-body">
                <div class="circle-wrap">
                    <div class="circle">
                        <div class="mask full">
                            <div class="fill"></div>
                        </div>
                        <div class="mask half">
                            <div class="fill"></div>
                        </div>
                        <div class="inside-circle">
                            <span class="porcentaje">
                                <?php echo round($porcentajeOcupados) ?>%
                            </span>
                            <p>ocupado</p>
                        </div>
                    </div>
                </div>
                <div class="leyenda">
                    <div class="item">
                        <span class="color libre"></span>
                        Disponibles
                        <b><?php echo $desocupados ?></b>
                    </div>
                    <div class="item">
                        <span class="color ocupado"></span>
                        Ocupados
                        <b><?php echo $ocupados ?></b>
                    </div>
                    <div class="item">
                        <span>
                            <span class="color total"></span>
                            Total
                        </span>
                        <b><?php echo $ambientes ?></b>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="acciones-rapidas">
        <h2 class="titulo">Acciones Rápidas</h2>
        <div class="acciones">
            <div class="accion" id="abrirModalPiso">
                <i class="bi bi-building-add"></i>
                <p>Agregar nuevo Piso</p>
            </div>
            <div class="accion" id="abrirModalAmbiente">
                <i class="bi bi-door-open-fill"></i>
                <p>Agregar nuevo Ambiente</p>
            </div>
            <div class="accion" id="abrirModal">
                <i class="bi bi-person-plus-fill"></i>
                <p>Agregar nuevo Celador</p>
            </div>
            <div class="accion" id="abrirModalInstructor">
                <i class="bi bi-person-plus-fill"></i>
                <p>Agregar nuevo Instructor</p>
            </div>
            <div class="accion" id="abrirModalVocero">
                <i class="bi bi-megaphone-fill"></i>
                <p>Agregar nuevo Vocero</p>
            </div>
        </div>
    </div>

    <!-- nuevo Instructor -->
    <div class="modal" id="modalInstructor">
        <div class="modal-content">
            <span class="cerrar" id="cerrarModalInstructor"><i class="bi bi-x-lg"></i></span>
            <h2>Registrar Instructor</h2>
            <form action="../../controllers/CrearInstructor.php" method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="sede_id" value="<?php echo $sede_id; ?>">

                <label>Cédula *</label>
                <input type="text" name="cedula" required>

                <label>Nombre completo *</label>
                <input type="text" name="nombre" required placeholder="Ej: Juan Pérez García">


                <!-- ⭐ NUEVO CAMPO EMAIL -->
                <label>Email *</label>
                <input type="email" name="email" required placeholder="ejemplo@correo.com">
                
                <label>Teléfono / Celular</label>
                <input type="tel" name="telefono" placeholder="Ej: 3001234567">

                <label>Nivel de Estudio *</label>
                <select name="nivel_estudio" required>
                    <option value="">Seleccione...</option>
                    <option value="tecnico">Técnico</option>
                    <option value="tecnologo">Tecnólogo</option>
                    <option value="profesional">Profesional</option>
                    <option value="especializacion">Especialización</option>
                    <option value="maestria">Maestría</option>
                    <option value="doctorado">Doctorado</option>
                </select>

                <label>Contraseña *</label>
                <input type="password" name="contrasena" required minlength="6">

                <label>Tipo de Contrato *</label>
                <select name="tipo_contrato" id="tipoContratoInstructor" required onchange="toggleFechasInstructor()">
                    <option value="">Seleccione...</option>
                    <option value="planta">Planta (Indefinido)</option>
                    <option value="contratista">Contratista (Temporal)</option>
                </select>
                
                <div id="fechasContratoInstructor" style="display: none;">
                    <label>Fecha de Inicio *</label>
                    <input type="date" name="fecha_inicio_contrato" id="fechaInicioInstructor">
                    <label>Fecha de Fin *</label>
                    <input type="date" name="fecha_fin_contrato" id="fechaFinInstructor">
                </div>
                
                <button type="submit">Guardar</button>
            </form>
        </div>
    </div>

    <!-- nuevo celador -->
    <div class="modal" id="modalCelador">
        <div class="modal-content">
            <span class="cerrar" id="cerrarModal"><i class="bi bi-x-lg"></i></span>
            <h2>Registrar Celador</h2>
            <form action="../../controllers/CrearCelador.php" method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="sede_id" value="<?php echo $sede_id; ?>">

                <label>Cédula *</label>
                <input type="text" name="cedula" required>
                
                <label>Nombre completo *</label>
                <input type="text" name="nombre" required placeholder="Ej: Juan Pérez García">
                
                
                <!-- ⭐ NUEVO CAMPO EMAIL -->
                <label>Email *</label>
                <input type="email" name="email" required placeholder="ejemplo@correo.com">
                
                <label>Teléfono / Celular</label>
                <input type="tel" name="telefono" placeholder="Ej: 3001234567">

                <label>Contraseña *</label>
                <input type="password" name="contrasena" required minlength="6">

                <label>Tipo de Contrato *</label>
                <select name="tipo_contrato" id="tipoContrato" required onchange="toggleFechas()">
                    <option value="">Seleccione...</option>
                    <option value="planta">Planta (Indefinido)</option>
                    <option value="contratista">Contratista (Temporal)</option>
                </select>
                
                <div id="fechasContrato" style="display: none;">
                    <label>Fecha de Inicio *</label>
                    <input type="date" name="fecha_inicio_contrato" id="fechaInicio">
                    <label>Fecha de Fin *</label>
                    <input type="date" name="fecha_fin_contrato" id="fechaFin">
                </div>
                
                <button type="submit">Guardar</button>
            </form>
        </div>
    </div>

    <!-- nuevo ambiente -->
    <div class="modal" id="modalAmbiente">
        <div class="modal-content">
            <span class="cerrar" id="cerrarModalAmbiente"><i class="bi bi-x-lg"></i></span>
            <h2>Registrar Ambiente</h2>
            <form action="../../controllers/CrearAmbiente.php" method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="sede_id" value="<?php echo $sede_id; ?>">

                <label>Nombre del ambiente</label>
                <input type="text" name="nombre" required>
                <label>Descripcion</label>
                <textarea name="descripcion" rows="3" required></textarea>
                <label>Piso</label>
                <select name="piso_id" required>
                    <?php
                    // ⭐ FILTRAR PISOS POR SEDE
                    $pisosQuery = mysqli_query($con,"SELECT * FROM pisos WHERE sede_id = $sede_id ORDER BY nombre");
                    while($p = mysqli_fetch_assoc($pisosQuery)){
                        echo "<option value='".$p['id']."'>".$p['nombre']."</option>";
                    }
                    ?>
                </select>
                <label>Estado</label>
                <select name="estado">
                    <option value="disponible">Disponible</option>
                    <option value="ocupado">Ocupado</option>
                </select>
                <button type="submit">Guardar</button>
            </form>
        </div>
    </div>

    <!-- nuevo vocero -->
    <div class="modal" id="modalVocero">
        <div class="modal-content">
            <span class="cerrar" id="cerrarModalVocero"><i class="bi bi-x-lg"></i></span>
            <h2>Registrar Vocero</h2>
            <form action="../../controllers/CrearVocero.php" method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="accion" value="crear">
                <input type="hidden" name="sede_id" value="<?php echo $sede_id; ?>">

                <label>Cédula *</label>
                <input type="text" name="cedula" required>

                <label>Nombre completo *</label>
                <input type="text" name="nombre" required placeholder="Ej: Juan Pérez García">


                <label>Email *</label>
                <input type="email" name="email" required placeholder="ejemplo@correo.com">

                <label>Teléfono / Celular</label>
                <input type="tel" name="telefono" placeholder="Ej: 3001234567">

                <label>Contraseña *</label>
                <input type="password" name="contrasena" required minlength="6">

                <label>Programa de Estudio *</label>
                <input type="text" name="programa_estudio" placeholder="Ej: Análisis y Desarrollo de Software" required>

                <label>Nivel de Estudio *</label>
                <select name="nivel_estudio" id="nivelVoceroDash" required onchange="toggleFechasVocero()">
                    <option value="">Seleccione...</option>
                    <option value="tecnico">Técnico</option>
                    <option value="tecnologo">Tecnólogo</option>
                    <option value="complementario">Complementario</option>
                </select>

                <div id="fechasVoceroDash" style="display:none;">
                    <label>Fecha de Inicio *</label>
                    <input type="date" name="fecha_inicio_contrato" id="fechaInicioVoceroDash" onchange="autoFechaFinVocero()">
                    <label>Fecha de Fin *</label>
                    <input type="date" name="fecha_fin_contrato" id="fechaFinVoceroDash">
                    <small id="ayudaFechaFinVocero" style="color:#666;display:none;"></small>
                </div>

                <button type="submit">Guardar</button>
            </form>
        </div>
    </div>

    <!-- nuevo piso -->
    <div class="modal" id="modalPiso">
        <div class="modal-content">
            <span class="cerrar" id="cerrarModalPiso"><i class="bi bi-x-lg"></i></span>
            <h2>Registrar Nuevo Piso</h2>
            <form action="../../controllers/CrearPiso.php" method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="sede_id" value="<?php echo $sede_id; ?>">

                <label>Nombre del piso</label>
                <input type="text" name="nombre" required>
                <label>Descripción</label>
                <textarea name="descripcion" rows="3" required></textarea>
                <button type="submit">Guardar</button>
            </form>
        </div>
    </div>

    <script>
    let porcentaje = <?php echo round($porcentajeOcupados) ?>;
    document.querySelector(".circle").style.setProperty("--pct", porcentaje);

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

    function enlazarModal(triggerId, modalId, closeId) {
        const trigger = document.getElementById(triggerId);
        const modalElement = document.getElementById(modalId);
        const close = document.getElementById(closeId);

        if(!trigger || !modalElement || !close) return;

        trigger.onclick = function(){
            modalElement.style.display = "flex";
        };

        close.onclick = function(){
            modalElement.style.display = "none";
        };

        window.addEventListener("click", function(event){
            if(event.target === modalElement){
                modalElement.style.display = "none";
            }
        });
    }

    enlazarModal("abrirModal", "modalCelador", "cerrarModal");
    enlazarModal("abrirModalAmbiente", "modalAmbiente", "cerrarModalAmbiente");
    enlazarModal("abrirModalPiso", "modalPiso", "cerrarModalPiso");

    function toggleFechas() {
        const tipoContrato = document.getElementById('tipoContrato').value;
        const fechasDiv = document.getElementById('fechasContrato');
        const fechaInicio = document.getElementById('fechaInicio');
        const fechaFin = document.getElementById('fechaFin');
    
        if(tipoContrato === 'contratista') {
            fechasDiv.style.display = 'block';
            fechaInicio.required = true;
            fechaFin.required = true;
        } else {
            fechasDiv.style.display = 'none';
            fechaInicio.required = false;
            fechaFin.required = false;
            fechaInicio.value = '';
            fechaFin.value = '';
        }
    }

    function toggleFechasInstructor() {
        const tipoContrato = document.getElementById('tipoContratoInstructor').value;
        const fechasDiv = document.getElementById('fechasContratoInstructor');
        const fechaInicio = document.getElementById('fechaInicioInstructor');
        const fechaFin = document.getElementById('fechaFinInstructor');
 
        if(tipoContrato === 'contratista') {
            fechasDiv.style.display = 'block';
            fechaInicio.required = true;
            fechaFin.required = true;
        } else {
            fechasDiv.style.display = 'none';
            fechaInicio.required = false;
            fechaFin.required = false;
            fechaInicio.value = '';
            fechaFin.value = '';
        }
    }

    enlazarModal("abrirModalInstructor", "modalInstructor", "cerrarModalInstructor");
    enlazarModal("abrirModalVocero", "modalVocero", "cerrarModalVocero");

    function toggleFechasVocero() {
        const nivel     = document.getElementById('nivelVoceroDash').value;
        const fechasDiv = document.getElementById('fechasVoceroDash');
        const inputFin  = document.getElementById('fechaFinVoceroDash');
        const ayuda     = document.getElementById('ayudaFechaFinVocero');

        if(!nivel) {
            fechasDiv.style.display = 'none';
            return;
        }
        fechasDiv.style.display = 'block';
        document.getElementById('fechaInicioVoceroDash').required = true;
        inputFin.required = true;

        if(nivel === 'tecnico') {
            inputFin.readOnly = true;
            inputFin.style.background = '#f0f0f0';
            ayuda.textContent = 'Se calcula automáticamente: 1 año';
            ayuda.style.display = 'block';
        } else if(nivel === 'tecnologo') {
            inputFin.readOnly = true;
            inputFin.style.background = '#f0f0f0';
            ayuda.textContent = 'Se calcula automáticamente: 2 años';
            ayuda.style.display = 'block';
        } else {
            inputFin.readOnly = false;
            inputFin.style.background = '';
            ayuda.style.display = 'none';
        }
        autoFechaFinVocero();
    }

    function autoFechaFinVocero() {
        const nivel  = document.getElementById('nivelVoceroDash').value;
        const inicio = document.getElementById('fechaInicioVoceroDash').value;
        if(!inicio) return;
        const fecha = new Date(inicio + 'T00:00:00');
        if(nivel === 'tecnico')   { fecha.setFullYear(fecha.getFullYear() + 1); document.getElementById('fechaFinVoceroDash').value = fecha.toISOString().slice(0,10); }
        if(nivel === 'tecnologo') { fecha.setFullYear(fecha.getFullYear() + 2); document.getElementById('fechaFinVoceroDash').value = fecha.toISOString().slice(0,10); }
    }
    </script>
</body>
</html>
