const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const { decode } = require('../assets/js/carnet_decoder');
const php = process.env.PHP_BINARY || 'C:/xampp/php/php.exe';
for (const code of ['0098765432', '1234567', '9876543210']) {
    const barcode = JSON.parse(execFileSync(php, ['-r',
        "require 'vendor/tecnickcom/tcpdf/tcpdf_barcodes_1d.php'; $b=new TCPDFBarcode($argv[1],'C128'); echo json_encode($b->getBarcodeArray());", code
    ], { cwd: path.resolve(__dirname, '..'), encoding: 'utf8' }));
    const width = barcode.maxw * 3 + 80, height = 100;
    const data = new Uint8ClampedArray(width * height * 4).fill(255);
    let x = 40;
    for (const bar of barcode.bcode) {
        for (let dx = 0; dx < bar.w * 3; dx++) {
            for (let y = 10; y < 90; y++) {
                const index = (y * width + x + dx) * 4;
                const value = Math.round((bar.t ? 40 : 170) + (x / width) * 50);
                data[index] = data[index + 1] = data[index + 2] = value;
            }
        }
        x += bar.w * 3;
    }
    assert.equal(decode(data, width, height), code);
    for (let y = 0; y < height; y++) for (let x = Math.floor(width * .4); x < width * .6; x++) {
        const i = (y * width + x) * 4;
        data[i] = data[i + 1] = data[i + 2] = 255;
    }
    assert.equal(decode(data, width, height), null);
}
console.log('OK: Code 128 sintéticos, longitudes distintas, ceros iniciales, contraste desigual y códigos dañados.');
