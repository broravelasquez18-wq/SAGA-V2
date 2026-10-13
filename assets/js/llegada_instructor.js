/**
 * Modal "Nueva Ocupación" del celador: cuando se identifica al instructor
 * (escaneando el carnet, con el buscador o eligiéndolo a mano), consulta si ya
 * tiene clase HOY (p. ej. sincronizada desde Google Sheets). Si la tiene, muestra
 * un aviso con "Registrar llegada" en lugar de dejar crear una ocupación que
 * chocaría con la existente.
 */
(function () {
    if (typeof document === 'undefined') return;
    const BASE = '../../controllers/';

    const esc = t => (t || '').toString().replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

    function iniciar(select) {
        const form = select.form;
        if (!form || select.dataset.llegadaLista) return;
        select.dataset.llegadaLista = '1';

        const panel = document.createElement('div');
        panel.className = 'panel-llegada';
        panel.hidden = true;
        const grupo = select.closest('.form-grupo') || select.parentNode;
        grupo.insertAdjacentElement('afterend', panel);

        let peticion = 0;

        function ocultar() { panel.hidden = true; panel.innerHTML = ''; }

        async function consultar() {
            const id = select.value;
            if (!id) { ocultar(); return; }
            const mia = ++peticion;
            try {
                const r = await fetch(BASE + 'ClaseProgramadaInstructor.php?usuario_id=' + encodeURIComponent(id), { credentials: 'same-origin' });
                const d = await r.json();
                if (mia !== peticion) return;              // llegó una respuesta vieja
                if (!d.ok || !d.clases.length) { ocultar(); return; }
                pintar(d.clases);
            } catch (e) { ocultar(); }
        }

        function pintar(clases) {
            const nombre = esc(select.selectedOptions[0] ? select.selectedOptions[0].textContent.trim() : 'El instructor');
            let html = `<h4><i class="bi bi-calendar-check"></i> Ya tiene clase programada hoy</h4>
                        <p>${nombre} ya está registrado. No necesitas crear una ocupación nueva: confirma su llegada.</p>`;
            clases.forEach(c => {
                const badge = c.llegada ? `<span class="llegada-badge llegada-${esc(c.llegada.clase)}">${esc(c.llegada.texto)}</span>` : '';
                html += `<div class="clase" data-id="${c.id}">
                            <strong>${esc(c.ambiente)} · ${esc(c.horario)}</strong>
                            ${c.ficha ? 'Ficha ' + esc(c.ficha) + (c.sincronizada ? ' · desde el calendario SENA' : '') : (c.sincronizada ? 'Desde el calendario SENA' : 'Registro manual')}
                            <br>${badge}
                            ${c.registrable ? `<button type="button" class="btn-llegada" data-llegada="${c.id}" data-horario="${esc(c.horario)}"><i class="bi bi-person-check-fill"></i> Registrar llegada</button>` : ''}
                         </div>`;
            });
            panel.innerHTML = html;
            panel.hidden = false;
        }

        panel.addEventListener('click', async e => {
            const btn = e.target.closest('[data-llegada]');
            if (!btn) return;
            const caja = btn.closest('.clase');
            const opcion = select.selectedOptions[0];
            let extra = { carnet: '', observacion: '' };
            if (window.SagaLlegada) {
                extra = await window.SagaLlegada.abrir({
                    cedula: opcion ? opcion.dataset.cedula : '',
                    instructor: opcion ? opcion.textContent.trim() : '',
                    horario: btn.dataset.horario || ''
                });
                if (!extra) return;                      // canceló la ventana
            }
            btn.disabled = true;
            const datos = new FormData();
            datos.append('csrf_token', (form.querySelector('[name="csrf_token"]') || {}).value || '');
            datos.append('id', btn.dataset.llegada);
            datos.append('carnet', extra.carnet);
            datos.append('observacion', extra.observacion);
            datos.append('ajax', '1');
            try {
                const r = await fetch(BASE + 'RegistrarLlegada.php', { method: 'POST', body: datos, credentials: 'same-origin' });
                const d = await r.json();
                btn.remove();
                const msg = document.createElement('div');
                if (d.ok) {
                    msg.className = 'ok-msg';
                    msg.innerHTML = `<i class="bi bi-check-circle-fill"></i> ${esc(d.texto)}. La página se actualizará…`;
                    caja.appendChild(msg);
                    setTimeout(() => location.reload(), 1400);
                } else {
                    msg.className = 'err-msg';
                    msg.textContent = d.codigo === 'llegada_ya_registrada' ? 'La llegada ya estaba registrada.'
                                    : d.codigo === 'llegada_carnet_no_coincide' ? 'El carnet escaneado no corresponde a este instructor.'
                                    : d.codigo === 'llegada_fuera_horario' ? 'Aún no es posible registrarla (solo desde 1 hora antes de la clase).'
                                    : 'No se pudo registrar la llegada.';
                    caja.appendChild(msg);
                }
            } catch (err) {
                btn.disabled = false;
            }
        });

        select.addEventListener('change', consultar);
        form.addEventListener('reset', ocultar);
    }

    function todos() { document.querySelectorAll('select[name="usuario_id"]').forEach(iniciar); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', todos);
    else todos();
})();
