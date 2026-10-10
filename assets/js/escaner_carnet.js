/* Lectura local: las imágenes de la cámara no se envían al servidor. */
(() => {
    'use strict';
    function buscarInstructor(options, codigo) {
        const cedula = String(codigo).trim();
        // No extraer dígitos de textos desconocidos ni perder ceros iniciales.
        if (!/^\d+$/.test(cedula)) return { error: 'El código leído no contiene una cédula válida. Intenta nuevamente o selecciona el instructor.' };
        const coincidencias = Array.from(options).filter(option => option.value && option.dataset.cedula === cedula);
        if (coincidencias.length !== 1) return { error: coincidencias.length
            ? 'Hay varios instructores con esa cédula. Solicita al administrador revisar los registros.'
            : 'No se encontró un instructor activo y autorizado para esta sede con esa cédula.' };
        return { option: coincidencias[0] };
    }
    if (typeof module !== 'undefined' && module.exports) module.exports = { buscarInstructor };
    if (typeof document === 'undefined') return;

    const root = document.getElementById('escanerCarnet');
    if (!root) return;
    const form = root.closest('form');
    const modal = form.closest('#modalOcupacion, #modalNuevaOcupacion');
    const select = form.querySelector('[name="usuario_id"]');
    const el = id => document.getElementById(id);
    const start = el('iniciarEscaneo'), stop = el('detenerEscaneo');
    const panel = el('panelCamara'), video = el('videoCarnet'), status = el('estadoCarnet');
    const camera = el('camaraCarnet'), photo = el('fotoCarnet'), photoButton = el('elegirFotoCarnet');
    const details = el('datosOcupacion'), summary = el('resumenCarnet');
    function mostrarEscaneo() {
        cerrar();
        root.hidden = false;
        details.hidden = true;
        details.disabled = true;
        select.value = '';
        mensaje('Escanea el carnet para continuar o elige Registrar manualmente.');
    }
    function mostrarFormulario(text) {
        cerrar();
        root.hidden = true;
        details.hidden = false;
        details.disabled = false;
        summary.textContent = text;
        (form.querySelector('[name="ambiente_id"]') || select).focus();
    }
    const canvas = document.createElement('canvas');
    const context = canvas.getContext('2d', { willReadFrequently: true });
    const workerUrl = new URL('carnet_worker.js?v=20261010-4', document.currentScript.src);
    let stream = null, worker = null, rejectDecode = null;
    let generation = 0, pending = false, loop = null, deadline = null;

    function mensaje(text, error = false) {
        status.textContent = text;
        status.dataset.error = String(error);
    }
    function cerrar() {
        generation++;
        clearTimeout(loop);
        clearTimeout(deadline);
        worker?.terminate();
        worker = null;
        if (rejectDecode) rejectDecode(new Error('Lectura cancelada'));
        rejectDecode = null;
        if (stream) stream.getTracks().forEach(track => track.stop());
        stream = null;
        video.srcObject = null;
        panel.hidden = true;
        start.disabled = pending;
        camera.disabled = pending;
        photoButton.disabled = pending;
    }
    function decode(image) {
        return new Promise((resolve, reject) => {
            let timeout;
            const fail = error => { clearTimeout(timeout); rejectDecode = null; reject(error); };
            rejectDecode = fail;
            try {
                if (!worker) worker = new Worker(workerUrl);
                worker.onmessage = ({ data }) => {
                    clearTimeout(timeout);
                    rejectDecode = null;
                    if (data.error) reject(new Error(data.error));
                    else resolve(data.value);
                };
                worker.onerror = () => fail(new Error('No se pudo cargar el lector. Recarga la página.'));
                timeout = setTimeout(() => fail(new Error('La lectura tardó demasiado. Intenta con una foto más pequeña.')), 15000);
                worker.postMessage({ width: image.width, height: image.height, buffer: image.data.buffer }, [image.data.buffer]);
            } catch (error) { fail(error); }
        });
    }
    function pixels(source, width, height) {
        const scale = Math.min(1, 1920 / Math.max(width, height));
        canvas.width = Math.max(1, Math.round(width * scale));
        canvas.height = Math.max(1, Math.round(height * scale));
        context.drawImage(source, 0, 0, canvas.width, canvas.height);
        return context.getImageData(0, 0, canvas.width, canvas.height);
    }
    function identificar(value) {
        const match = buscarInstructor(select.options, value);
        cerrar();
        select.value = match.option ? match.option.value : '';
        select.dispatchEvent(new Event('change', { bubbles: true }));
        mensaje(match.error || 'Instructor identificado: ' + match.option.textContent.trim() + '. Revisa los datos y confirma el registro.', !!match.error);
        if (match.option) mostrarFormulario('Instructor identificado: ' + match.option.textContent.trim());
        else start.focus();
    }
    async function scan(current) {
        if (current !== generation) return;
        try {
            if (video.readyState >= 2 && video.videoWidth) {
                const value = await decode(pixels(video, video.videoWidth, video.videoHeight));
                if (current !== generation) return;
                if (value) {
                    // El motor ya valida checksum y dos líneas independientes
                    // del mismo fotograma: no esperar otro fotograma completo.
                    identificar(value);
                    return;
                }
            }
            loop = setTimeout(() => scan(current), 16);
        } catch (error) {
            if (current !== generation) return;
            cerrar();
            mensaje(error.message, true);
        }
    }
    async function abrir() {
        if (pending) return;
        cerrar();
        if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
            mensaje('La cámara necesita HTTPS y un navegador compatible. También puedes usar Leer foto del carnet.', true);
            return;
        }
        select.value = '';
        const current = generation;
        pending = true;
        start.disabled = camera.disabled = photoButton.disabled = true;
        panel.hidden = false;
        mensaje('Permite el acceso a la cámara para escanear el carnet.');
        try {
            const videoConstraints = { width: { ideal: 1920 }, height: { ideal: 1080 } };
            if (camera.value) videoConstraints.deviceId = { exact: camera.value };
            else videoConstraints.facingMode = { ideal: 'environment' };
            const acquired = await navigator.mediaDevices.getUserMedia({ audio: false, video: videoConstraints });
            if (current !== generation) { acquired.getTracks().forEach(track => track.stop()); return; }
            stream = acquired;
            const track = stream.getVideoTracks()[0];
            try {
                if (track?.getCapabilities?.().focusMode?.includes('continuous')) {
                    await track.applyConstraints({ advanced: [{ focusMode: 'continuous' }] });
                }
            } catch (_) { /* Cámara de foco fijo o ajuste no admitido. */ }
            if (current !== generation) return;
            video.srcObject = acquired;
            await video.play();
            if (current !== generation) return;
            try {
                const devices = await navigator.mediaDevices.enumerateDevices();
                if (current !== generation) return;
                camera.replaceChildren(new Option('Cámara automática', ''));
                devices.filter(d => d.kind === 'videoinput').forEach((d, i) => camera.add(new Option(d.label || 'Cámara ' + (i + 1), d.deviceId)));
                camera.value = track.getSettings?.().deviceId || '';
            } catch (_) { /* La cámara puede funcionar sin enumerar dispositivos. */ }
            if (current !== generation) return;
            mensaje('Centra el código completo y mantén el carnet quieto. Si se ve borroso, aléjalo lentamente o usa Leer foto del carnet.');
            deadline = setTimeout(() => {
                cerrar();
                mensaje('No se pudo leer con esta cámara. Prueba otra cámara o usa Leer foto del carnet con una imagen nítida.', true);
            }, 60000);
            scan(current);
        } catch (error) {
            if (current !== generation) return;
            cerrar();
            const messages = {
                NotAllowedError: 'Permiso de cámara denegado. Habilítalo o usa Leer foto del carnet.',
                NotFoundError: 'No se encontró una cámara. Usa Leer foto del carnet.',
                OverconstrainedError: 'La cámara seleccionada no está disponible. Selecciona Cámara automática.',
                NotReadableError: 'La cámara está ocupada. Cierra otras aplicaciones o usa Leer foto del carnet.'
            };
            mensaje(messages[error.name] || 'No se pudo abrir la cámara. Puedes usar Leer foto del carnet.', true);
        } finally {
            pending = false;
            start.disabled = !panel.hidden;
            camera.disabled = photoButton.disabled = false;
        }
    }
    start.addEventListener('click', abrir);
    el('registroManual').addEventListener('click', () => mostrarFormulario('Registro manual: selecciona el ambiente y el instructor.'));
    el('volverEscaneo').addEventListener('click', () => { mostrarEscaneo(); abrir(); });
    camera.addEventListener('change', abrir);
    photoButton.addEventListener('click', () => {
        cerrar();
        photo.value = '';
        photo.click();
    });
    photo.addEventListener('change', async () => {
        const file = photo.files[0];
        if (!file) return;
        cerrar();
        if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 16 * 1024 * 1024) {
            mensaje('Selecciona una foto JPG, PNG o WebP de hasta 16 MB.', true);
            return;
        }
        select.value = '';
        const current = generation;
        pending = true;
        start.disabled = camera.disabled = photoButton.disabled = true;
        mensaje('Leyendo la foto del carnet…');
        const url = URL.createObjectURL(file);
        try {
            const image = new Image();
            image.src = url;
            await image.decode();
            if (current !== generation) return;
            const value = await decode(pixels(image, image.naturalWidth, image.naturalHeight));
            if (current !== generation) return;
            if (value) identificar(value);
            else { cerrar(); mensaje('No se pudo leer el código de la foto. Usa una imagen enfocada del código completo y sus márgenes blancos.', true); }
        } catch (error) {
            if (current === generation) { cerrar(); mensaje('No se pudo procesar la foto. Prueba otra imagen JPG o PNG.', true); }
        } finally {
            URL.revokeObjectURL(url);
            pending = false;
            start.disabled = camera.disabled = photoButton.disabled = false;
        }
    });
    stop.addEventListener('click', () => { cerrar(); mensaje('Cámara cerrada. Puedes escanear nuevamente o leer una foto.'); });
    select.addEventListener('change', () => { cerrar(); mensaje('Revisa el instructor seleccionado antes de registrar la ocupación.'); });
    form.addEventListener('reset', mostrarEscaneo);
    form.addEventListener('submit', event => {
        if (details.disabled) { event.preventDefault(); event.stopImmediatePropagation(); }
    }, true);
    form.addEventListener('submit', cerrar);
    window.addEventListener('pagehide', cerrar);
    document.addEventListener('visibilitychange', () => { if (document.hidden && stream) { cerrar(); mensaje('Cámara cerrada. Pulsa escanear para continuar.'); } });
    if (modal) {
        let visible = false;
        const actualizarModal = () => {
            const next = !modal.hidden && getComputedStyle(modal).display !== 'none';
            if (next === visible) return;
            visible = next;
            mostrarEscaneo();
            if (next) abrir();
        };
        new MutationObserver(actualizarModal).observe(modal, { attributes: true, attributeFilter: ['style', 'hidden'] });
        actualizarModal();
    }
})();
