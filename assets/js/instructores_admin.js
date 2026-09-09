// ════════════════════════════════════════════════════════════════════
// instructores_admin.js - VERSIÓN FINAL
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
    document.getElementById('modalInstructor').style.display = 'flex';
    document.getElementById('modalTitulo').textContent = 'Nuevo Instructor';
    document.getElementById('btnTexto').textContent = ' Guardar Instructor';
    document.getElementById('accion').value = 'crear';
    document.getElementById('instructorId').value = '';
    document.getElementById('instructorNombre').value = '';
    document.getElementById('instructorCedula').value = '';
    document.getElementById('instructorEmail').value = '';
    document.getElementById('instructorTelefono').value = '';
    document.getElementById('instructorEstudio').value = '';
    document.getElementById('instructorContrasena').value = '';
    document.getElementById('instructorContrato').value = '';
    document.getElementById('instructorFechaInicio').value = '';
    document.getElementById('instructorFechaFin').value = '';
    document.getElementById('fechasContrato').style.display = 'none';
    document.getElementById('mensajeIndefinido').style.display = 'none';
    document.getElementById('contrasenaAyuda').style.display = 'none';
    document.getElementById('instructorContrasena').required = true;
}

function cerrarModal() {
    document.getElementById('modalInstructor').style.display = 'none';
}

function editar(id) {
    document.getElementById('modalInstructor').style.display = 'flex';
    document.getElementById('modalTitulo').textContent = 'Editar Instructor';
    document.getElementById('btnTexto').textContent = ' Actualizar Instructor';
    document.getElementById('accion').value = 'editar';
    document.getElementById('instructorId').value = id;
    document.getElementById('contrasenaAyuda').style.display = 'block';
    document.getElementById('instructorContrasena').required = false;

    fetch(`../../controllers/ObtenerInstructor.php?id=${id}`)
        .then(response => response.json())
        .then(data => {
            if(data.success) {
                document.getElementById('instructorNombre').value = `${data.instructor.nombre ?? ''} ${data.instructor.apellido ?? ''}`.trim();
                document.getElementById('instructorCedula').value = data.instructor.cedula;
                document.getElementById('instructorEmail').value = data.instructor.email;
                document.getElementById('instructorTelefono').value = data.instructor.telefono ?? '';
                document.getElementById('instructorEstudio').value = data.instructor.nivel_estudio;
                document.getElementById('instructorContrato').value = data.instructor.tipo_contrato;
                
                if(data.instructor.tipo_contrato == 'contratista') {
                    document.getElementById('fechasContrato').style.display = 'block';
                    document.getElementById('mensajeIndefinido').style.display = 'none';
                    document.getElementById('instructorFechaInicio').value = data.instructor.fecha_inicio_contrato;
                    document.getElementById('instructorFechaFin').value = data.instructor.fecha_fin_contrato;
                } else {
                    document.getElementById('fechasContrato').style.display = 'none';
                    document.getElementById('mensajeIndefinido').style.display = 'block';
                }
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error al cargar los datos del instructor');
        });
}

// ⭐ ELIMINAR CON SEDE_ID
function eliminar(id, nombre) {
    if(confirm(`¿Eliminar al instructor "${nombre}"`)) {
        window.location.href = `../../controllers/EliminarInstructor.php?id=${id}&sede_id=${sedeId}&csrf_token=${csrfToken}`;
    }
}

// ⭐ ACTIVAR CON SEDE_ID
function activar(id) {
    if(confirm('¿Activar este instructor')) {
        window.location.href = `../../controllers/CambiarEstadoInstructor.php?id=${id}&estado=activo&sede_id=${sedeId}&csrf_token=${csrfToken}`;
    }
}

// ⭐ DESACTIVAR CON SEDE_ID
function desactivar(id) {
    if(confirm('¿Desactivar este instructor')) {
        window.location.href = `../../controllers/CambiarEstadoInstructor.php?id=${id}&estado=inactivo&sede_id=${sedeId}&csrf_token=${csrfToken}`;
    }
}

// Cambiar tipo de contrato
function cambiarTipoContrato() {
    const tipo = document.getElementById('instructorContrato').value;
    if(tipo == 'contratista') {
        document.getElementById('fechasContrato').style.display = 'block';
        document.getElementById('mensajeIndefinido').style.display = 'none';
        document.getElementById('instructorFechaInicio').required = true;
        document.getElementById('instructorFechaFin').required = true;
    } else if(tipo == 'planta') {
        document.getElementById('fechasContrato').style.display = 'none';
        document.getElementById('mensajeIndefinido').style.display = 'block';
        document.getElementById('instructorFechaInicio').required = false;
        document.getElementById('instructorFechaFin').required = false;
    } else {
        document.getElementById('fechasContrato').style.display = 'none';
        document.getElementById('mensajeIndefinido').style.display = 'none';
    }
}

// Filtrar
function filtrar() {
    const busqueda = document.getElementById('buscarInstructor').value.toLowerCase();
    const estado = document.getElementById('filtrarEstado').value;
    const contrato = document.getElementById('filtrarContrato').value;
    const estudio = document.getElementById('filtrarEstudio').value;
    
    const cards = document.querySelectorAll('.instructor-card');

    cards.forEach(card => {
        const nombre = card.dataset.nombre;
        const cedula = card.dataset.cedula;
        const estadoCard = card.dataset.estado;
        const contratoCard = card.dataset.contrato;
        const estudioCard = card.dataset.estudio;
        
        const matchBusqueda = nombre.includes(busqueda) || cedula.includes(busqueda);
        const matchEstado = !estado || estadoCard === estado;
        const matchContrato = !contrato || contratoCard === contrato;
        const matchEstudio = !estudio || estudioCard === estudio;
        
        if(matchBusqueda && matchEstado && matchContrato && matchEstudio) {
            card.style.display = 'block';
        } else {
            card.style.display = 'none';
        }
    });
}

// Validación del formulario antes de enviar
const formInstructor = document.querySelector('#modalInstructor form');
if(formInstructor) {
    formInstructor.addEventListener('submit', function(e) {
        const accion = document.getElementById('accion').value;
        const password = document.getElementById('instructorContrasena').value.trim();
        const tipoContrato = document.getElementById('instructorContrato').value;
        const fechaInicio = document.getElementById('instructorFechaInicio').value;
        const fechaFin = document.getElementById('instructorFechaFin').value;

        if(accion === 'crear' && !password) {
            e.preventDefault();
            alert('La contraseña es obligatoria para crear un instructor.');
            document.getElementById('instructorContrasena').focus();
            return;
        }

        if(password && password.length < 6) {
            e.preventDefault();
            alert('La contraseña debe tener mínimo 6 caracteres.');
            document.getElementById('instructorContrasena').focus();
            return;
        }

        if(tipoContrato === 'contratista' && (!fechaInicio || !fechaFin)) {
            e.preventDefault();
            alert('Las fechas de contrato son obligatorias para instructores contratistas.');
            return;
        }
    });
}

// Cerrar modal con ESC
document.addEventListener('keydown', (e) => {
    if(e.key === 'Escape') cerrarModal();
});

// Cerrar modal al hacer click fuera
const modalInstructor = document.getElementById('modalInstructor');
if(modalInstructor) {
    modalInstructor.addEventListener('click', function(e) {
        if(e.target === this) cerrarModal();
    });
}
