const btnMenu   = document.getElementById("btnMenu");
const modalMenu = document.getElementById("modalMenu");
if(btnMenu && modalMenu) {
    btnMenu.addEventListener("click", () => modalMenu.classList.add("active"));
    modalMenu.addEventListener("click", (e) => { if(e.target === modalMenu) modalMenu.classList.remove("active"); });
}

const mensajeAlert = document.getElementById('mensajeAlert');
if(mensajeAlert) {
    setTimeout(() => { mensajeAlert.style.opacity = '0'; setTimeout(() => mensajeAlert.style.display = 'none', 300); }, 5000);
}

function abrirModal() {
    document.getElementById('modalVocero').style.display = 'flex';
    document.getElementById('modalTitulo').textContent   = 'Nuevo Vocero';
    document.getElementById('btnTexto').textContent      = ' Guardar Vocero';
    document.getElementById('accion').value       = 'crear';
    document.getElementById('voceroId').value     = '';
    document.getElementById('voceroNombre').value    = '';
    document.getElementById('voceroCedula').value    = '';
    document.getElementById('voceroEmail').value     = '';
    document.getElementById('voceroTelefono').value  = '';
    document.getElementById('voceroContrasena').value = '';
    document.getElementById('voceroPrograma').value  = '';
    document.getElementById('voceroFicha').value     = '';
    document.getElementById('contrasenaAyuda').style.display = 'none';
    document.getElementById('voceroContrasena').required     = true;
}

function cerrarModal() {
    document.getElementById('modalVocero').style.display = 'none';
}

function editar(id) {
    document.getElementById('modalVocero').style.display  = 'flex';
    document.getElementById('modalTitulo').textContent    = 'Editar Vocero';
    document.getElementById('btnTexto').textContent       = ' Actualizar Vocero';
    document.getElementById('accion').value   = 'editar';
    document.getElementById('voceroId').value = id;
    document.getElementById('contrasenaAyuda').style.display  = 'block';
    document.getElementById('voceroContrasena').required = false;

    fetch(`../../controllers/ObtenerVocero.php?id=${id}`)
        .then(r => r.json())
        .then(data => {
            if(data.success) {
                const v = data.vocero;
                document.getElementById('voceroNombre').value   = v.nombre;
                document.getElementById('voceroCedula').value   = v.cedula;
                document.getElementById('voceroEmail').value    = v.email || '';
                document.getElementById('voceroTelefono').value = v.telefono || '';
                document.getElementById('voceroPrograma').value = v.programa_estudio || '';
                document.getElementById('voceroFicha').value    = v.ficha_id || '';
            }
        });
}

function eliminar(id, nombre) {
    if(confirm(`¿Eliminar al vocero "${nombre}"`)) {
        window.location.href = `../../controllers/EliminarVocero.php?id=${id}&sede_id=${sedeId}&csrf_token=${csrfToken}`;
    }
}

function activar(id) {
    if(confirm('¿Activar este vocero')) {
        window.location.href = `../../controllers/CambiarEstadoVocero.php?id=${id}&estado=activo&sede_id=${sedeId}&csrf_token=${csrfToken}`;
    }
}

function desactivar(id) {
    if(confirm('¿Desactivar este vocero')) {
        window.location.href = `../../controllers/CambiarEstadoVocero.php?id=${id}&estado=inactivo&sede_id=${sedeId}&csrf_token=${csrfToken}`;
    }
}

function filtrar() {
    const buscar = document.getElementById('buscarCelador').value.toLowerCase();
    const estado = document.getElementById('filtrarEstado').value;
    document.querySelectorAll('.celador-card').forEach(card => {
        const matchNombre = card.dataset.nombre.includes(buscar) || card.dataset.cedula.includes(buscar);
        const matchEstado = !estado || card.dataset.estado === estado;
        card.style.display = (matchNombre && matchEstado) ? '' : 'none';
    });
}

function ordenar() {
    const criterio = document.getElementById('ordenar').value;
    const grid = document.getElementById('verocerosGrid');
    const cards = Array.from(grid.querySelectorAll('.celador-card'));
    cards.sort((a, b) => {
        if(criterio === 'nombre') return a.dataset.nombre.localeCompare(b.dataset.nombre);
        if(criterio === 'cedula') return a.dataset.cedula.localeCompare(b.dataset.cedula);
        if(criterio === 'estado') return a.dataset.estado.localeCompare(b.dataset.estado);
        return 0;
    });
    cards.forEach(c => grid.appendChild(c));
}

const formVocero = document.getElementById('formVocero');
if(formVocero) {
    formVocero.addEventListener('submit', function(e) {
        const accion = document.getElementById('accion').value;
        const clave  = document.getElementById('voceroContrasena').value.trim();
        if(accion === 'crear' && clave === '') {
            e.preventDefault();
            alert('La contraseña es obligatoria.');
            document.getElementById('voceroContrasena').focus();
        }
    });
}

document.addEventListener('keydown', (e) => { if(e.key === 'Escape') cerrarModal(); });
const modalVocero = document.getElementById('modalVocero');
if(modalVocero) {
    modalVocero.addEventListener('click', function(e) { if(e.target === this) cerrarModal(); });
}
