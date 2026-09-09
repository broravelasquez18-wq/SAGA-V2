<?php
/**
 * Campana de notificaciones in-app (compartida por instructor y admin).
 * Autosuficiente: se puede incluir con una sola línea dentro del header:
 *     <?php include __DIR__ . '/../_campana.php'; ?>
 * Emite su propio CSS/JS (una sola vez por página) y usa/abre la conexión.
 */
if (!isset($_SESSION['id'])) return;

require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/notificaciones_helper.php';

if (!isset($con) || !$con) { $con = conexion(); }
if (!$con) return;

$_camp_uid = intval($_SESSION['id']);

$_camp_no_leidas = 0;
$_rc = mysqli_query($con, "SELECT COUNT(*) AS t FROM notificaciones WHERE usuario_id = $_camp_uid AND leida = 0");
if ($_rc && ($_rr = mysqli_fetch_assoc($_rc))) $_camp_no_leidas = (int)$_rr['t'];

$_camp_lista = [];
$_rl = mysqli_query($con, "SELECT id, tipo, titulo, mensaje, url, leida, creado_en
                           FROM notificaciones
                           WHERE usuario_id = $_camp_uid
                           ORDER BY creado_en DESC
                           LIMIT 20");
if ($_rl) { while ($_row = mysqli_fetch_assoc($_rl)) $_camp_lista[] = $_row; }

$_camp_csrf = function_exists('csrf_token') ? csrf_token() : '';

// Emitir CSS/JS una sola vez por página
if (!defined('CAMPANA_ASSETS_EMITIDOS')) {
    define('CAMPANA_ASSETS_EMITIDOS', true);
    echo '<link rel="stylesheet" href="../../assets/css/notificaciones_campana.css">';
    $_camp_emitir_js = true;
} else {
    $_camp_emitir_js = false;
}
?>
<div class="campana-wrap" id="campanaWrap" data-csrf="<?php echo htmlspecialchars($_camp_csrf, ENT_QUOTES); ?>">
    <button type="button" class="campana-btn" id="campanaBtn" aria-label="Notificaciones">
        <i class="bi bi-bell-fill"></i>
        <?php if ($_camp_no_leidas > 0): ?>
            <span class="campana-badge" id="campanaBadge"><?php echo $_camp_no_leidas > 99 ? '99+' : $_camp_no_leidas; ?></span>
        <?php endif; ?>
    </button>

    <div class="campana-panel" id="campanaPanel">
        <div class="campana-panel-cab">
            <h4><i class="bi bi-bell"></i> Notificaciones</h4>
            <?php if ($_camp_no_leidas > 0): ?>
                <button type="button" class="campana-marcar" id="campanaMarcarTodas">Marcar todas leídas</button>
            <?php endif; ?>
        </div>

        <div class="campana-lista" id="campanaLista">
            <?php if (!$_camp_lista): ?>
                <div class="campana-vacio">
                    <i class="bi bi-bell-slash"></i>
                    <p>No tienes notificaciones</p>
                </div>
            <?php else: foreach ($_camp_lista as $_n):
                [$_ic, $_cls] = camp_icono($_n['tipo']);
            ?>
                <div class="campana-item <?php echo $_n['leida'] ? '' : 'no-leida'; ?>"
                     data-id="<?php echo (int)$_n['id']; ?>"
                     data-url="<?php echo htmlspecialchars($_n['url'] ?? '', ENT_QUOTES); ?>">
                    <div class="campana-item-icono <?php echo $_cls; ?>"><i class="bi <?php echo $_ic; ?>"></i></div>
                    <div class="campana-item-cuerpo">
                        <span class="campana-item-titulo"><?php echo htmlspecialchars($_n['titulo']); ?></span>
                        <span class="campana-item-msg"><?php echo htmlspecialchars($_n['mensaje']); ?></span>
                        <span class="campana-item-tiempo"><?php echo camp_tiempo($_n['creado_en']); ?></span>
                    </div>
                    <?php if (!$_n['leida']): ?><span class="campana-item-punto"></span><?php endif; ?>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
</div>
<?php if ($_camp_emitir_js): ?>
<script src="../../assets/js/notificaciones_campana.js"></script>
<?php endif; ?>
