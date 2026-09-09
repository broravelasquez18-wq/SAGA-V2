/* ============================================================================
   SAGA · Sistema de notificaciones (toasts)
   Uso:  notificar('Mensaje', 'exito' | 'error' | 'aviso' | 'info', duracionMs)
   Requiere notificaciones.css + Bootstrap Icons.
   ============================================================================ */
(function () {
    const ICONOS = {
        exito: 'bi-check-circle-fill',
        error: 'bi-x-circle-fill',
        aviso: 'bi-exclamation-triangle-fill',
        info:  'bi-info-circle-fill',
    };

    function getContenedor() {
        let c = document.getElementById('toastContainer');
        if (!c) {
            c = document.createElement('div');
            c.id = 'toastContainer';
            c.className = 'toast-container';
            document.body.appendChild(c);
        }
        return c;
    }

    window.notificar = function (mensaje, tipo, duracion) {
        if (!mensaje) return;
        tipo = ICONOS[tipo] ? tipo : 'info';
        duracion = (typeof duracion === 'number') ? duracion : 5000;

        const cont = getContenedor();
        const t = document.createElement('div');
        t.className = 'toast toast-' + tipo;
        t.setAttribute('role', 'alert');
        t.innerHTML =
            '<div class="toast-icono"><i class="bi ' + ICONOS[tipo] + '"></i></div>' +
            '<div class="toast-texto"></div>' +
            '<button class="toast-cerrar" type="button" aria-label="Cerrar"><i class="bi bi-x-lg"></i></button>' +
            '<div class="toast-progreso"></div>';
        t.querySelector('.toast-texto').textContent = mensaje;
        cont.appendChild(t);

        requestAnimationFrame(() => t.classList.add('visible'));

        let timer = null;
        const cerrar = () => {
            if (timer) clearTimeout(timer);
            t.classList.remove('visible');
            t.classList.add('saliendo');
            setTimeout(() => t.remove(), 400);
        };
        t.querySelector('.toast-cerrar').addEventListener('click', cerrar);

        if (duracion > 0) {
            const bar = t.querySelector('.toast-progreso');
            bar.style.transition = 'transform ' + duracion + 'ms linear';
            requestAnimationFrame(() => { bar.style.transform = 'scaleX(0)'; });
            timer = setTimeout(cerrar, duracion);

            // Pausar el auto-cierre mientras el cursor está encima
            t.addEventListener('mouseenter', () => {
                if (timer) clearTimeout(timer);
                const w = bar.getBoundingClientRect().width;
                bar.style.transition = 'none';
                bar.style.transform = 'scaleX(' + (w / t.getBoundingClientRect().width) + ')';
            });
            t.addEventListener('mouseleave', () => {
                bar.style.transition = 'transform 2500ms linear';
                requestAnimationFrame(() => { bar.style.transform = 'scaleX(0)'; });
                timer = setTimeout(cerrar, 2500);
            });
        }
        return t;
    };
})();
