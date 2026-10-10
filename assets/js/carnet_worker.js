importScripts('lib/zxing-library-0.21.3.min.js', 'carnet_decoder.js?v=20261010-4');
self.onmessage = ({ data }) => {
    try {
        const value = self.SagaCarnetDecoder.decode(new Uint8ClampedArray(data.buffer), data.width, data.height);
        self.postMessage({ value });
    } catch (_) {
        self.postMessage({ error: 'No se pudo procesar la imagen.' });
    }
};
