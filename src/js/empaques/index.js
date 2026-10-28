import Swal from 'sweetalert2';
import { $, ORO, esc, num, Q, toast, crearApi, confirmar, error } from '../comun.js';
import { Dropdown } from "bootstrap";


const { get, post } = crearApi($('appEmpaques').dataset.base || '');
let empaques = [];

async function cargar() {
    empaques = (await get('empaques/listar')).datos || [];
    const cuerpo = document.querySelector('#tablaEmpaques tbody');
    if (!empaques.length) {
        cuerpo.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3">No hay empaques.</td></tr>';
        return;
    }
    cuerpo.innerHTML = empaques.map((m) => {
        const activo = num(m.activo) === 1;
        return `
        <tr class="${activo ? '' : 'text-muted'}">
            <td><b>${esc(m.nombre)}</b></td>
            <td class="text-end">${Q(m.costo)}</td>
            <td><span class="hc-chip">${activo ? 'Activo' : 'Inactivo'}</span></td>
            <td class="text-end">
                <button class="btn btn-sm btn-outline-dark" data-editar="${m.id}"><i class="bi bi-pencil"></i></button>
                <button class="btn btn-sm btn-outline-secondary" data-toggle="${m.id}">${activo ? 'Desactivar' : 'Activar'}</button>
                <button class="btn btn-sm btn-outline-danger" data-eliminar="${m.id}"><i class="bi bi-trash"></i></button>
            </td>
        </tr>`;
    }).join('');
}

async function formEmpaque(m = null) {
    const { value } = await Swal.fire({
        titleText: m ? 'Editar empaque' : 'Nuevo empaque',
        html: `
            <div class="text-start">
                <label class="form-label mb-1">Nombre</label>
                <input id="mNombre" class="form-control mb-2" placeholder="Ej. Caja pequeña" value="${esc(m?.nombre)}">
                <label class="form-label mb-1">Costo del empaque (Q)</label>
                <input id="mCosto" type="number" min="0" step="any" class="form-control" value="${m ? num(m.costo) : ''}">
            </div>`,
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonText: 'Guardar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: ORO,
        preConfirm: () => {
            const p = Swal.getPopup();
            const nombre = p.querySelector('#mNombre').value.trim();
            const costo = parseFloat(p.querySelector('#mCosto').value);
            if (!nombre || !(costo >= 0)) {
                Swal.showValidationMessage('Escribe el nombre y el costo');
                return false;
            }
            return { nombre, costo: costo };
        },
    });
    if (!value) return;

    const r = m ? await post('empaques/actualizar', { id: m.id, ...value }) : await post('empaques/crear', value);
    if (r.codigo !== 1) return error(r);
    toast.fire({ icon: 'success', title: r.mensaje });
    cargar();
}

$('btnNuevo').addEventListener('click', () => formEmpaque());
$('tablaEmpaques').addEventListener('click', async (e) => {
    const buscar = (id) => empaques.find((m) => String(m.id) === id);
    const ed = e.target.closest('[data-editar]');
    const tg = e.target.closest('[data-toggle]');
    const del = e.target.closest('[data-eliminar]');

    if (ed) formEmpaque(buscar(ed.dataset.editar));
    if (tg) {
        const m = buscar(tg.dataset.toggle);
        const r = await post('empaques/actualizar', { id: m.id, activo: num(m.activo) === 1 ? 0 : 1 });
        if (r.codigo !== 1) return error(r);
        cargar();
    }
    if (del) {
        const m = buscar(del.dataset.eliminar);
        if (!(await confirmar(`¿Eliminar ${m.nombre}?`))) return;
        const r = await post('empaques/eliminar', { id: m.id });
        if (r.codigo !== 1) return error(r, 'No se puede eliminar');
        toast.fire({ icon: 'success', title: r.mensaje });
        cargar();
    }
});

cargar();
