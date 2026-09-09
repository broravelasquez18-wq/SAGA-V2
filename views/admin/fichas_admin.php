<?php
session_start();
if (!isset($_SESSION['id']) || $_SESSION['rol'] != 'admin') {
    header("Location: ../../views/home.php");
    exit();
}
require_once "../../config/conexion.php";
require_once "../../config/csrf.php";
$con = conexion();
mysqli_set_charset($con, 'utf8mb4');

$sede_id = isset($_GET['sede_id']) ? intval($_GET['sede_id']) : 0;
if ($sede_id <= 0) { header("Location: index_sedes.php"); exit(); }

$sq = mysqli_query($con, "SELECT nombre, ciudad FROM sedes WHERE id = $sede_id");
if (!$sq || mysqli_num_rows($sq) == 0) { header("Location: index_sedes.php"); exit(); }
$sede = mysqli_fetch_assoc($sq);
$sede_nombre = $sede['nombre'];

// Fichas de la sede con métricas
$fichas = [];
$fq = mysqli_query($con, "
    SELECT f.*, a.nombre AS ambiente_nombre,
       (SELECT COUNT(*) FROM historial_ocupacion h WHERE h.ficha_id=f.id AND h.origen='sync') AS n_ocup,
       (SELECT COUNT(*) FROM historial_ocupacion h WHERE h.ficha_id=f.id AND h.origen='sync' AND h.fecha_fin <= NOW()) AS n_dictadas,
       (SELECT COUNT(DISTINCT h.usuario_id) FROM historial_ocupacion h WHERE h.ficha_id=f.id AND h.origen='sync') AS n_inst_clases,
       (SELECT COUNT(*) FROM ficha_instructores fi WHERE fi.ficha_id=f.id) AS n_inst,
       (SELECT COUNT(*) FROM ficha_instructores fi WHERE fi.ficha_id=f.id AND fi.usuario_id IS NULL) AS n_sin
    FROM fichas f
    LEFT JOIN ambientes a ON f.ambiente_id = a.id
    WHERE f.sede_id = $sede_id
    ORDER BY f.codigo_ficha");
while ($row = mysqli_fetch_assoc($fq)) { $fichas[] = $row; }

// Mapeo de instructores por ficha
$mapeo = [];
if ($fichas) {
    $ids = implode(',', array_map(fn($f) => intval($f['id']), $fichas));
    $mq = mysqli_query($con, "
        SELECT fi.id, fi.ficha_id, fi.nombre_excel, fi.cedula, fi.usuario_id, fi.origen,
               CONCAT_WS(' ', u.nombre, u.apellido) AS usuario_nombre
        FROM ficha_instructores fi
        LEFT JOIN usuarios u ON fi.usuario_id = u.id
        WHERE fi.ficha_id IN ($ids)
        ORDER BY fi.nombre_excel");
    while ($row = mysqli_fetch_assoc($mq)) { $mapeo[$row['ficha_id']][] = $row; }
}

// Ambientes de la sede (para el formulario de nueva ficha)
$ambientes = [];
$aq = mysqli_query($con, "SELECT a.id, a.nombre, p.nombre AS piso
                          FROM ambientes a JOIN pisos p ON a.piso_id = p.id
                          WHERE p.sede_id = $sede_id ORDER BY p.nombre, a.nombre");
while ($row = mysqli_fetch_assoc($aq)) { $ambientes[] = $row; }

// Instructores del sistema (para el selector del mapeo). Se enlaza por cédula,
// así el admin elige de una lista en vez de teclear el número a ciegas.
$instructores = [];
$iq2 = mysqli_query($con, "SELECT cedula, CONCAT_WS(' ', nombre, apellido) AS nombre
                           FROM usuarios
                           WHERE rol = 'instructor' AND cedula IS NOT NULL AND cedula <> ''
                           ORDER BY nombre");
while ($row = mysqli_fetch_assoc($iq2)) { $instructores[] = $row; }

// Voceros del sistema (para asignar el vocero/a de cada ficha)
$voceros = [];
$vq2 = mysqli_query($con, "SELECT id, CONCAT_WS(' ', nombre, apellido) AS nombre, cedula
                           FROM usuarios WHERE rol = 'vocero' ORDER BY nombre");
while ($row = mysqli_fetch_assoc($vq2)) { $voceros[] = $row; }

// Estadísticas globales
$tot_fichas = count($fichas);
$tot_ocup = array_sum(array_map(fn($f) => (int)$f['n_ocup'], $fichas));
$tot_sin = array_sum(array_map(fn($f) => (int)$f['n_sin'], $fichas));
$ultima = null;
foreach ($fichas as $f) { if ($f['ultima_sync'] && (!$ultima || $f['ultima_sync'] > $ultima)) $ultima = $f['ultima_sync']; }

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$msg = $_GET['msg'] ?? ''; $error = $_GET['error'] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Fichas - <?php echo h($sede_nombre); ?></title>
    <link rel="stylesheet" href="../../assets/css/fichas_admin.css">
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
                <span class="usuario-nombre"><?php echo h($_SESSION['nombre']); ?></span>
                <span class="usuario-tipo">Administrador</span>
            </div>
            <a class="btn-logout" href="../../controllers/logout.php"><i class="bi bi-box-arrow-right"></i></a>
        </div>
    </div>

    <!-- Sidebar -->
    <?php $pagina_actual = basename($_SERVER['PHP_SELF']); ?>
        <?php include __DIR__ . '/_sidebar.php'; ?>

    <div class="wrap">
        <div class="encabezado" style="padding:24px 0 0;">
            <h1>Fichas de formación</h1>
            <p><?php echo h($sede_nombre); ?> — sincroniza el calendario del SENA desde Google Sheets</p>
        </div>

        <?php if ($msg === 'sync_ok'): ?>
            <div class="banner ok"><i class="bi bi-check-circle-fill"></i>
                Sincronización completada: <strong><?php echo intval($_GET['total'] ?? 0); ?></strong> ocupaciones actualizadas.
                <?php if (intval($_GET['sin'] ?? 0) > 0): ?> · <?php echo intval($_GET['sin']); ?> instructor(es) sin enlazar (revisa el mapeo).<?php endif; ?>
            </div>
        <?php elseif ($msg === 'ficha_creada'): ?>
            <div class="banner ok"><i class="bi bi-check-circle-fill"></i> Ficha registrada. Ahora pulsa "Sincronizar" para traer el calendario.</div>
        <?php elseif ($msg === 'instructor_enlazado'): ?>
            <div class="banner ok"><i class="bi bi-check-circle-fill"></i> Instructor enlazado. Vuelve a sincronizar para aplicar el cambio.</div>
        <?php elseif ($msg === 'ficha_editada'): ?>
            <div class="banner ok"><i class="bi bi-check-circle-fill"></i> Ficha actualizada. Si cambiaste el enlace, pulsa "Sincronizar" para traer el calendario.</div>
        <?php elseif ($msg === 'instructor_agregado'): ?>
            <div class="banner ok"><i class="bi bi-check-circle-fill"></i> Instructor agregado a la ficha.</div>
        <?php elseif ($msg === 'instructor_quitado'): ?>
            <div class="banner ok"><i class="bi bi-check-circle-fill"></i> Instructor quitado de la ficha.</div>
        <?php elseif ($msg === 'instructor_ya_en_ficha'): ?>
            <div class="banner ok"><i class="bi bi-info-circle-fill"></i> Ese instructor ya estaba en la ficha.</div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="banner err"><i class="bi bi-exclamation-triangle-fill"></i>
                <?php
                echo match($error) {
                    'sheet_privado'   => 'El Google Sheet no es accesible. Compártelo como "Cualquiera con el enlace puede ver".',
                    'sin_sheet'       => 'Esta ficha no tiene un Google Sheet configurado.',
                    'sync_fallo'      => 'No se pudo sincronizar. Revisa el enlace del Sheet e inténtalo de nuevo.',
                    'campos_vacios'   => 'Faltan datos obligatorios.',
                    'ambiente_invalido' => 'El ambiente seleccionado no es válido.',
                    'ficha_duplicada' => 'Ya existe una ficha con ese código.',
                    'cedula_no_existe'=> 'No hay ningún usuario con esa cédula. Créalo primero como instructor.',
                    'instructor_no_existe' => 'No hay ningún instructor registrado con esa cédula.',
                    'ficha_invalida'  => 'La ficha no es válida.',
                    'no_encontrada'   => 'No se encontró el registro.',
                    'registro_fallido'=> 'No se pudo guardar. Inténtalo de nuevo.',
                    default           => 'Ocurrió un error. Inténtalo de nuevo.',
                };
                ?>
            </div>
        <?php endif; ?>

        <div class="stats">
            <div class="stat"><div class="n"><?php echo $tot_fichas; ?></div><div class="l">Fichas</div></div>
            <div class="stat"><div class="n"><?php echo $tot_ocup; ?></div><div class="l">Ocupaciones sincronizadas</div></div>
            <div class="stat <?php echo $tot_sin>0?'alerta':''; ?>"><div class="n"><?php echo $tot_sin; ?></div><div class="l">Instructores sin enlazar</div></div>
            <div class="stat"><div class="n" style="font-size:18px;"><?php echo $ultima ? date('d/m/Y H:i', strtotime($ultima)) : '—'; ?></div><div class="l">Última sincronización</div></div>
        </div>

        <div class="barra">
            <h2 style="font-size:19px; color:var(--sena-carbon);">Listado de fichas</h2>
            <button class="btn btn-nueva" onclick="abrir('modalNueva')"><i class="bi bi-plus-lg"></i> Nueva ficha</button>
        </div>

        <div class="tabla-card">
            <?php if (!$fichas): ?>
                <div class="vacio">
                    <i class="bi bi-journal-x"></i>
                    <p style="margin-top:12px;">Aún no hay fichas registradas en esta sede.</p>
                    <p>Pulsa <strong>"Nueva ficha"</strong> para registrar la primera.</p>
                </div>
            <?php else: ?>
                <table class="fichas">
                    <thead>
                        <tr>
                            <th>Ficha</th><th>Programa</th><th>Ambiente</th><th>Enlace</th>
                            <th>Ocupaciones</th><th>Instructores</th><th>Última sync</th><th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($fichas as $f): ?>
                        <tr>
                            <td class="cod"><?php echo h($f['codigo_ficha']); ?></td>
                            <td><?php echo h($f['programa']); ?></td>
                            <td><?php echo $f['ambiente_nombre'] ? h($f['ambiente_nombre']) : '<span class="pill none">sin ambiente</span>'; ?></td>
                            <td>
                                <?php if (!empty($f['google_sheet_url'])): ?>
                                    <a class="pill ok" href="<?php echo h($f['google_sheet_url']); ?>" target="_blank" rel="noopener" title="Abrir el Google Sheet"><i class="bi bi-link-45deg"></i> ver</a>
                                <?php else: ?>
                                    <span class="pill warn">sin enlace</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="pill <?php echo $f['n_ocup']>0?'ok':'none'; ?>"><?php echo (int)$f['n_ocup']; ?></span></td>
                            <td>
                                <?php if ($f['n_sin'] > 0): ?>
                                    <span class="pill warn"><?php echo (int)$f['n_sin']; ?> sin enlazar</span>
                                <?php elseif ($f['n_inst'] > 0): ?>
                                    <span class="pill ok"><?php echo (int)$f['n_inst']; ?> ok</span>
                                <?php else: ?>
                                    <span class="pill none">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $f['ultima_sync'] ? date('d/m/Y H:i', strtotime($f['ultima_sync'])) : '<span class="pill none">nunca</span>'; ?></td>
                            <td>
                                <div class="acciones">
                                    <button class="btn btn-info-ficha" onclick="abrir('info<?php echo (int)$f['id']; ?>')"><i class="bi bi-bar-chart-line-fill"></i> Ver info</button>
                                    <form action="../../controllers/SincronizarFicha.php" method="POST" style="display:inline;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="sede_id" value="<?php echo $sede_id; ?>">
                                        <input type="hidden" name="ficha_id" value="<?php echo (int)$f['id']; ?>">
                                        <button type="submit" class="btn btn-sync"><i class="bi bi-arrow-repeat"></i> Sincronizar</button>
                                    </form>
                                    <button class="btn btn-sec" onclick="abrir('map<?php echo (int)$f['id']; ?>')"><i class="bi bi-people-fill"></i> Instructores</button>
                                    <button class="btn btn-sec" onclick="abrir('edit<?php echo (int)$f['id']; ?>')"><i class="bi bi-pencil-square"></i> Editar</button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal: Nueva ficha -->
    <div class="modal-bg" id="modalNueva">
        <div class="modal-box">
            <h3>Nueva ficha <span class="cerrar" onclick="cerrar('modalNueva')">&times;</span></h3>
            <form action="../../controllers/CrearFicha.php" method="POST" class="modal-body">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="sede_id" value="<?php echo $sede_id; ?>">
                <label>Código de ficha *</label>
                <input type="text" name="codigo_ficha" placeholder="Ej: 3142784" required>
                <label>Programa</label>
                <input type="text" name="programa" placeholder="Ej: Análisis y Desarrollo de Software">
                <label>Ambiente fijo *</label>
                <select name="ambiente_id" required>
                    <option value="">— Selecciona el ambiente —</option>
                    <?php foreach ($ambientes as $a): ?>
                        <option value="<?php echo (int)$a['id']; ?>"><?php echo h($a['nombre'].' ('.$a['piso'].')'); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (!$ambientes): ?>
                    <p class="hint" style="color:#b71c1c;">No hay ambientes en esta sede. Crea uno antes de registrar la ficha.</p>
                <?php endif; ?>
                <label>Enlace del Google Sheet</label>
                <input type="text" name="google_sheet_url" placeholder="https://docs.google.com/spreadsheets/d/...">
                <p class="hint">El documento debe estar compartido como "Cualquiera con el enlace puede ver".</p>
                <div style="margin-top:20px; display:flex; gap:10px; justify-content:flex-end;">
                    <button type="button" class="btn btn-sec" onclick="cerrar('modalNueva')">Cancelar</button>
                    <button type="submit" class="btn btn-nueva"><i class="bi bi-check-lg"></i> Registrar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modales: editar ficha (programa, ambiente, enlace) -->
    <?php foreach ($fichas as $f): ?>
    <div class="modal-bg" id="edit<?php echo (int)$f['id']; ?>">
        <div class="modal-box">
            <h3>Editar ficha <?php echo h($f['codigo_ficha']); ?> <span class="cerrar" onclick="cerrar('edit<?php echo (int)$f['id']; ?>')">&times;</span></h3>
            <form action="../../controllers/EditarFicha.php" method="POST" class="modal-body">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="sede_id" value="<?php echo $sede_id; ?>">
                <input type="hidden" name="ficha_id" value="<?php echo (int)$f['id']; ?>">
                <label>Código de ficha</label>
                <input type="text" value="<?php echo h($f['codigo_ficha']); ?>" disabled>
                <p class="hint">El código no se puede cambiar aquí.</p>
                <label>Programa</label>
                <input type="text" name="programa" value="<?php echo h($f['programa']); ?>" placeholder="Ej: Análisis y Desarrollo de Software">
                <label>Ambiente fijo *</label>
                <select name="ambiente_id" required>
                    <option value="">— Selecciona el ambiente —</option>
                    <?php foreach ($ambientes as $a): ?>
                        <option value="<?php echo (int)$a['id']; ?>" <?php echo ((int)$a['id'] === (int)$f['ambiente_id']) ? 'selected' : ''; ?>><?php echo h($a['nombre'].' ('.$a['piso'].')'); ?></option>
                    <?php endforeach; ?>
                </select>
                <label>Enlace del Google Sheet</label>
                <input type="text" name="google_sheet_url" value="<?php echo h($f['google_sheet_url']); ?>" placeholder="https://docs.google.com/spreadsheets/d/...">
                <p class="hint">El documento debe estar compartido como "Cualquiera con el enlace puede ver". Déjalo vacío para quitar el enlace.</p>
                <label>Vocero/a de la ficha</label>
                <select name="vocero_id">
                    <option value="">— Sin vocero asignado —</option>
                    <?php foreach ($voceros as $v): ?>
                        <option value="<?php echo (int)$v['id']; ?>" <?php echo ((int)$v['id'] === (int)($f['vocero_id'] ?? 0)) ? 'selected' : ''; ?>><?php echo h($v['nombre'] . ' — ' . $v['cedula']); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="hint"><?php echo $voceros ? 'Se le avisará por correo cuando se apruebe la cancelación de una clase de esta ficha.' : 'No hay voceros registrados. Crea uno en la sección Voceros para poder asignarlo.'; ?></p>
                <div style="margin-top:20px; display:flex; gap:10px; justify-content:flex-end;">
                    <button type="button" class="btn btn-sec" onclick="cerrar('edit<?php echo (int)$f['id']; ?>')">Cancelar</button>
                    <button type="submit" class="btn btn-nueva"><i class="bi bi-check-lg"></i> Guardar</button>
                </div>
            </form>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- Modales: mapeo de instructores por ficha -->
    <?php foreach ($fichas as $f): ?>
    <div class="modal-bg" id="map<?php echo (int)$f['id']; ?>">
        <div class="modal-box">
            <h3>Instructores · Ficha <?php echo h($f['codigo_ficha']); ?> <span class="cerrar" onclick="cerrar('map<?php echo (int)$f['id']; ?>')">&times;</span></h3>
            <div class="modal-body">
                <p class="hint" style="margin-bottom:14px;">Enlaza los nombres detectados del calendario, o <strong>agrega manualmente</strong> un instructor registrado. Los que queden sin enlazar no se importan.</p>

                <?php if (!empty($mapeo[$f['id']])): foreach ($mapeo[$f['id']] as $mi): ?>
                    <div class="map-row">
                        <span class="nom"><?php echo h($mi['nombre_excel']); ?><?php if (($mi['origen'] ?? 'sync') === 'manual'): ?> <span class="pill man">manual</span><?php endif; ?></span>
                        <?php if ($mi['usuario_id']): ?>
                            <div class="map-accion">
                            <span class="pill ok"><i class="bi bi-check-circle"></i> <?php echo h($mi['usuario_nombre']); ?></span>
                            <form action="../../controllers/QuitarInstructorFicha.php" method="POST" onsubmit="return confirm('¿Quitar este instructor de la ficha?')" style="display:inline;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="sede_id" value="<?php echo $sede_id; ?>">
                                <input type="hidden" name="fi_id" value="<?php echo (int)$mi['id']; ?>">
                                <button type="submit" class="btn btn-quitar" title="Quitar de la ficha"><i class="bi bi-x-lg"></i></button>
                            </form>
                            </div>
                        <?php else: ?>
                            <form action="../../controllers/MapearInstructorFicha.php" method="POST">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="sede_id" value="<?php echo $sede_id; ?>">
                                <input type="hidden" name="fi_id" value="<?php echo (int)$mi['id']; ?>">
                                <select name="cedula" class="sel-instructor" required>
                                    <option value="">— Selecciona el instructor —</option>
                                    <?php foreach ($instructores as $ins): ?>
                                        <option value="<?php echo h($ins['cedula']); ?>"><?php echo h($ins['nombre'] . ' — ' . $ins['cedula']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-sync">Enlazar</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; else: ?>
                    <p class="hint">Aún no hay instructores. Agrégalos abajo o pulsa "Sincronizar" para traerlos del calendario.</p>
                <?php endif; ?>

                <form action="../../controllers/AgregarInstructorFicha.php" method="POST" class="map-agregar">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="sede_id" value="<?php echo $sede_id; ?>">
                    <input type="hidden" name="ficha_id" value="<?php echo (int)$f['id']; ?>">
                    <select name="cedula" class="sel-instructor" required>
                        <option value="">— Agregar instructor registrado —</option>
                        <?php foreach ($instructores as $ins): ?>
                            <option value="<?php echo h($ins['cedula']); ?>"><?php echo h($ins['nombre'] . ' — ' . $ins['cedula']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-nueva"><i class="bi bi-plus-lg"></i> Agregar</button>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- Modales: análisis (Ver info) por ficha -->
    <?php foreach ($fichas as $f):
        $t_total    = (int)$f['n_ocup'];
        $t_dictadas = (int)$f['n_dictadas'];
        $t_faltan   = max(0, $t_total - $t_dictadas);
        $t_pct      = $t_total > 0 ? (int)round($t_dictadas * 100 / $t_total) : 0;
    ?>
    <div class="modal-bg" id="info<?php echo (int)$f['id']; ?>">
        <div class="modal-box">
            <h3>Análisis · Ficha <?php echo h($f['codigo_ficha']); ?> <span class="cerrar" onclick="cerrar('info<?php echo (int)$f['id']; ?>')">&times;</span></h3>
            <div class="modal-body">
                <p class="hint" style="margin-bottom:16px;">
                    <?php echo h($f['programa'] ?: 'Sin programa'); ?>
                    <?php echo $f['ambiente_nombre'] ? ' · ' . h($f['ambiente_nombre']) : ''; ?>
                </p>

                <?php if ($t_total === 0): ?>
                    <p class="hint">Esta ficha aún no tiene clases sincronizadas. Configura el enlace del Sheet y pulsa <strong>"Sincronizar"</strong>.</p>
                <?php else: ?>
                    <div class="info-grid">
                        <div class="info-metric">
                            <div class="im-num"><?php echo (int)$f['n_inst_clases']; ?></div>
                            <div class="im-lbl"><i class="bi bi-person-workspace"></i> Instructores</div>
                        </div>
                        <div class="info-metric">
                            <div class="im-num"><?php echo $t_total; ?></div>
                            <div class="im-lbl"><i class="bi bi-collection"></i> Clases totales</div>
                        </div>
                        <div class="info-metric ok">
                            <div class="im-num"><?php echo $t_dictadas; ?></div>
                            <div class="im-lbl"><i class="bi bi-check2-circle"></i> Ya dictadas</div>
                        </div>
                        <div class="info-metric pend">
                            <div class="im-num"><?php echo $t_faltan; ?></div>
                            <div class="im-lbl"><i class="bi bi-hourglass-split"></i> Faltan</div>
                        </div>
                    </div>

                    <div class="info-progreso">
                        <div class="ip-top"><span>Avance del programa</span><span><strong><?php echo $t_pct; ?>%</strong></span></div>
                        <div class="ip-bar"><div class="ip-fill" style="width:<?php echo $t_pct; ?>%;"></div></div>
                    </div>
                <?php endif; ?>

                <p class="hint" style="margin-top:16px;">
                    <i class="bi bi-arrow-repeat"></i> Última sincronización:
                    <?php echo $f['ultima_sync'] ? date('d/m/Y H:i', strtotime($f['ultima_sync'])) : 'nunca'; ?>.
                </p>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <script>
        const btnMenu = document.getElementById("btnMenu");
        const modalMenu = document.getElementById("modalMenu");
        if (btnMenu && modalMenu) {
            btnMenu.addEventListener("click", () => modalMenu.classList.add("active"));
            modalMenu.addEventListener("click", (e) => { if (e.target === modalMenu) modalMenu.classList.remove("active"); });
        }
        function abrir(id){ document.getElementById(id).classList.add('open'); }
        function cerrar(id){ document.getElementById(id).classList.remove('open'); }
        document.querySelectorAll('.modal-bg').forEach(m => {
            m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); });
        });

        /* ── Auto-sincronización en segundo plano (sin botón ni comando) ──
           Se dispara al abrir la página; el servidor limita la frecuencia,
           así que es seguro. Si trajo cambios, recarga una vez para mostrarlos. */
        (function () {
            const params = new URLSearchParams(location.search);
            fetch('../../controllers/auto_sync_endpoint.php?sede_id=<?php echo $sede_id; ?>')
                .then(r => r.json())
                .then(d => {
                    if (d && d.ok && d.cambios > 0 && !params.has('synced')) {
                        params.set('synced', '1');
                        location.search = params.toString();
                    }
                })
                .catch(() => {});
        })();
    </script>
</body>
</html>
