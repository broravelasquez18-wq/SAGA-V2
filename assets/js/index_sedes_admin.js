const modal = document.getElementById('modalSede');
const formSede = document.getElementById('formSede');
const tituloModal = document.getElementById('tituloModal');
function abrirModal() { modal.style.display = 'flex'; document.body.classList.add('modal-abierto'); setTimeout(() => document.getElementById('nombre').focus(), 50); }
function cerrarModal() { modal.style.display = 'none'; document.body.classList.remove('modal-abierto'); }
function abrirModalCrear() { tituloModal.textContent = 'Nueva sede'; formSede.reset(); document.getElementById('sede_id').value = ''; abrirModal(); }
document.getElementById('btnNuevaSede')?.addEventListener('click', abrirModalCrear);
document.getElementById('btnPrimeraSede')?.addEventListener('click', abrirModalCrear);
document.getElementById('cerrarModal').addEventListener('click', cerrarModal);
document.getElementById('btnCancelar').addEventListener('click', cerrarModal);
modal.addEventListener('click', event => { if (event.target === modal) cerrarModal(); });
document.addEventListener('keydown', event => { if (event.key === 'Escape' && modal.style.display === 'flex') cerrarModal(); });
function editarSede(id) { tituloModal.textContent = 'Editar sede'; fetch('../../controllers/ObtenerSede.php?id=' + id).then(response => response.json()).then(data => { if (!data.success) throw new Error(); const sede = data.sede; ['id','nombre','ciudad','direccion','telefono','email','estado'].forEach(campo => document.getElementById(campo === 'id' ? 'sede_id' : campo).value = sede[campo] || ''); abrirModal(); }).catch(() => alert('No fue posible cargar los datos de la sede.')); }
function confirmarEliminar(id, nombre) { if (confirm('¿Eliminar la sede "' + nombre + '"?\n\nEsta acción no se puede deshacer.')) window.location.href = '../../controllers/EliminarSede.php?id=' + id + '&csrf_token=' + csrfToken; }
const buscador = document.getElementById('buscarSede'); const tarjetas = Array.from(document.querySelectorAll('.sede-card')); const contador = document.getElementById('contadorSedes'); const sinResultados = document.getElementById('sinResultados');
buscador?.addEventListener('input', function() { const termino = this.value.trim().toLocaleLowerCase(); let visibles = 0; tarjetas.forEach(tarjeta => { const visible = tarjeta.dataset.busqueda.includes(termino); tarjeta.hidden = !visible; if (visible) visibles++; }); if (contador) contador.textContent = visibles; if (sinResultados) sinResultados.hidden = visibles !== 0; });
