import Swal from 'sweetalert2';
import { $, ORO, esc, num, Q, toast, crearApi, confirmar, error } from '../comun.js';

const { get, post } = crearApi($('appPintores').dataset.base || '');
let pintores = [];

const pintorPorId = (id) => pintores.find((p) => String(p.id) === String(id));
const tarifaPorId = (id) => pintores.flatMap((p) => p.tarifas).find((t) => String(t.id) === String(id));
const cm = (n) => `${num(n)} cm`;

async function cargar() {
    const r = await get('pintores/listar');
    pintores = r.datos || [];
    pintar();
}

function filasTarifas(p) {
    if (!p.tarifas.length) return '<tr><td colspan="3" class="hc-ayuda">Sin tarifas todavía</td></tr>';
    return p.tarifas.map((t) => `
        <tr>
            <td>${cm(t.alto_min_cm)} – ${cm(t.alto_max_cm)}</td>
            <td class="text-end fw-bold">${Q(t.precio)}</td>
            <td class="text-end text-nowrap">
                <button class="btn btn-sm btn-link p-0 me-2" data-accion="tarifa-editar" data-id="${t.id}" title="Editar"><i class="bi bi-pencil"></i></button>
                <button class="btn btn-sm btn-link text-danger p-0" data-accion="tarifa-eliminar" data-id="${t.id}" title="Quitar"><i class="bi bi-x-lg"></i></button>
            </td>
        </tr>`).join('');
}

function pintar() {
    const grid = $('pintoresGrid');
    if (!pintores.length) {
        grid.innerHTML = '<div class="hc-vacio">Aún no hay pintores. Registra el primero con “Nuevo pintor”.</div>';
        return;
    }

    grid.innerHTML = pintores.map((p) => {
        const activo = num(p.activo) === 1;
        return `
        <div class="col-md-6 col-xl-4">
            <div class="hc-card h-100 ${activo ? '' : 'hc-inactivo'}">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                        <h2 class="mb-0">${esc(p.nombre)}</h2>
                        <div class="hc-ayuda">${p.telefono ? `<i class="bi bi-telephone me-1"></i>${esc(p.telefono)}` : 'Sin teléfono'}</div>
                    </div>
                    <span class="hc-chip">${activo ? 'Activo' : 'Inactivo'}</span>
                </div>
                <table class="table table-sm mb-2">
                    <thead><tr><th>Altura</th><th class="text-end">Cobra</th><th></th></tr></thead>
                    <tbody>${filasTarifas(p)}</tbody>
                </table>
                <div class="d-flex flex-wrap gap-1">
                    <button class="btn btn-sm btn-hc" data-accion="tarifa-nueva" data-id="${p.id}"><i class="bi bi-plus-lg"></i> Tarifa</button>
                    <button class="btn btn-sm btn-outline-dark" data-accion="editar" data-id="${p.id}"><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-sm btn-outline-secondary" data-accion="toggle" data-id="${p.id}">${activo ? 'Desactivar' : 'Activar'}</button>
                    <button class="btn btn-sm btn-outline-danger" data-accion="eliminar" data-id="${p.id}"><i class="bi bi-trash"></i></button>
                </div>
            </div>
        </div>`;
    }).join('');
}

// ── Pintor ──────────────────────────────────────────────────────────────────
async function formPintor(p = null) {
    const { value } = await Swal.fire({
        titleText: p ? 'Editar pintor' : 'Nuevo pintor',
        html: `<input id="fNombre" class="swal2-input" placeholder="Nombre" value="${esc(p?.nombre)}">
               <input id="fTel" class="swal2-input" placeholder="Teléfono (opcional)" value="${esc(p?.telefono)}">`,
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonText: 'Guardar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: ORO,
        preConfirm: () => {
            const pop = Swal.getPopup();
            const nombre = pop.querySelector('#fNombre').value.trim();
            if (!nombre) {
                Swal.showValidationMessage('Escribe el nombre del pintor');
                return false;
            }
            return { nombre, telefono: pop.querySelector('#fTel').value.trim() };
        },
    });
    if (!value) return;

    const r = await post(p ? 'pintores/actualizar' : 'pintores/crear', { id: p?.id, ...value });
    if (r.codigo !== 1) return error(r);
    toast.fire({ icon: 'success', title: r.mensaje });
    cargar();
}

