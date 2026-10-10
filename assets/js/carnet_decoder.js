/* Code 128: contraste local y varias líneas independientes; siempre verifica checksum. */
(function (scope) {
    'use strict';
    const ZX = typeof module !== 'undefined' && module.exports
        ? require('./lib/zxing-library-0.21.3.min.js') : scope.ZXing;
    function decodeRegion(data, width, height, fast = false) {
        if (!width || !height || data.length !== width * height * 4) return null;
        const reader = new ZX.Code128Reader();
        const votes = new Map();
        // Repetir con ejes intercambiados permite códigos verticales.
        for (const vertical of [false, true]) {
            const length = vertical ? height : width;
            const cross = vertical ? width : height;
            const positions = Array.from({ length: 31 }, (_, i) => Math.round((i + 1) * cross / 32));
            if (fast) positions.sort((a, b) => Math.abs(a - cross / 2) - Math.abs(b - cross / 2));
            for (const y of positions) {
                const row = new Float64Array(length);
                for (let x = 0; x < length; x++) {
                    let sum = 0, count = 0;
                    for (let dy = -2; dy <= 2; dy++) {
                        const yy = Math.max(0, Math.min(cross - 1, y + dy));
                        const index = (vertical ? x * width + yy : yy * width + x) * 4;
                        sum += Math.round(.299 * data[index] + .587 * data[index + 1] + .114 * data[index + 2]);
                        count++;
                    }
                    row[x] = Math.floor(sum / count);
                }
                let min = 255, max = 0;
                row.forEach(v => { min = Math.min(min, v); max = Math.max(max, v); });
                if (max - min < 15) continue;
                let value = null;
                for (const radius of (fast ? [0] : [15, 30, 50, 90])) {
                    const local = new Float64Array(length);
                    if (radius) {
                        const sigma = .3 * (radius - 1) + .8;
                        const weights = Array.from({ length: 2 * radius + 1 }, (_, i) => Math.exp(-((i - radius) ** 2) / (2 * sigma * sigma)));
                        const total = weights.reduce((a, b) => a + b, 0);
                        for (let x = 0; x < length; x++) {
                            let sum = 0;
                            for (let k = -radius; k <= radius; k++) sum += row[Math.max(0, Math.min(length - 1, x + k))] * weights[k + radius];
                            local[x] = Math.round(sum / total);
                        }
                    }
                    for (const offset of [0, 3, 8]) {
                        const bits = new ZX.BitArray(length + 80);
                        for (let x = 0; x < length; x++) {
                            const threshold = radius ? local[x] - offset : (min + max) / 2 - offset;
                            if (row[x] <= threshold) bits.set(x + 40);
                        }
                        for (let reverse = 0; reverse < 2; reverse++) {
                            try {
                                const result = reader.decodeRow(y, bits, new Map());
                                const text = result.getText().trim();
                                if (/^\d+$/.test(text)) { value = text; break; }
                            } catch (_) { /* No se encontró un Code 128 con checksum válido. */ }
                            bits.reverse();
                        }
                        if (value) break;
                    }
                    if (value) break;
                }
                if (value) {
                    const count = (votes.get(value) || 0) + 1;
                    votes.set(value, count);
                    if (count >= 2) return value;
                }
            }
        }
        return null;
    }
    function decode(data, width, height) {
        // Primero todas las líneas con umbral simple: no gastar en contraste
        // complejo sobre el fondo mientras el código nítido está en el centro.
        const direct = decodeRegion(data, width, height, true);
        if (direct) return direct;
        const regions = [{ data, width, height }];
        // Quita bordes del carnet/fondo; nunca recorta la única oportunidad de lectura.
        for (const margin of [.05, .1, .15]) {
            const left = Math.floor(width * margin), top = Math.floor(height * margin);
            const w = width - left * 2, h = height - top * 2;
            const crop = new Uint8ClampedArray(w * h * 4);
            for (let y = 0; y < h; y++) crop.set(data.subarray(((top + y) * width + left) * 4, ((top + y) * width + left + w) * 4), y * w * 4);
            const value = decodeRegion(crop, w, h, true);
            if (value) return value;
            regions.push({ data: crop, width: w, height: h });
        }
        for (const region of regions) {
            const value = decodeRegion(region.data, region.width, region.height);
            if (value) return value;
        }
        return null;
    }
    if (typeof module !== 'undefined' && module.exports) module.exports = { decode };
    else scope.SagaCarnetDecoder = { decode };
})(typeof self !== 'undefined' ? self : globalThis);
