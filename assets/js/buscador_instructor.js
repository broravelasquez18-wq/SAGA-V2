/**
 * Buscador de instructor por nombre o cédula para el <select name="usuario_id">
 * del modal "Nueva Ocupación".
 *
 * No quita opciones del <select> (Safari iOS ignora las opciones ocultas y el
 * escáner de carnet busca dentro de select.options): agrega encima un campo de
 * búsqueda con sugerencias y, al elegir una, fija el valor del select y dispara
 * "change", igual que si se hubiera elegido a mano.
 */
(function () {
    if (typeof document === 'undefined') return;

    const norm = s => (s || '').toString()
        .normalize('NFD').replace(/[̀-ͯ]/g, '')
        .toLowerCase().replace(/\s+/g, ' ').trim();
    const digitos = s => (s || '').toString().replace(/\D/g, '');

    function estilos() {
        if (document.getElementById('buscadorInstructorCss')) return;
        const css = document.createElement('style');
        css.id = 'buscadorInstructorCss';
        css.textContent = `
.buscador-instructor{ position:relative; margin-bottom:8px; }
.buscador-instructor-input{ width:100%; box-sizing:border-box; min-height:44px; padding:11px 14px 11px 38px !important;
  background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%236E7A6E' viewBox='0 0 16 16'%3E%3Cpath d='M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398l3.85 3.85a1 1 0 0 0 1.415-1.414zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0'/%3E%3C/svg%3E") no-repeat 13px center !important; }
.buscador-instructor-lista{ position:absolute; z-index:30; left:0; right:0; top:calc(100% + 4px); margin:0; padding:4px; list-style:none;
  background:#fff; border:1.5px solid var(--borde, #E4E7E4); border-radius:11px; box-shadow:0 10px 28px rgba(20,35,20,.14);
  max-height:240px; overflow-y:auto; }
.buscador-instructor-lista li{ padding:10px 12px; border-radius:8px; cursor:pointer; font-size:14px; color:var(--carbon, #23272B); line-height:1.3; }
.buscador-instructor-lista li.activo, .buscador-instructor-lista li:hover{ background:var(--verde-suave, #EAF6E3); color:var(--verde-oscuro, #007832); }
.buscador-instructor-lista li.vacio{ cursor:default; color:var(--gris, #6E7A6E); background:none; }
.buscador-instructor-lista mark{ background:none; color:inherit; font-weight:800; }`;
        document.head.appendChild(css);
    }

    function resaltar(texto, q) {
        const i = norm(texto).indexOf(q);
        const esc = t => t.replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
        if (!q || i < 0) return esc(texto);
        return esc(texto.slice(0, i)) + '<mark>' + esc(texto.slice(i, i + q.length)) + '</mark>' + esc(texto.slice(i + q.length));
    }

    function iniciar(select) {
        if (select.dataset.buscadorListo) return;
        select.dataset.buscadorListo = '1';
        estilos();

        const wrap = document.createElement('div');
        wrap.className = 'buscador-instructor';
        const input = document.createElement('input');
        input.type = 'search';
        input.className = 'buscador-instructor-input';
        input.placeholder = 'Buscar por nombre o cédula...';
        input.autocomplete = 'off';
        input.setAttribute('aria-label', 'Buscar instructor por nombre o cédula');
        input.setAttribute('enterkeyhint', 'search');
        const lista = document.createElement('ul');
        lista.className = 'buscador-instructor-lista';
        lista.setAttribute('role', 'listbox');
        lista.hidden = true;
        wrap.append(input, lista);
        select.parentNode.insertBefore(wrap, select);

        let resultados = [];
        let activo = -1;

        const opciones = () => Array.from(select.options).filter(o => o.value);

        function cerrarLista() { lista.hidden = true; activo = -1; }

        function marcar(i) {
            activo = i;
            lista.querySelectorAll('li[role="option"]').forEach((li, j) => li.classList.toggle('activo', j === i));
            const li = lista.querySelectorAll('li[role="option"]')[i];
            if (li) li.scrollIntoView({ block: 'nearest' });
        }

        function elegir(opcion) {
            select.value = opcion.value;
            select.dispatchEvent(new Event('change', { bubbles: true }));
            input.value = opcion.textContent.trim();
            cerrarLista();
        }

        function buscar() {
            const q = norm(input.value);
            const qd = digitos(input.value);
            lista.innerHTML = '';
            if (!q) { cerrarLista(); return; }
            resultados = opciones().filter(o =>
                norm(o.textContent).includes(q) ||
                (qd.length >= 3 && digitos(o.dataset.cedula).includes(qd))
            ).slice(0, 8);
            if (!resultados.length) {
                const li = document.createElement('li');
                li.className = 'vacio';
                li.textContent = 'No se encontró ningún instructor';
                lista.append(li);
            }
            resultados.forEach(o => {
                const li = document.createElement('li');
                li.setAttribute('role', 'option');
                li.innerHTML = resaltar(o.textContent.trim(), q);
                li.addEventListener('click', () => elegir(o));
                lista.append(li);
            });
            lista.hidden = false;
            marcar(resultados.length ? 0 : -1);
        }

        input.addEventListener('input', buscar);
        input.addEventListener('focus', () => { if (input.value && select.value === '') buscar(); });
        input.addEventListener('keydown', e => {
            if (lista.hidden) return;
            if (e.key === 'ArrowDown') { e.preventDefault(); marcar(Math.min(activo + 1, resultados.length - 1)); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); marcar(Math.max(activo - 1, 0)); }
            else if (e.key === 'Enter') { e.preventDefault(); if (resultados[activo]) elegir(resultados[activo]); }
            else if (e.key === 'Escape') { cerrarLista(); }
        });
        // Evita que el toque en la lista quite el foco antes de elegir.
        lista.addEventListener('pointerdown', e => e.preventDefault());
        input.addEventListener('blur', () => setTimeout(cerrarLista, 120));
        document.addEventListener('click', e => { if (!wrap.contains(e.target)) cerrarLista(); });

        // Si eligen directamente en el select, limpiar la búsqueda.
        select.addEventListener('change', () => {
            if (document.activeElement !== input) input.value = '';
        });
        if (select.form) select.form.addEventListener('reset', () => { input.value = ''; cerrarLista(); });
    }

    function todos() {
        document.querySelectorAll('select[name="usuario_id"]').forEach(iniciar);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', todos);
    else todos();
})();
