import Swal from 'sweetalert2';
import { $, ORO, esc, toast, crearApi, confirmar, error } from '../comun.js';

const { get, post } = crearApi($('appClientes').dataset.base || '');
let clientes = [];
let temporizador = null;

async function cargar() {
    const q = $('buscar').value.trim();
    const r = await get('clientes/listar', q ? { q } : {});
    clientes = r.datos || [];
    pintar();
}

function pintar() {
    const cuerpo = document.querySelector('#tablaClientes tbody');
    if (!clientes.length) {
        cuerpo.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3">No hay clientes.</td></tr>';
        return;
    }
    cuerpo.innerHTML = clientes.map((c) => `
        <tr>
            <td><b>${esc(c.nombre)}</b></td>
            <td>${c.telefono ? esc(c.telefono) : '—'}</td>
            <td class="text-muted" style="white-space:normal;max-width:320px">${esc(c.notas)}</td>
            <td class="text-end">
                <button class="btn btn-sm btn-outline-dark" data-editar="${c.id}"><i class="bi bi-pencil"></i></button>
                <button class="btn btn-sm btn-outline-danger" data-eliminar="${c.id}"><i class="bi bi-trash"></i></button>
            </td>
        </tr>`).join('');
}

async function formCliente(c = null) {
    const { value } = await Swal.fire({
        titleText: c ? 'Editar cliente' : 'Nuevo cliente',
        html: `
            <div class="text-start">
                <label class="form-label mb-1">Nombre</label>
                <input id="cNombre" class="form-control mb-2" value="${esc(c?.nombre)}">
                <label class="form-label mb-1">Teléfono</label>
                <input id="cTelefono" class="form-control mb-2" placeholder="Opcional" value="${esc(c?.telefono)}">
                <label class="form-label mb-1">Notas</label>
                <textarea id="cNotas" class="form-control" rows="3" placeholder="Opcional">${esc(c?.notas)}</textarea>
            </div>`,
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonText: 'Guardar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: ORO,
        preConfirm: () => {
            const p = Swal.getPopup();
            const nombre = p.querySelector('#cNombre').value.trim();
            if (!nombre) {
                Swal.showValidationMessage('Escribe el nombre del cliente');
                return false;
            }
            return { nombre, telefono: p.querySelector('#cTelefono').value.trim(), notas: p.querySelector('#cNotas').value.trim() };
        },
    });
    if (!value) return;

    const r = c ? await post('clientes/actualizar', { id: c.id, ...value }) : await post('clientes/crear', value);
    if (r.codigo !== 1) return error(r);
    toast.fire({ icon: 'success', title: r.mensaje });
    cargar();
}

async function eliminar(c) {
    if (!(await confirmar(`¿Eliminar a ${c.nombre}?`))) return;
    const r = await post('clientes/eliminar', { id: c.id });
    if (r.codigo !== 1) return error(r, 'No se puede eliminar');
    toast.fire({ icon: 'success', title: r.mensaje });
    cargar();
}

$('btnNuevo').addEventListener('click', () => formCliente());
$('buscar').addEventListener('input', () => {
    clearTimeout(temporizador);
    temporizador = setTimeout(cargar, 300);
});
$('tablaClientes').addEventListener('click', (e) => {
    const ed = e.target.closest('[data-editar]');
    const del = e.target.closest('[data-eliminar]');
    if (ed) formCliente(clientes.find((c) => String(c.id) === ed.dataset.editar));
    if (del) eliminar(clientes.find((c) => String(c.id) === del.dataset.eliminar));
});

cargar();
