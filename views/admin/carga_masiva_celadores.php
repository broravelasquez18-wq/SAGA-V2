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
<title>Carga Masiva de Celadores - <?php echo htmlspecialchars($sede_nombre); ?></title>
    <link rel="stylesheet" href="../../assets/css/carga_masiva.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body>
    <!-- Header -->
    <div class="header">
        <div class="logo">
            <button class="btn-menu" id="btnMenu"><i class="bi bi-list"></i></button>
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
            <h1><i class="bi bi-shield-plus"></i> Carga Masiva de Celadores</h1>
            <p><?php echo htmlspecialchars($sede_nombre); ?> - Registra múltiples celadores desde un archivo Excel</p>
        </div>
        <div class="encabezado-botones">
            <a href="Celadores_admin.php?sede_id=<?php echo $sede_id; ?>" class="btn-volver">
                ← Volver a Celadores
            </a>
        </div>
    </div>

    <!-- Mensajes -->
    <?php if($msg == 'carga_exitosa'): ?>
        <div class="mensaje-alert mensaje-exito" id="mensajeAlert">
            <i class="bi bi-check-circle-fill"></i> Carga completada: <?php echo $creados; ?> celador<?php echo $creados != 1 ? 'es' : ''; ?> registrado<?php echo $creados != 1 ? 's' : ''; ?> correctamente
            <?php if($fallidos > 0): ?>
                | <i class="bi bi-exclamation-triangle-fill"></i> <?php echo $fallidos; ?> registros omitidos (datos inválidos o duplicados)
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if($error): ?>
        <div class="mensaje-alert mensaje-error" id="mensajeAlert">
            <?php
            if($error == 'archivo_vacio')      echo '<i class="bi bi-exclamation-triangle-fill"></i> El archivo está vacío o no tiene datos';
            if($error == 'formato_invalido')   echo '<i class="bi bi-exclamation-triangle-fill"></i> El archivo debe ser .xlsx, .xls o .csv';
            if($error == 'sin_archivo')        echo '<i class="bi bi-exclamation-triangle-fill"></i> No se ha seleccionado ningún archivo';
            if($error == 'columnas_faltantes') echo '<i class="bi bi-exclamation-triangle-fill"></i> El archivo no tiene todas las columnas requeridas: cedula, nombre, apellido, email, contraseña, tipo_contrato';
            if($error == 'sin_sede')           echo '<i class="bi bi-exclamation-triangle-fill"></i> Sede no válida';
            if($error == 'error_lectura') {
                echo '<i class="bi bi-x-circle-fill"></i> Error al leer el archivo. Verifica el formato';
                if(isset($_GET['detalle'])) echo '<br><small>Detalle: ' . htmlspecialchars($_GET['detalle']) . '</small>';
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
                        <a href="../../controllers/DescargarPlantillaCeladores.php?sede_id=<?php echo $sede_id; ?>" class="btn-descargar">
                            <i class="bi bi-download"></i> Descargar Plantilla Excel
                        </a>
                    </div>
                </div>

                <div class="paso">
                    <div class="paso-numero">2</div>
                    <div class="paso-contenido">
                        <h3>Completa la información</h3>
                        <p>Llena el archivo con los datos de los celadores</p>
                        <table class="columnas-tabla">
                            <thead>
                                <tr><th>Columna</th><th>Descripción</th><th>Estado</th></tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><code>cedula</code></td>
                                    <td>Número de cédula (único en el sistema)</td>
                                    <td><span class="badge-oblig">Obligatorio</span></td>
                                </tr>
                                <tr>
                                    <td><code>nombre</code></td>
                                    <td>Nombres del celador</td>
                                    <td><span class="badge-oblig">Obligatorio</span></td>
                                </tr>
                                <tr>
                                    <td><code>apellido</code></td>
                                    <td>Apellidos del celador</td>
                                    <td><span class="badge-oblig">Obligatorio</span></td>
                                </tr>
                                <tr>
                                    <td><code>email</code></td>
                                    <td>Correo electrónico (único en el sistema)</td>
                                    <td><span class="badge-oblig">Obligatorio</span></td>
                                </tr>
                                <tr>
                                    <td><code>contraseña</code></td>
                                    <td>Contraseña de acceso al sistema</td>
                                    <td><span class="badge-oblig">Obligatorio</span></td>
                                </tr>
                                <tr>
                                    <td><code>tipo_contrato</code></td>
                                    <td>planta / contratista</td>
                                    <td><span class="badge-oblig">Obligatorio</span></td>
                                </tr>
                                <tr>
                                    <td><code>fecha_inicio_contrato</code></td>
                                    <td>Fecha inicio YYYY-MM-DD — solo si tipo_contrato=contratista</td>
                                    <td><span class="badge-cond">Condicional</span></td>
                                </tr>
                                <tr>
                                    <td><code>fecha_fin_contrato</code></td>
                                    <td>Fecha fin YYYY-MM-DD — solo si tipo_contrato=contratista</td>
                                    <td><span class="badge-cond">Condicional</span></td>
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
                <h3><i class="bi bi-lightbulb"></i> Ejemplo de datos</h3>
                <div class="ejemplo-code">
                    <pre>cedula    | nombre  | apellido | email               | contraseña | tipo_contrato | fecha_inicio_contrato | fecha_fin_contrato
12345678  | Luis    | Ramírez  | lramirez@sena.edu.co| clave123   | planta        |                       |
87654321  | Ana     | Castro   | acastro@sena.edu.co | clave456   | contratista   | 2026-01-01            | 2026-12-31</pre>
                </div>
                <p class="ejemplo-nota"><i class="bi bi-info-circle-fill"></i> Los celadores de tipo <strong>planta</strong> quedan asignados a esta sede. Los <strong>contratistas</strong> tienen fechas de contrato obligatorias.</p>
            </div>

            <div class="notas-importantes" style="margin-top:16px;">
                <h4><i class="bi bi-exclamation-triangle-fill"></i> Registros que se omiten automáticamente:</h4>
                <ul>
                    <li>La cédula o el email ya están registrados en el sistema</li>
                    <li>Tipo de contrato no es "planta" ni "contratista"</li>
                    <li>Contratista sin fechas de contrato válidas</li>
                    <li>Campos obligatorios vacíos</li>
                </ul>
            </div>
        </div>

        <!-- Formulario de carga -->
        <div class="upload-card">
            <h2><i class="bi bi-cloud-upload"></i> Subir Archivo</h2>

            <form action="../../controllers/ProcesarCargaCeladores.php" method="POST" enctype="multipart/form-data" id="formCarga">
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
                    <li>Máximo 200 celadores por carga</li>
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
        modalMenu.addEventListener('click', (e) => { if(e.target === modalMenu) modalMenu.classList.remove('active'); });

        uploadArea.addEventListener('click', () => archivoInput.click());
        uploadArea.addEventListener('dragover', (e) => { e.preventDefault(); uploadArea.classList.add('dragover'); });
        uploadArea.addEventListener('dragleave', () => uploadArea.classList.remove('dragover'));
        uploadArea.addEventListener('drop', (e) => {
            e.preventDefault();
            uploadArea.classList.remove('dragover');
            const files = e.dataTransfer.files;
            if(files.length > 0) { archivoInput.files = files; mostrarArchivo(files[0]); }
        });
        archivoInput.addEventListener('change', (e) => { if(e.target.files.length > 0) mostrarArchivo(e.target.files[0]); });

        function mostrarArchivo(file) {
            const validExtensions = ['.xlsx', '.xls', '.csv'];
            if(!validExtensions.some(ext => file.name.toLowerCase().endsWith(ext))) {
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
            const k = 1024, sizes = ['Bytes', 'KB', 'MB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
        }

        setTimeout(() => { const a = document.getElementById('mensajeAlert'); if(a) a.style.display = 'none'; }, 10000);
    </script>
</body>
</html>
