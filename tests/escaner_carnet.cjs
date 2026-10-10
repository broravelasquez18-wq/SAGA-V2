const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const { buscarInstructor } = require('../assets/js/escaner_carnet.js');
const instructor = { value: '7', dataset: { cedula: '0012345' }, textContent: 'Instructor de prueba' };
assert.equal(buscarInstructor([instructor], ' 0012345\r\n').option, instructor);
for (const code of ['12345', '', 'CC0012345', '0012345;Nombre', '99999']) {
    assert.ok(buscarInstructor([instructor], code).error, code);
}
assert.ok(buscarInstructor([instructor, { ...instructor, value: '8' }], '0012345').error);

console.log('OK: coincidencia exacta, ceros iniciales, desconocidos y duplicados.');
