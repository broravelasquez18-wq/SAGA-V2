// ════════════════════════════════════════════════════════════════════
// ACTUALIZACIÓN PARA celadores_admin.js
// Agregar/reemplazar estas funciones
// ════════════════════════════════════════════════════════════════════

// Menu
const btnMenu = document.getElementById("btnMenu");
const modalMenu = document.getElementById("modalMenu");

if(btnMenu && modalMenu) {
    btnMenu.addEventListener("click", () => modalMenu.classList.add("active"));
    modalMenu.addEventListener("click", (e) => {
        if(e.target === modalMenu) modalMenu.classList.remove("active");
    });
}

// Auto-ocultar mensajes
const mensajeAlert = document.getElementById('mensajeAlert');
if(mensajeAlert) {
    setTimeout(() => {
        mensajeAlert.style.opacity = '0';
        setTimeout(() => mensajeAlert.style.display = 'none', 300);
    }, 5000);
}

// Modal
function abrirModal() {
    document.getElementById('modalCelador').style.display = 'flex';
    document.getElementById('modalTitulo').textContent = 'Nuevo Celador';
    document.getElementById('btnTexto').textContent = ' Guardar Celador';
    document.getElementById('accion').value = 'crear';
    document.getElementById('celadorId').value = '';
    document.getElementById('celadorNombre').value = '';
    document.getElementById('celadorCedula').value = '';
    document.getElementById('celadorEmail').value = '';
    document.getElementById('celadorTelefono').value = '';
    document.getElementById('celadorContrasena').value = '';
    document.getElementById('celadorContrato').value = '';
    document.getElementById('celadorFechaInicio').value = '';
    document.getElementById('celadorFechaFin').value = '';
    document.getElementById('fechasContrato').style.display = 'none';
    document.getElementById('mensajeIndefinido').style.display = 'none';
    document.getElementById('contrasenaAyuda').style.display = 'none';
    document.getElementById('celadorContrasena').required = true;
}

function cerrarModal() {
    document.getElementById('modalCelador').style.display = 'none';
}

function editar(id) {
    document.getElementById('modalCelador').style.display = 'flex';
    document.getElementById('modalTitulo').textContent = 'Editar Celador';
    document.getElementById('btnTexto').textContent = ' Actualizar Celador';
    document.getElementById('accion').value = 'editar';
    document.getElementById('celadorId').value = id;
    document.getElementById('contrasenaAyuda').style.display = 'block';
    document.getElementById('celadorContrasena').required = false;

    fetch(`../../controllers/ObtenerCelador.php?id=${id}`)
        .then(response => response.json())
        .then(data => {
            if(data.success) {
                document.getElementById('celadorNombre').value = data.celador.nombre;
                document.getElementById('celadorCedula').value = data.celador.cedula;
                document.getElementById('celadorEmail').value = data.celador.email || '';
                document.getElementById('celadorTelefono').value = data.celador.telefono || '';
                document.getElementById('celadorContrato').value = data.celador.tipo_contrato;
                
                if(data.celador.tipo_contrato == 'contratista') {
                    document.getElementById('fechasContrato').style.display = 'block';
                    document.getElementById('mensajeIndefinido').style.display = 'none';
                    document.getElementById('celadorFechaInicio').value = data.celador.fecha_inicio_contrato;
                    document.getElementById('celadorFechaFin').value = data.celador.fecha_fin_contrato;
                } else {
                    document.getElementById('fechasContrato').style.display = 'none';
                    document.getElementById('mensajeIndefinido').style.display = 'block';
                }
            }
        });
}

// ⭐ ELIMINAR CON SEDE_ID
function eliminar(id, nombre) {
    if(confirm(`¿Eliminar al celador "${nombre}"`)) {
        window.location.href = `../../controllers/EliminarCelador.php?id=${id}&sede_id=${sedeId}&csrf_token=${csrfToken}`;
    }
}

// ⭐ ACTIVAR/DESACTIVAR CON SEDE_ID
function activar(id) {
    if(confirm('¿Activar este celador')) {
        window.location.href = `../../controllers/CambiarEstadoCelador.php?id=${id}&estado=activo&sede_id=${sedeId}&csrf_token=${csrfToken}`;
    }
}

function desactivar(id) {
    if(confirm('¿Desactivar este celador')) {
        window.location.href = `../../controllers/CambiarEstadoCelador.php?id=${id}&estado=inactivo&sede_id=${sedeId}&csrf_token=${csrfToken}`;
    }
}

// Cambiar tipo de contrato
function cambiarTipoContrato() {
    const tipo = document.getElementById('celadorContrato').value;
    if(tipo == 'contratista') {
        document.getElementById('fechasContrato').style.display = 'block';
        document.getElementById('mensajeIndefinido').style.display = 'none';
        document.getElementById('celadorFechaInicio').required = true;
        document.getElementById('celadorFechaFin').required = true;
    } else if(tipo == 'planta') {
        document.getElementById('fechasContrato').style.display = 'none';
        document.getElementById('mensajeIndefinido').style.display = 'block';
        document.getElementById('celadorFechaInicio').required = false;
        document.getElementById('celadorFechaFin').required = false;
    } else {
        document.getElementById('fechasContrato').style.display = 'none';
        document.getElementById('mensajeIndefinido').style.display = 'none';
    }
}

// Filtrar
function filtrar() {
    const busqueda = document.getElementById('buscarCelador').value.toLowerCase();
    const estado = document.getElementById('filtrarEstado').value;
    const contrato = document.getElementById('filtrarContrato').value;
    
    const cards = document.querySelectorAll('.celador-card');

    cards.forEach(card => {
        const nombre = card.dataset.nombre;
        const cedula = card.dataset.cedula;
        const estadoCard = card.dataset.estado;
        const contratoCard = card.dataset.contrato;
        
        const matchBusqueda = nombre.includes(busqueda) || cedula.includes(busqueda);
        const matchEstado = !estado || estadoCard === estado;
        const matchContrato = !contrato || contratoCard === contrato;
        
        if(matchBusqueda && matchEstado && matchContrato) {
            card.style.display = 'block';
        } else {
            card.style.display = 'none';
        }
    });
}

// Ordenar
function ordenar() {
    const criterio = document.getElementById('ordenar').value;
    if(!criterio) return;

    const grid = document.getElementById('celadoresGrid');
    const cards = Array.from(document.querySelectorAll('.celador-card'));

    cards.sort((a, b) => {
        if(criterio === 'nombre') return a.dataset.nombre.localeCompare(b.dataset.nombre);
        if(criterio === 'cedula') return a.dataset.cedula.localeCompare(b.dataset.cedula);
        if(criterio === 'estado') return a.dataset.estado.localeCompare(b.dataset.estado);
    });

    cards.forEach(card => grid.appendChild(card));
}

// Validar formulario antes de enviar
const formCelador = document.getElementById('formCelador');
if(formCelador) {
    formCelador.addEventListener('submit', function(e) {
        const accion = document.getElementById('accion').value;
        const clave  = document.getElementById('celadorContrasena').value.trim();
        if(accion === 'crear' && clave === '') {
            e.preventDefault();
            alert('La contraseña es obligatoria. Por favor ingresala antes de guardar.');
            document.getElementById('celadorContrasena').focus();
        }
    });
}

// Cerrar modal
document.addEventListener('keydown', (e) => {
    if(e.key === 'Escape') cerrarModal();
});

const modalCelador = document.getElementById('modalCelador');
if(modalCelador) {
    modalCelador.addEventListener('click', function(e) {
        if(e.target === this) cerrarModal();
    });
}
