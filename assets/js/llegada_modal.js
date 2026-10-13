/**
 * Ventana "Registrar llegada": el celador puede escanear el código de barras del
 * carnet (cámara o foto) para verificar que es el instructor de la clase y
 * escribir una descripción breve antes de confirmar.
 *
 * - Intercepta los formularios .form-llegada de los listados (data-cedula,
 *   data-instructor, data-horario) y les agrega carnet + observacion.
 * - Expone window.SagaLlegada.abrir({cedula, instructor, horario}) → Promise
 *   con {carnet, observacion} o null si se cancela (lo usa llegada_instructor.js).
 *
 * Lectura local: las imágenes de la cámara no se envían al servidor.
 */
(function () {
    'use strict';
    if (typeof document === 'undefined') return;
    const script = document.currentScript;
    const workerUrl = new URL('carnet_worker.js?v=20261010-4', script.src);
    const MAX = 255;

    let modal, el = {}, resolver = null, datos = null;
    let stream = null, worker = null, loop = null, generation = 0, carnet = '';
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d', { willReadFrequently: true });

    function crear() {
        if (modal) return;
        modal = document.createElement('div');
        modal.className = 'llm-fondo';
        modal.hidden = true;
        modal.innerHTML = `
            <div class="llm-caja" role="dialog" aria-modal="true" aria-labelledby="llmTitulo">
                <h3 id="llmTitulo"><i class="bi bi-person-check-fill"></i> Registrar llegada</h3>
                <p class="llm-sub" data-r="sub"></p>
                <div class="llm-botones">
                    <button type="button" data-r="camara"><i class="bi bi-upc-scan"></i> Escanear carnet</button>
                    <button type="button" data-r="foto"><i class="bi bi-image"></i> Leer foto</button>
                </div>
                <input type="file" accept="image/jpeg,image/png,image/webp" capture="environment" data-r="archivo" hidden>
                <video class="llm-video" data-r="video" playsinline muted hidden></video>
                <p class="llm-estado" data-r="estado">Escanear el carnet es opcional, pero confirma que es el instructor correcto.</p>
                <label for="llmObs">Descripción breve (opcional)</label>
                <textarea id="llmObs" data-r="obs" maxlength="${MAX}" placeholder="Ej.: llegó con los aprendices, pidió llaves del ambiente…"></textarea>
                <div class="llm-contador" data-r="contador">0/${MAX}</div>
                <div class="llm-acciones">
                    <button type="button" class="llm-cancelar" data-r="cancelar">Cancelar</button>
                    <button type="button" class="llm-confirmar" data-r="confirmar"><i class="bi bi-check-lg"></i> Confirmar llegada</button>
                </div>
            </div>`;
        document.body.appendChild(modal);
        modal.querySelectorAll('[data-r]').forEach(n => { el[n.dataset.r] = n; });

        el.camara.addEventListener('click', abrirCamara);
        el.foto.addEventListener('click', () => { detener(); el.archivo.value = ''; el.archivo.click(); });
        el.archivo.addEventListener('change', leerFoto);
        el.obs.addEventListener('input', () => { el.contador.textContent = el.obs.value.length + '/' + MAX; });
        el.cancelar.addEventListener('click', () => cerrar(null));
        el.confirmar.addEventListener('click', () => cerrar({ carnet, observacion: el.obs.value.trim() }));
        modal.addEventListener('click', e => { if (e.target === modal) cerrar(null); });
        document.addEventListener('keydown', e => { if (e.key === 'Escape' && !modal.hidden) cerrar(null); });
    }

    function estado(texto, tipo = '') { el.estado.textContent = texto; el.estado.dataset.tipo = tipo; }

    function detener() {
        generation++;
        clearTimeout(loop);
        if (stream) stream.getTracks().forEach(t => t.stop());
        stream = null;
        el.video.srcObject = null;
        el.video.hidden = true;
    }

    function cerrar(resultado) {
        detener();
        worker?.terminate();
        worker = null;
        modal.hidden = true;
        document.body.style.overflow = '';
        const r = resolver; resolver = null;
        if (r) r(resultado);
    }

    function decodificar(img) {
        return new Promise((resolve, reject) => {
            if (!worker) worker = new Worker(workerUrl);
            const t = setTimeout(() => reject(new Error('La lectura tardó demasiado.')), 15000);
            worker.onmessage = ({ data }) => { clearTimeout(t); data.error ? reject(new Error(data.error)) : resolve(data.value); };
            worker.onerror = () => { clearTimeout(t); reject(new Error('No se pudo cargar el lector. Recarga la página.')); };
            worker.postMessage({ width: img.width, height: img.height, buffer: img.data.buffer }, [img.data.buffer]);
        });
    }

    function pixeles(fuente, w, h) {
        const s = Math.min(1, 1920 / Math.max(w, h));
        canvas.width = Math.max(1, Math.round(w * s));
        canvas.height = Math.max(1, Math.round(h * s));
        ctx.drawImage(fuente, 0, 0, canvas.width, canvas.height);
        return ctx.getImageData(0, 0, canvas.width, canvas.height);
    }

    function verificar(valor) {
        const leido = String(valor).trim();
        if (!/^\d+$/.test(leido)) { estado('El código leído no contiene una cédula válida. Intenta de nuevo.', 'error'); return false; }
        if (datos.cedula && leido !== datos.cedula) {
            carnet = '';
            estado('Este carnet (' + leido + ') NO corresponde a ' + datos.instructor + '. Verifica la persona.', 'error');
            return false;
        }
        carnet = leido;
        estado('Carnet verificado: ' + datos.instructor + ' (C.C. ' + leido + ').', 'ok');
        el.obs.focus();
        return true;
    }

    async function abrirCamara() {
        detener();
        if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
            estado('La cámara necesita HTTPS y un navegador compatible. Usa "Leer foto".', 'error');
            return;
        }
        const g = generation;
        estado('Permite el acceso a la cámara y centra el código de barras del carnet.');
        try {
            const s = await navigator.mediaDevices.getUserMedia({ audio: false, video: { facingMode: { ideal: 'environment' }, width: { ideal: 1920 }, height: { ideal: 1080 } } });
            if (g !== generation) { s.getTracks().forEach(t => t.stop()); return; }
            stream = s;
            el.video.srcObject = s;
            el.video.hidden = false;
            await el.video.play();
            const fin = Date.now() + 60000;
            const paso = async () => {
                if (g !== generation) return;
                if (Date.now() > fin) { detener(); estado('No se pudo leer el código. Prueba con "Leer foto".', 'error'); return; }
                try {
                    if (el.video.readyState >= 2 && el.video.videoWidth) {
                        const v = await decodificar(pixeles(el.video, el.video.videoWidth, el.video.videoHeight));
                        if (g !== generation) return;
                        if (v) { detener(); verificar(v); return; }
                    }
                } catch (e) { if (g !== generation) return; detener(); estado(e.message, 'error'); return; }
                loop = setTimeout(paso, 16);
            };
            paso();
        } catch (e) {
            if (g !== generation) return;
            detener();
            estado(e.name === 'NotAllowedError' ? 'Permiso de cámara denegado. Usa "Leer foto".' : 'No se pudo abrir la cámara. Usa "Leer foto".', 'error');
        }
    }

    async function leerFoto() {
        const f = el.archivo.files[0];
        if (!f) return;
        if (!['image/jpeg', 'image/png', 'image/webp'].includes(f.type) || f.size > 16 * 1024 * 1024) {
            estado('Selecciona una foto JPG, PNG o WebP de hasta 16 MB.', 'error'); return;
        }
        const g = generation;
        estado('Leyendo la foto del carnet…');
        const url = URL.createObjectURL(f);
        try {
            const img = new Image();
            img.src = url;
            await img.decode();
            const v = await decodificar(pixeles(img, img.naturalWidth, img.naturalHeight));
            if (g !== generation) return;
            if (v) verificar(v);
            else estado('No se pudo leer el código de la foto. Usa una imagen enfocada del código completo.', 'error');
        } catch (e) {
            if (g === generation) estado(e.message || 'No se pudo leer la foto.', 'error');
        } finally { URL.revokeObjectURL(url); }
    }

    function abrir(d) {
        crear();
        if (resolver) cerrar(null);
        datos = { cedula: String(d.cedula || '').trim(), instructor: d.instructor || 'el instructor', horario: d.horario || '' };
        carnet = '';
        el.sub.textContent = datos.instructor + (datos.horario ? ' · clase ' + datos.horario : '')
            + '. Si llega antes, queda con la hora programada.';
        el.obs.value = '';
        el.contador.textContent = '0/' + MAX;
        el.confirmar.disabled = false;
        estado('Escanear el carnet es opcional, pero confirma que es el instructor correcto.');
        modal.hidden = false;
        document.body.style.overflow = 'hidden';
        el.camara.focus();
        return new Promise(r => { resolver = r; });
    }

    window.SagaLlegada = { abrir };

    // Formularios "Registrar llegada" de los listados.
    document.addEventListener('submit', async e => {
        const form = e.target;
        if (!form.classList || !form.classList.contains('form-llegada') || form.dataset.confirmada) return;
        e.preventDefault();
        const r = await abrir({ cedula: form.dataset.cedula, instructor: form.dataset.instructor, horario: form.dataset.horario });
        if (!r) return;
        const poner = (n, v) => {
            let i = form.querySelector(`input[name="${n}"]`);
            if (!i) { i = document.createElement('input'); i.type = 'hidden'; i.name = n; form.appendChild(i); }
            i.value = v;
        };
        poner('carnet', r.carnet);
        poner('observacion', r.observacion);
        form.dataset.confirmada = '1';
        const btn = form.querySelector('button[type="submit"]');
        if (btn) btn.disabled = true;
        form.submit();
    });
})();
