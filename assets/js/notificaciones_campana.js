/* ============================================================================
   SAGA · Campana de notificaciones in-app (con actualización en vivo)
   - Abre/cierra el panel
   - Sondea el servidor cada POLL_MS y refresca badge + lista sin recargar
   - Marca como leídas (individual al hacer clic, o todas)
   - Requiere el partial views/_campana.php y su CSS
   ============================================================================ */
(function () {
    const wrap = document.getElementById('campanaWrap');
    if (!wrap) return;

    const btn   = document.getElementById('campanaBtn');
    const panel = document.getElementById('campanaPanel');
    const lista = document.getElementById('campanaLista');
    const cab   = panel ? panel.querySelector('.campana-panel-cab') : null;
    const csrf  = wrap.getAttribute('data-csrf') || '';

    const MARCAR_URL = '../../controllers/MarcarNotificacionesLeidas.php';
    const POLL_URL   = '../../controllers/ObtenerNotificaciones.php';
    const POLL_MS    = 15000;

    let firma = null;   // huella del último estado renderizado (evita re-render inútil)

    /* ---------- utilidades ---------- */
    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function abrir()   { panel.classList.add('abierto'); }
    function cerrar()  { panel.classList.remove('abierto'); }
    function alternar(){ panel.classList.toggle('abierto'); }

    /* ---------- badge ---------- */
    function setBadge(n) {
        let badge = document.getElementById('campanaBadge');
        if (n > 0) {
            if (!badge) {
                badge = document.createElement('span');
                badge.className = 'campana-badge';
                badge.id = 'campanaBadge';
                btn.appendChild(badge);
            }
            badge.textContent = n > 99 ? '99+' : n;
        } else if (badge) {
            badge.remove();
        }
    }

    /* ---------- botón "marcar todas" ---------- */
    function setMarcarTodas(hayNoLeidas) {
        let mt = document.getElementById('campanaMarcarTodas');
        if (hayNoLeidas) {
            if (!mt && cab) {
                mt = document.createElement('button');
                mt.type = 'button';
                mt.className = 'campana-marcar';
                mt.id = 'campanaMarcarTodas';
                mt.textContent = 'Marcar todas leídas';
                cab.appendChild(mt);
            }
        } else if (mt) {
            mt.remove();
        }
    }

    /* ---------- render de la lista ---------- */
    function renderLista(items) {
        if (!items.length) {
            lista.innerHTML =
                '<div class="campana-vacio"><i class="bi bi-bell-slash"></i><p>No tienes notificaciones</p></div>';
            return;
        }
        let html = '';
        items.forEach(function (n) {
            html +=
                '<div class="campana-item ' + (n.leida ? '' : 'no-leida') + '"' +
                ' data-id="' + n.id + '" data-url="' + escapeHtml(n.url) + '">' +
                    '<div class="campana-item-icono ' + escapeHtml(n.clase) + '"><i class="bi ' + escapeHtml(n.icono) + '"></i></div>' +
                    '<div class="campana-item-cuerpo">' +
                        '<span class="campana-item-titulo">' + escapeHtml(n.titulo) + '</span>' +
                        '<span class="campana-item-msg">' + escapeHtml(n.mensaje) + '</span>' +
                        '<span class="campana-item-tiempo">' + escapeHtml(n.tiempo) + '</span>' +
                    '</div>' +
                    (n.leida ? '' : '<span class="campana-item-punto"></span>') +
                '</div>';
        });
        lista.innerHTML = html;
    }

    function aplicar(data) {
        const items = Array.isArray(data.items) ? data.items : [];
        const nueva = data.no_leidas + '|' + items.map(function (i) { return i.id + ':' + i.leida; }).join(',');
        if (nueva === firma) return;              // sin cambios: no re-renderiza
        const antesNoLeidas = firma === null ? -1 : parseInt(firma, 10);

        firma = nueva;
        renderLista(items);
        setBadge(data.no_leidas);
        setMarcarTodas(data.no_leidas > 0);

        // Aviso emergente si llegó algo nuevo (y no es la primera carga)
        if (antesNoLeidas !== -1 && data.no_leidas > antesNoLeidas && typeof window.notificar === 'function') {
            window.notificar('Tienes una nueva notificación', 'info');
        }
    }

    function sondear() {
        fetch(POLL_URL, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) { if (data && data.ok) aplicar(data); })
            .catch(function () {});
    }

    /* ---------- marcar leídas ---------- */
    function marcarLeida(id) {
        const body = new URLSearchParams();
        body.append('csrf_token', csrf);
        if (id) body.append('id', id);
        return fetch(MARCAR_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            credentials: 'same-origin',
            body: body.toString()
        }).catch(function () {});
    }

    /* ---------- eventos (delegación: sobreviven a los refrescos) ---------- */
    btn.addEventListener('click', function (e) { e.stopPropagation(); alternar(); });

    document.addEventListener('click', function (e) { if (!wrap.contains(e.target)) cerrar(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') cerrar(); });

    panel.addEventListener('click', function (e) {
        const marcar = e.target.closest('#campanaMarcarTodas');
        if (marcar) {
            e.stopPropagation();
            marcarLeida(null);
            panel.querySelectorAll('.campana-item.no-leida').forEach(function (it) {
                it.classList.remove('no-leida');
                const p = it.querySelector('.campana-item-punto');
                if (p) p.remove();
            });
            setBadge(0);
            setMarcarTodas(false);
            firma = null;          // fuerza reconciliación en el próximo sondeo
            return;
        }

        const item = e.target.closest('.campana-item');
        if (item) {
            const id  = item.getAttribute('data-id');
            const url = item.getAttribute('data-url');
            if (item.classList.contains('no-leida')) {
                marcarLeida(id);
                item.classList.remove('no-leida');
                const p = item.querySelector('.campana-item-punto');
                if (p) p.remove();
                const restantes = panel.querySelectorAll('.campana-item.no-leida').length;
                setBadge(restantes);
                setMarcarTodas(restantes > 0);
                firma = null;
            }
            if (url) window.location.href = url;
        }
    });

    /* ---------- arranque del polling ---------- */
    setTimeout(sondear, 3000);            // primera sincronización rápida
    setInterval(sondear, POLL_MS);        // luego cada POLL_MS
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) sondear();  // al volver a la pestaña, refresca ya
    });
})();
