<?php
session_start();

if(!isset($_SESSION['id']) || $_SESSION['rol'] != 'admin') {
    header("Location: ../../views/home.php");
    exit();
}

require_once "../../config/conexion.php";
require_once "../../config/csrf.php";
$con = conexion();

$sede_id = isset($_GET['sede_id']) ? intval($_GET['sede_id']) : 0;
if($sede_id <= 0) {
    header("Location: index_sedes.php");
    exit();
}

$sede_query = mysqli_query($con, "SELECT nombre, ciudad FROM sedes WHERE id = $sede_id");
if(mysqli_num_rows($sede_query) > 0) {
    $sede_info   = mysqli_fetch_assoc($sede_query);
    $sede_nombre = $sede_info['nombre'];
    $sede_ciudad = $sede_info['ciudad'];
} else {
    header("Location: index_sedes.php");
    exit();
}

$msg      = $_GET['msg']      ?? '';
$error    = $_GET['error']    ?? '';
$creados  = intval($_GET['creados']  ?? 0);
$fallidos = intval($_GET['fallidos'] ?? 0);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Carga Masiva de Ocupaciones - <?php echo htmlspecialchars($sede_nombre); ?></title>
    <link rel="stylesheet" href="../../assets/css/carga_masiva.css?v=20261013">
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
                <span class="usuario-nombre"><?php echo htmlspecialchars($_SESSION['nombre']); ?></span>
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
            <h1><i class="bi bi-calendar-plus"></i> Carga Masiva de Ocupaciones</h1>
            <p><?php echo htmlspecialchars($sede_nombre); ?> - Registra múltiples ocupaciones desde un archivo Excel</p>
        </div>
        <div class="encabezado-botones">
            <a href="ocupaciones_admin.php?sede_id=<?php echo $sede_id; ?>" class="btn-volver">
                ← Volver a Ocupaciones
            </a>
        </div>
    </div>

    <!-- Mensajes -->
    <?php if($msg == 'carga_exitosa'): ?>
        <div class="mensaje-alert mensaje-exito" id="mensajeAlert">
            <i class="bi bi-check-circle-fill"></i> Carga completada: <?php echo $creados; ?> ocupaciones registradas correctamente
            <?php if($fallidos > 0): ?>
                | <i class="bi bi-exclamation-triangle-fill"></i> <?php echo $fallidos; ?> registros omitidos (conflictos o datos inválidos)
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if($error): ?>
        <div class="mensaje-alert mensaje-error" id="mensajeAlert">
            <?php
            if($error == 'archivo_vacio')      echo '<i class="bi bi-exclamation-triangle-fill"></i> El archivo está vacío o no tiene datos';
            if($error == 'formato_invalido')   echo '<i class="bi bi-exclamation-triangle-fill"></i> El archivo debe ser .xlsx, .xls o .csv';
            if($error == 'sin_archivo')        echo '<i class="bi bi-exclamation-triangle-fill"></i> No se ha seleccionado ningún archivo';
            if($error == 'columnas_faltantes') echo '<i class="bi bi-exclamation-triangle-fill"></i> El archivo no tiene todas las columnas requeridas: piso, ambiente, cedula_instructor, fecha, jornada';
            if($error == 'sin_sede')           echo '<i class="bi bi-exclamation-triangle-fill"></i> Sede no válida';
            if($error == 'error_lectura') {
                echo '<i class="bi bi-x-circle-fill"></i> Error al leer el archivo. Verifica el formato';
                if(isset($_GET['detalle'])) {
                    echo '<br><small>Detalle: ' . htmlspecialchars($_GET['detalle']) . '</small>';
                }
            }
            ?>
        </div>
    <?php endif; ?>

    <!-- Contenido principal -->
    <div class="instrucciones-container">

        <!-- Instrucciones -->
        <div class="instrucciones-card">
            <h2><i class="bi bi-clipboard"></i> Instrucciones de Uso</h2>

            <div class="pasos">
                <div class="paso">
                    <div class="paso-numero">1</div>
                    <div class="paso-contenido">
                        <h3>Descarga la plantilla</h3>
                        <p>Descarga el archivo de ejemplo con el formato correcto</p>
                        <a href="../../controllers/DescargarPlantillaOcupaciones.php?sede_id=<?php echo $sede_id; ?>" class="btn-descargar">
                            <i class="bi bi-download"></i> Descargar Plantilla Excel
                        </a>
                    </div>
                </div>

                <div class="paso">
                    <div class="paso-numero">2</div>
                    <div class="paso-contenido">
                        <h3>Completa la información</h3>
                        <p>Llena el archivo con los datos de las ocupaciones</p>
                        <table class="columnas-tabla">
                            <thead>
                                <tr>
                                    <th>Columna</th>
                                    <th>Descripción</th>
                                    <th>Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><code>piso</code></td>
                                    <td>Nombre exacto del piso (ej: Piso 1)</td>
                                    <td><span class="badge-oblig">Obligatorio</span></td>
                                </tr>
                                <tr>
                                    <td><code>ambiente</code></td>
                                    <td>Nombre exacto del ambiente (ej: Aula 101)</td>
                                    <td><span class="badge-oblig">Obligatorio</span></td>
                                </tr>
                                <tr>
                                    <td><code>cedula_instructor</code></td>
                                    <td>Cédula del instructor registrado en el sistema</td>
                                    <td><span class="badge-oblig">Obligatorio</span></td>
                                </tr>
                                <tr>
                                    <td><code>fecha</code></td>
                                    <td>Fecha en formato YYYY-MM-DD (ej: 2026-05-15)</td>
                                    <td><span class="badge-oblig">Obligatorio</span></td>
                                </tr>
                                <tr>
                                    <td><code>jornada</code></td>
                                    <td>mañana / tarde / noche / personalizado</td>
                                    <td><span class="badge-oblig">Obligatorio</span></td>
                                </tr>
                                <tr>
                                    <td><code>hora_inicio</code></td>
                                    <td>Hora inicio HH:MM — solo si jornada=personalizado</td>
                                    <td><span class="badge-cond">Condicional</span></td>
                                </tr>
                                <tr>
                                    <td><code>hora_fin</code></td>
                                    <td>Hora fin HH:MM — solo si jornada=personalizado</td>
                                    <td><span class="badge-cond">Condicional</span></td>
                                </tr>
                                <tr>
                                    <td><code>observaciones</code></td>
                                    <td>Notas adicionales sobre la ocupación</td>
                                    <td><span class="badge-opt">Opcional</span></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="paso">
                    <div class="paso-numero">3</div>
                    <div class="paso-contenido">
                        <h3>Sube el archivo</h3>
                        <p>Selecciona el archivo completado y haz clic en "Procesar Archivo"</p>
                    </div>
                </div>
            </div>

            <div class="ejemplo-visual">
                <h3><i class="bi bi-lightbulb"></i> Ejemplo: Registrar ocupaciones de una semana</h3>
                <div class="ejemplo-code">
                    <pre>piso      | ambiente     | cedula_instructor | fecha      | jornada       | hora_inicio | hora_fin | observaciones
