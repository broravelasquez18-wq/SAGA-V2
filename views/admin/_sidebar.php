<?php
/**
 * Sidebar compartido del panel de administrador.
 *
 * Uso:  <?php include __DIR__ . '/_sidebar.php'; ?>
 * Requiere que la vista que lo incluye tenga definida la variable $sede_id.
 * El ítem activo se calcula solo a partir de la página actual.
 */
if (!isset($sede_id)) { $sede_id = isset($_GET['sede_id']) ? intval($_GET['sede_id']) : 0; }
$pagina_actual = basename($_SERVER['PHP_SELF']);

// Contador de solicitudes de cancelación pendientes de esta sede (para el badge)
$sb_solic_pendientes = 0;
if (isset($con) && $con) {
    $sbq = @mysqli_query($con, "SELECT COUNT(*) c
        FROM solicitudes_cancelacion sc
        JOIN historial_ocupacion ho ON sc.ocupacion_id = ho.id
        JOIN ambientes a ON ho.ambiente_id = a.id
        JOIN pisos p ON a.piso_id = p.id
        WHERE sc.estado = 'pendiente' AND p.sede_id = " . intval($sede_id));
    if ($sbq && ($sbr = mysqli_fetch_assoc($sbq))) $sb_solic_pendientes = (int)$sbr['c'];

    // Sumar las solicitudes de cambio de jornada pendientes de esta sede
    $sbcj = @mysqli_query($con, "SELECT COUNT(*) c
        FROM solicitudes_cambio_jornada scj
        JOIN historial_ocupacion ho ON scj.ocupacion_id = ho.id
        JOIN ambientes a ON ho.ambiente_id = a.id
        JOIN pisos p ON a.piso_id = p.id
        WHERE scj.estado = 'pendiente' AND p.sede_id = " . intval($sede_id));
    if ($sbcj && ($sbcr = mysqli_fetch_assoc($sbcj))) $sb_solic_pendientes += (int)$sbcr['c'];
}

// Contador global de solicitudes de ocupación pendientes (el vocero pide ocupar)
$sb_ocup_pendientes = 0;
if (isset($con) && $con) {
    $sbo = @mysqli_query($con, "SELECT COUNT(*) c FROM solicitudes_ocupacion WHERE estado = 'pendiente'");
    if ($sbo && ($sbor = mysqli_fetch_assoc($sbo))) $sb_ocup_pendientes = (int)$sbor['c'];
}
?>
<div class="modal-menu" id="modalMenu">
    <div class="sidebar">
        <h3 class="menu-titulo">PRINCIPAL</h3>
        <a href="dashboard_admin.php?sede_id=<?php echo $sede_id; ?>" <?php echo $pagina_actual == 'dashboard_admin.php' ? 'class="active"' : ''; ?>>
            <i class="bi bi-bar-chart-fill"></i>Dashboard
        </a>
        <a href="pisos_admin.php?sede_id=<?php echo $sede_id; ?>" <?php echo $pagina_actual == 'pisos_admin.php' ? 'class="active"' : ''; ?>>
            <i class="bi bi-layers"></i>Pisos
        </a>
        <a href="Ambientes_admin.php?sede_id=<?php echo $sede_id; ?>" <?php echo ($pagina_actual == 'Ambientes_admin.php' || $pagina_actual == 'Ambientes_piso.php' || $pagina_actual == 'carga_masiva_ambientes.php') ? 'class="active"' : ''; ?>>
            <i class="bi bi-grid-3x3-gap"></i>Ambientes
        </a>

        <h3 class="menu-titulo">GESTIÓN</h3>
        <a href="Celadores_admin.php?sede_id=<?php echo $sede_id; ?>" <?php echo ($pagina_actual == 'Celadores_admin.php' || $pagina_actual == 'carga_masiva_celadores.php') ? 'class="active"' : ''; ?>>
            <i class="bi bi-shield-check"></i>Celadores
        </a>
        <a href="instructores_admin.php?sede_id=<?php echo $sede_id; ?>" <?php echo ($pagina_actual == 'instructores_admin.php' || $pagina_actual == 'carga_masiva_instructores.php') ? 'class="active"' : ''; ?>>
            <i class="bi bi-person-workspace"></i>Instructores
        </a>
        <a href="voceros_admin.php?sede_id=<?php echo $sede_id; ?>" <?php echo $pagina_actual == 'voceros_admin.php' ? 'class="active"' : ''; ?>>
            <i class="bi bi-megaphone-fill"></i>Voceros
        </a>
        <a href="fichas_admin.php?sede_id=<?php echo $sede_id; ?>" <?php echo $pagina_actual == 'fichas_admin.php' ? 'class="active"' : ''; ?>>
            <i class="bi bi-journal-bookmark-fill"></i>Fichas
        </a>
        <a href="ocupaciones_admin.php?sede_id=<?php echo $sede_id; ?>" <?php echo ($pagina_actual == 'ocupaciones_admin.php' || $pagina_actual == 'carga_masiva_ocupaciones.php') ? 'class="active"' : ''; ?>>
            <i class="bi bi-calendar-check"></i>Ocupaciones
        </a>
        <a href="calendario_ocupaciones.php?sede_id=<?php echo $sede_id; ?>" <?php echo $pagina_actual == 'calendario_ocupaciones.php' ? 'class="active"' : ''; ?>>
            <i class="bi bi-calendar3"></i>Calendario
        </a>
        <a href="reportes_admin.php?sede_id=<?php echo $sede_id; ?>" <?php echo $pagina_actual == 'reportes_admin.php' ? 'class="active"' : ''; ?>>
            <i class="bi bi-file-earmark-bar-graph"></i>Reportes
        </a>
        <a href="historial_admin.php?sede_id=<?php echo $sede_id; ?>" <?php echo $pagina_actual == 'historial_admin.php' ? 'class="active"' : ''; ?>>
            <i class="bi bi-clock-history"></i>Historial
        </a>
        <a href="solicitudes_admin.php?sede_id=<?php echo $sede_id; ?>" <?php echo $pagina_actual == 'solicitudes_admin.php' ? 'class="active"' : ''; ?>>
            <i class="bi bi-envelope-paper"></i>Solicitudes<?php if ($sb_solic_pendientes > 0): ?> <span style="margin-left:auto;background:#FF7A00;color:#fff;font-size:11px;font-weight:800;padding:1px 8px;border-radius:999px;"><?php echo $sb_solic_pendientes; ?></span><?php endif; ?>
        </a>
        <a href="solicitudes_ocupacion_admin.php" <?php echo $pagina_actual == 'solicitudes_ocupacion_admin.php' ? 'class="active"' : ''; ?>>
            <i class="bi bi-box-arrow-in-down"></i>Ocupaciones vocero<?php if ($sb_ocup_pendientes > 0): ?> <span style="margin-left:auto;background:#FF7A00;color:#fff;font-size:11px;font-weight:800;padding:1px 8px;border-radius:999px;"><?php echo $sb_ocup_pendientes; ?></span><?php endif; ?>
        </a>

        <h3 class="menu-titulo">SISTEMA</h3>
        <a href="index_sedes.php">
            <i class="bi bi-gear-fill"></i>Gestionar Sedes
        </a>
    </div>
</div>