async function alternarActivo(p) {
    const r = await post('pintores/actualizar', { id: p.id, activo: num(p.activo) === 1 ? 0 : 1 });
    if (r.codigo !== 1) return error(r);
    cargar();
}

async function eliminarPintor(p) {
    if (!(await confirmar(`¿Eliminar a ${p.nombre}?`))) return;
    const r = await post('pintores/eliminar', { id: p.id });
    if (r.codigo !== 1) return error(r, 'No se puede eliminar');
    toast.fire({ icon: 'success', title: r.mensaje });
    cargar();
}

// ── Tarifas ─────────────────────────────────────────────────────────────────
async function formTarifa(pintor, t = null) {
    // Sugiere que el nuevo rango empiece justo donde termina el último
    const previas = pintor.tarifas.filter((x) => !t || x.id !== t.id);
    const siguiente = previas.length ? Math.round((Math.max(...previas.map((x) => num(x.alto_max_cm))) + 0.1) * 10) / 10 : 0;

    const { value } = await Swal.fire({
        titleText: `${t ? 'Editar' : 'Nueva'} tarifa de ${pintor.nombre}`,
        html: `
            <div class="text-start">
                <div class="row g-2 mb-2">
                    <div class="col"><label class="form-label mb-1">Alto desde (cm)</label>
                        <input id="tMin" type="number" min="0" step="any" class="form-control" value="${t ? num(t.alto_min_cm) : siguiente}"></div>
                    <div class="col"><label class="form-label mb-1">Hasta (cm)</label>
                        <input id="tMax" type="number" min="0" step="any" class="form-control" value="${t ? num(t.alto_max_cm) : ''}"></div>
                </div>
                <label class="form-label mb-1">Lo que cobra (Q)</label>
                <input id="tPrecio" type="number" min="0" step="any" class="form-control" value="${t ? num(t.precio) : ''}">
            </div>`,
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonText: 'Guardar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: ORO,
        preConfirm: () => {
            const pop = Swal.getPopup();
            const min = parseFloat(pop.querySelector('#tMin').value);
            const max = parseFloat(pop.querySelector('#tMax').value);
            const precio = parseFloat(pop.querySelector('#tPrecio').value);
            if (isNaN(min) || isNaN(max) || max < min) {
                Swal.showValidationMessage('El alto “hasta” debe ser mayor o igual al “desde”');
                return false;
            }
            if (!(precio > 0)) {
                Swal.showValidationMessage('Escribe lo que cobra');
                return false;
            }
            return { alto_min_cm: min, alto_max_cm: max, precio };
        },
    });
    if (!value) return;

    const r = await post('pintores/tarifa/guardar', { id: t?.id, pintor_id: pintor.id, ...value });
    if (r.codigo !== 1) return error(r);
    toast.fire({ icon: 'success', title: r.mensaje });
    cargar();
}

async function eliminarTarifa(t) {
    if (!(await confirmar('¿Quitar esta tarifa?', 'Quitar'))) return;
    const r = await post('pintores/tarifa/eliminar', { id: t.id });
    if (r.codigo !== 1) return error(r);
    cargar();
}

// ── Eventos ─────────────────────────────────────────────────────────────────
$('btnNuevoPintor').addEventListener('click', () => formPintor());

$('pintoresGrid').addEventListener('click', (e) => {
    const btn = e.target.closest('[data-accion]');
    if (!btn) return;
    const { accion, id } = btn.dataset;

    if (accion === 'tarifa-editar' || accion === 'tarifa-eliminar') {
        const t = tarifaPorId(id);
        if (!t) return;
        return accion === 'tarifa-editar' ? formTarifa(pintorPorId(t.pintor_id), t) : eliminarTarifa(t);
    }

    const p = pintorPorId(id);
    if (!p) return;
    if (accion === 'tarifa-nueva') formTarifa(p);
    if (accion === 'editar') formPintor(p);
    if (accion === 'toggle') alternarActivo(p);
    if (accion === 'eliminar') eliminarPintor(p);
});

cargar();
