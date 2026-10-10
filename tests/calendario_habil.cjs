const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const context = vm.createContext({});
vm.runInContext(fs.readFileSync(path.join(root, 'assets/js/calendario_habil.js'), 'utf8'), context);
const php = process.env.PHP_BINARY || 'C:/xampp/php/php.exe';
const expected = JSON.parse(execFileSync(php, ['-r',
    'require "config/festivos.php"; $r=[]; for($y=2025;$y<=2035;$y++)$r[$y]=festivos_colombia($y); echo json_encode($r);'
], { cwd: root, encoding: 'utf8' }));
let count = 0;
for (let year=2025; year<=2035; year++) {
    const dates = new Set(expected[year]);
    for (const d=new Date(Date.UTC(year,0,1)); d.getUTCFullYear()===year; d.setUTCDate(d.getUTCDate()+1)) {
        const date=d.toISOString().slice(0,10);
        assert.equal(context.sagaEsFestivo(date), dates.has(date), date);
        count++;
    }
}
console.log(`OK: ${count} fechas con el mismo calendario PHP y JavaScript (2025-2035).`);
