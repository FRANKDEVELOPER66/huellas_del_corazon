import { Dropdown } from "bootstrap";
import Swal from 'sweetalert2';
import { $, ORO, esc, num, Q, toast, crearApi, confirmar, error } from '../comun.js';

const { get, post } = crearApi($('appMateriales').dataset.base || '');
let materiales = [];

async function cargar() {
    materiales = (await get('materiales/listar')).datos || [];
    const cuerpo = document.querySelector('#tablaMateriales tbody');
    if (!materiales.length) {
        cuerpo.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3">No hay materiales.</td></tr>';
        return;
    }
    cuerpo.innerHTML = materiales.map((m) => {
        const activo = num(m.activo) === 1;
        return `
        <tr class="${activo ? '' : 'text-muted'}">
            <td><b>${esc(m.nombre)}</b></td>
            <td class="text-end">${Q(m.costo_kg)}</td>
            <td><span class="hc-chip">${activo ? 'Activo' : 'Inactivo'}</span></td>
            <td class="text-end">
                <button class="btn btn-sm btn-outline-dark" data-editar="${m.id}"><i class="bi bi-pencil"></i></button>
                <button class="btn btn-sm btn-outline-secondary" data-toggle="${m.id}">${activo ? 'Desactivar' : 'Activar'}</button>
                <button class="btn btn-sm btn-outline-danger" data-eliminar="${m.id}"><i class="bi bi-trash"></i></button>
            </td>
        </tr>`;
    }).join('');
}

async function formMaterial(m = null) {
    const { value } = await Swal.fire({
        titleText: m ? 'Editar material' : 'Nuevo material',
        html: `
            <div class="text-start">
                <label class="form-label mb-1">Nombre</label>
                <input id="mNombre" class="form-control mb-2" placeholder="Ej. PLA negro" value="${esc(m?.nombre)}">
                <label class="form-label mb-1">Lo que pagas por kg (Q)</label>
                <input id="mCosto" type="number" min="0" step="any" class="form-control" value="${m ? num(m.costo_kg) : ''}">
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
            if (!nombre || !(costo > 0)) {
                Swal.showValidationMessage('Escribe el nombre y el costo por kg');
                return false;
            }
            return { nombre, costo_kg: costo };
        },
    });
    if (!value) return;

    const r = m ? await post('materiales/actualizar', { id: m.id, ...value }) : await post('materiales/crear', value);
    if (r.codigo !== 1) return error(r);
    toast.fire({ icon: 'success', title: r.mensaje });
    cargar();
}

$('btnNuevo').addEventListener('click', () => formMaterial());
$('tablaMateriales').addEventListener('click', async (e) => {
    const buscar = (id) => materiales.find((m) => String(m.id) === id);
    const ed = e.target.closest('[data-editar]');
    const tg = e.target.closest('[data-toggle]');
    const del = e.target.closest('[data-eliminar]');

    if (ed) formMaterial(buscar(ed.dataset.editar));
    if (tg) {
        const m = buscar(tg.dataset.toggle);
        const r = await post('materiales/actualizar', { id: m.id, activo: num(m.activo) === 1 ? 0 : 1 });
        if (r.codigo !== 1) return error(r);
        cargar();
    }
    if (del) {
        const m = buscar(del.dataset.eliminar);
        if (!(await confirmar(`¿Eliminar ${m.nombre}?`))) return;
        const r = await post('materiales/eliminar', { id: m.id });
        if (r.codigo !== 1) return error(r, 'No se puede eliminar');
        toast.fire({ icon: 'success', title: r.mensaje });
        cargar();
    }
});

cargar();