Piso 1    | Aula 101     | 12345678          | 2026-05-05 | mañana        |             |          | Formación técnica
Piso 1    | Aula 102     | 87654321          | 2026-05-05 | tarde         |             |          |
Piso 2    | Lab Química  | 11223344          | 2026-05-06 | noche         |             |          | Práctica lab
Piso 2    | Sala Cómputo | 55667788          | 2026-05-07 | personalizado | 08:00       | 11:00    | Clase especial</pre>
                </div>
                <p class="ejemplo-nota"><i class="bi bi-check-circle-fill"></i> Las jornadas predefinidas asignan horarios automáticamente: mañana (06-12), tarde (12-18), noche (18-22)</p>
            </div>

            <div class="notas-importantes" style="margin-top:16px;">
                <h4><i class="bi bi-exclamation-triangle-fill"></i> Registros que se omiten automáticamente:</h4>
                <ul>
                    <li>El piso o ambiente no existe en esta sede</li>
                    <li>La cédula del instructor no está registrada o el instructor está inactivo</li>
                    <li>La fecha es anterior a hoy</li>
                    <li>El ambiente ya tiene otra ocupación activa en ese horario</li>
                    <li>El instructor ya tiene otra ocupación activa en ese horario</li>
                    <li>Jornada "personalizado" sin hora_inicio / hora_fin válidas</li>
                </ul>
            </div>
        </div>

        <!-- Formulario de carga -->
        <div class="upload-card">
            <h2><i class="bi bi-cloud-upload"></i> Subir Archivo</h2>

            <form action="../../controllers/ProcesarCargaOcupaciones.php" method="POST" enctype="multipart/form-data" id="formCarga">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="sede_id" value="<?php echo $sede_id; ?>">

                <div class="upload-area" id="uploadArea">
                    <div class="upload-icon"><i class="bi bi-folder2-open"></i></div>
                    <h3>Arrastra tu archivo aquí</h3>
                    <p>o haz clic para seleccionar</p>
                    <p class="upload-formatos">Formatos: .xlsx, .xls, .csv</p>
                    <input type="file" name="archivo" id="archivoInput" accept=".xlsx,.xls,.csv" required hidden>
                </div>

                <div class="archivo-seleccionado" id="archivoSeleccionado" style="display:none;">
                    <div class="archivo-info">
                        <div class="archivo-icono"><i class="bi bi-file-earmark-text"></i></div>
                        <div class="archivo-detalles">
                            <div class="archivo-nombre" id="archivoNombre"></div>
                            <div class="archivo-size"   id="archivoSize"></div>
                        </div>
                        <button type="button" class="btn-quitar" onclick="quitarArchivo()">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                </div>

                <div class="form-botones">
                    <button type="submit" class="btn-procesar" id="btnProcesar" disabled>
                        <i class="bi bi-gear-fill"></i> Procesar Archivo
                    </button>
                </div>
            </form>

            <div class="notas-importantes">
                <h4><i class="bi bi-info-circle-fill"></i> Límites:</h4>
                <ul>
                    <li>El archivo no debe exceder 5 MB</li>
                    <li>Máximo 200 ocupaciones por carga</li>
                </ul>
            </div>
        </div>
    </div>

    <script>
        const btnMenu    = document.getElementById('btnMenu');
        const modalMenu  = document.getElementById('modalMenu');
        const uploadArea = document.getElementById('uploadArea');
        const archivoInput        = document.getElementById('archivoInput');
        const archivoSeleccionado = document.getElementById('archivoSeleccionado');
        const btnProcesar         = document.getElementById('btnProcesar');

        btnMenu.addEventListener('click', () => modalMenu.classList.add('active'));
        modalMenu.addEventListener('click', (e) => {
            if(e.target === modalMenu) modalMenu.classList.remove('active');
        });

        uploadArea.addEventListener('click', () => archivoInput.click());

        uploadArea.addEventListener('dragover', (e) => {
            e.preventDefault();
            uploadArea.classList.add('dragover');
        });
        uploadArea.addEventListener('dragleave', () => {
            uploadArea.classList.remove('dragover');
        });
        uploadArea.addEventListener('drop', (e) => {
            e.preventDefault();
            uploadArea.classList.remove('dragover');
            const files = e.dataTransfer.files;
            if(files.length > 0) {
                archivoInput.files = files;
                mostrarArchivo(files[0]);
            }
        });

        archivoInput.addEventListener('change', (e) => {
            if(e.target.files.length > 0) mostrarArchivo(e.target.files[0]);
        });

        function mostrarArchivo(file) {
            const validExtensions = ['.xlsx', '.xls', '.csv'];
            const isValid = validExtensions.some(ext => file.name.toLowerCase().endsWith(ext));
            if(!isValid) {
                alert('Formato no válido. Solo se permiten archivos .xlsx, .xls o .csv');
                archivoInput.value = '';
                return;
            }
            if(file.size > 5 * 1024 * 1024) {
                alert('El archivo excede el tamaño máximo de 5 MB');
                archivoInput.value = '';
                return;
            }
            document.getElementById('archivoNombre').textContent = file.name;
            document.getElementById('archivoSize').textContent   = formatBytes(file.size);
            uploadArea.style.display          = 'none';
            archivoSeleccionado.style.display = 'block';
            btnProcesar.disabled = false;
        }

        function quitarArchivo() {
            archivoInput.value = '';
            uploadArea.style.display          = 'flex';
            archivoSeleccionado.style.display = 'none';
            btnProcesar.disabled = true;
        }

        function formatBytes(bytes) {
            if(bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
        }

        setTimeout(() => {
            const alert = document.getElementById('mensajeAlert');
            if(alert) alert.style.display = 'none';
        }, 10000);
    </script>
</body>
</html>
