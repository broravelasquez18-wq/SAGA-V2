// NODE_PATH debe apuntar a una instalación de playwright y sharp.
// Uso: node tests/carnet_browser.cjs <imagen de prueba> <cedula esperada>
// Las imágenes personales se leen desde su ubicación original, sin copiarlas al proyecto.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const { chromium } = require('playwright');
const sharp = require('sharp');
const root = path.resolve(__dirname, '..');
const sample = process.argv[2], expected = process.argv[3];
if (!sample || !/^\d+$/.test(expected || '')) throw new Error('Indica imagen y cédula esperada');
const { decode } = require('../assets/js/carnet_decoder');
(async () => {
    for (const angle of [0, 90, 180]) {
        const { data, info } = await sharp(sample).rotate(angle).ensureAlpha().raw().toBuffer({ resolveWithObject: true });
        assert.equal(decode(data, info.width, info.height), expected, 'Imagen real, giro ' + angle);
    }
    assert.equal(decode(new Uint8ClampedArray(400 * 100 * 4).fill(255), 400, 100), null);
    const partial = fs.readFileSync(path.join(root, 'views/celador/_escaner_carnet.php'), 'utf8');
    const html = `<!doctype html><meta charset="UTF-8"><link rel="stylesheet" href="/assets/css/escaner_carnet.css"><div id="modalOcupacion"><form>${partial}<fieldset id="datosOcupacion" hidden disabled><div class="carnet-resumen"><p id="resumenCarnet"></p><button type="button" id="volverEscaneo">Volver a escanear</button></div><input name="fecha" value="2026-10-15"><select name="usuario_id" required><option value="">Selecciona</option><option value="7" data-cedula="${expected}">Instructor de prueba</option></select></fieldset></form></div><script src="/assets/js/escaner_carnet.js"></script>`;
    const server = http.createServer((req, res) => {
        const url = new URL(req.url, 'http://localhost');
        if (url.pathname === '/') { res.setHeader('Content-Type', 'text/html; charset=utf-8'); res.end(html); return; }
        if (url.pathname === '/sample') { res.setHeader('Content-Type', 'image/png'); res.end(fs.readFileSync(sample)); return; }
        if (!/^\/assets\/[a-zA-Z0-9_./-]+$/.test(url.pathname) || url.pathname.includes('..')) { res.writeHead(404).end(); return; }
        const file = path.join(root, url.pathname);
        res.setHeader('Content-Type', file.endsWith('.js') ? 'text/javascript' : 'text/css');
        if (!fs.existsSync(file)) { res.writeHead(404).end(); return; }
        res.end(fs.readFileSync(file));
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    let browser;
    try {
        browser = await chromium.launch({ channel: 'chrome', headless: true });
        const page = await browser.newPage();
        await page.addInitScript(() => {
            navigator.mediaDevices.getUserMedia = async () => { throw new DOMException('Permiso denegado', 'NotAllowedError'); };
        });
        const errors = [];
        page.on('pageerror', e => errors.push(e.message));
        await page.goto(`http://127.0.0.1:${server.address().port}`);
        await page.waitForFunction(() => document.getElementById('estadoCarnet').dataset.error === 'true');
        assert.equal(await page.locator('#datosOcupacion').isVisible(), false);
        await page.locator('#registroManual').click();
        assert.equal(await page.locator('#datosOcupacion').isVisible(), true);
        assert.equal(await page.locator('[name=usuario_id]').isEnabled(), true);
        await page.locator('#volverEscaneo').click();
        await page.waitForFunction(() => document.getElementById('estadoCarnet').dataset.error === 'true');
        assert.equal(await page.locator('[name=fecha]').inputValue(), '2026-10-15');
        await page.locator('#fotoCarnet').setInputFiles(sample);
        await page.waitForFunction(() => document.querySelector('[name=usuario_id]').value === '7');
        assert.match(await page.locator('#estadoCarnet').textContent(), /Instructor identificado/);
        assert.equal(await page.locator('#escanerCarnet').isVisible(), false);
        assert.equal(await page.locator('#datosOcupacion').isVisible(), true);
        await page.locator('#volverEscaneo').click();
        await page.waitForFunction(() => document.getElementById('estadoCarnet').dataset.error === 'true');
        // No debe conservar el instructor anterior si el carnet ya no corresponde.
        await page.locator('[name=usuario_id] option[value="7"]').evaluate(e => e.dataset.cedula = '0000');
        await page.locator('#fotoCarnet').setInputFiles([]);
        await page.locator('#fotoCarnet').setInputFiles(sample);
        await page.waitForFunction(() => document.getElementById('estadoCarnet').dataset.error === 'true');
        assert.equal(await page.locator('[name=usuario_id]').inputValue(), '');
        assert.equal(await page.locator('#datosOcupacion').isVisible(), false);
        await page.locator('[name=usuario_id] option[value="7"]').evaluate((e, id) => e.dataset.cedula = id, expected);
        // Fuente de vídeo reproducible, sin depender de una webcam física.
        await page.evaluate(async () => {
            const OriginalWorker = window.Worker;
            window.frameReads = 0;
            window.Worker = class extends OriginalWorker {
                postMessage(...args) { window.frameReads++; return super.postMessage(...args); }
            };
            const image = new Image(); image.src = '/sample'; await image.decode();
            const canvas = document.createElement('canvas'); canvas.width = image.width; canvas.height = image.height;
            canvas.getContext('2d').drawImage(image, 0, 0);
            window.testVideoTimer = setInterval(() => canvas.getContext('2d').drawImage(image, 0, 0), 100);
            window.testTracks = [];
            navigator.mediaDevices.getUserMedia = async () => {
                const stream = canvas.captureStream(10); window.testTracks.push(...stream.getTracks()); return stream;
            };
            navigator.mediaDevices.enumerateDevices = async () => [{ kind: 'videoinput', deviceId: 'fake', label: 'Cámara de prueba' }];
        });
        await page.locator('#iniciarEscaneo').click();
        await page.waitForFunction(() => document.querySelector('[name=usuario_id]').value === '7').catch(async error => {
            console.log(await page.evaluate(() => ({status: document.getElementById('estadoCarnet').textContent,
                ready: document.getElementById('videoCarnet').readyState, width: document.getElementById('videoCarnet').videoWidth})));
            throw error;
        });
        assert.equal(await page.evaluate(() => window.testTracks.every(t => t.readyState === 'ended')), true);
        assert.equal(await page.evaluate(() => window.frameReads), 1, 'Aceptar el primer fotograma validado sin esperar un segundo');
        // Cierre mientras el navegador todavía espera permiso.
        await page.evaluate(() => {
            document.querySelector('[name=usuario_id]').value = '';
            navigator.mediaDevices.getUserMedia = () => new Promise(resolve => window.allowCamera = resolve);
        });
        await page.locator('#volverEscaneo').click();
        await page.locator('#detenerEscaneo').click();
        await page.evaluate(() => {
            const stream = document.createElement('canvas').captureStream();
            window.lateTrack = stream.getTracks()[0]; window.allowCamera(stream);
        });
        await page.waitForFunction(() => window.lateTrack.readyState === 'ended');
        assert.equal(await page.locator('#panelCamara').isVisible(), false);
        await page.locator('#registroManual').click();
        await page.evaluate(() => document.getElementById('modalOcupacion').style.display = 'none');
        await page.evaluate(() => {
            navigator.mediaDevices.getUserMedia = async () => { throw new DOMException('Denied', 'NotAllowedError'); };
            document.getElementById('modalOcupacion').style.display = 'flex';
        });
        await page.waitForFunction(() => document.getElementById('estadoCarnet').dataset.error === 'true');
        assert.equal(await page.locator('#datosOcupacion').isVisible(), false);
        assert.deepEqual(errors, []);
        console.log('OK: imagen real y rotaciones; foto; desconocido limpia selección; primer fotograma validado; liberación y cancelación tardía; sin errores de navegador.');
    } finally { await browser?.close(); await new Promise(resolve => server.close(resolve)); }
})().catch(error => { console.error(error); process.exitCode = 1; });
