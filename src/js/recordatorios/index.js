import Swal from 'sweetalert2';
import { $, ORO, esc, num, hoy, fecha, toast, crearApi, confirmar, error, opciones } from '../comun.js';

const { get, post } = crearApi($('appRecordatorios').dataset.base || '');

const TIPOS = {
    material: { nombre: 'Comprar material', icono: 'bi-boxes', color: '#0aa2c0' },
    diseno: { nombre: 'Diseño / STL', icono: 'bi-vector-pen', color: '#6f42c1' },
    cliente: { nombre: 'Cliente', icono: 'bi-person-lines-fill', color: '#d6336c' },
    pintura: { nombre: 'Pintura', icono: 'bi-palette', color: '#e8590c' },
    envio: { nombre: 'Envío', icono: 'bi-truck', color: '#2f9e44' },
    otro: { nombre: 'Otro', icono: 'bi-pin-angle', color: '#6c757d' },
};

let lista = [];
let estado = 'pendiente';
let tipoFiltro = '';
let catalogos = null;

const plano = (s) => String(s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

async function cargar() {
    const r = await get('recordatorios/listar', { estado });
    if (r.codigo !== 1) {
        $('lista').innerHTML = `<div class="alert alert-warning mb-0">${esc(r.mensaje)}</div>`;
        return;
    }
    lista = r.datos || [];
    pintar();
}

function cuando(x) {
    if (x.estado === 'hecho' || !x.fecha_limite) return x.fecha_limite ? `<span class="rec-chip"><i class="bi bi-calendar-event me-1"></i>${fecha(x.fecha_limite)}</span>` : '';
    const d = num(x.dias_para);
    if (d < 0) return `<span class="rec-chip venc"><i class="bi bi-exclamation-triangle-fill me-1"></i>Venció hace ${Math.abs(d)} ${Math.abs(d) === 1 ? 'día' : 'días'}</span>`;
    if (d === 0) return '<span class="rec-chip hoy"><i class="bi bi-alarm me-1"></i>Vence hoy</span>';
    if (d === 1) return '<span class="rec-chip hoy"><i class="bi bi-alarm me-1"></i>Vence mañana</span>';
    return `<span class="rec-chip"><i class="bi bi-calendar-event me-1"></i>${fecha(x.fecha_limite)} · en ${d} días</span>`;
}

function pintar() {
    const q = plano($('rBusca').value);
    $('nPend').textContent = estado === 'pendiente' ? `(${lista.length})` : '';

    // Chips por tipo, con cuántos hay de cada uno
    const cuenta = {};
    lista.forEach((x) => { cuenta[x.tipo] = (cuenta[x.tipo] || 0) + 1; });
    $('filtrosTipo').innerHTML = `<button class="btn btn-sm btn-outline-dark ${tipoFiltro === '' ? 'activo' : ''}" data-tipo="">Todos</button>` +
        Object.entries(TIPOS).filter(([k]) => cuenta[k]).map(([k, t]) =>
            `<button class="btn btn-sm btn-outline-dark ${tipoFiltro === k ? 'activo' : ''}" data-tipo="${k}"><i class="bi ${t.icono} me-1"></i>${t.nombre} <b>${cuenta[k]}</b></button>`).join('');

    const filas = lista.filter((x) => (!tipoFiltro || x.tipo === tipoFiltro) &&
        (!q || plano([x.texto, x.detalle, x.cliente, x.cotizacion, x.color, x.material].join(' ')).includes(q)));

    if (!filas.length) {
        $('lista').innerHTML = `<div class="text-center text-muted py-4"><i class="bi bi-check2-circle fs-1 d-block mb-2" style="color:#2f9e44"></i>${estado === 'pendiente' ? (lista.length ? 'Nada coincide con tu búsqueda.' : 'No tienes recordatorios pendientes. ¡Todo al día!') : 'Todavía no has marcado ninguno como hecho.'}</div>`;
        return;
    }

    $('lista').innerHTML = filas.map((x) => {
        const t = TIPOS[x.tipo] || TIPOS.otro;
        const hecho = x.estado === 'hecho';
        return `
        <div class="rec-item ${hecho ? 'hecho' : ''}" style="--rc:${x.prioridad === 'urgente' && !hecho ? '#d6336c' : t.color}">
            <button class="rec-check" data-hecho="${x.id}" data-valor="${hecho ? 0 : 1}" title="${hecho ? 'Volver a pendientes' : 'Marcar como hecho'}"><i class="bi bi-check-lg"></i></button>
            <div class="flex-grow-1">
                <div class="rec-texto">${esc(x.texto)}</div>
                ${x.detalle ? `<div class="small text-muted mt-1">${esc(x.detalle)}</div>` : ''}
                <div class="rec-meta">
                    <span class="rec-chip" style="background:${t.color}1f;color:${t.color};font-weight:600"><i class="bi ${t.icono} me-1"></i>${t.nombre}</span>
                    ${x.prioridad === 'urgente' && !hecho ? '<span class="rec-chip urg"><i class="bi bi-flag-fill me-1"></i>Urgente</span>' : ''}
                    ${cuando(x)}
                    ${x.cotizacion ? `<span class="rec-chip"><i class="bi bi-calculator me-1"></i>${esc(x.cotizacion)}</span>` : ''}
                    ${x.cliente ? `<span class="rec-chip"><i class="bi bi-person me-1"></i>${esc(x.cliente)}</span>` : ''}
                    ${x.color ? `<span class="rec-chip"><i class="bi bi-droplet-fill me-1"></i>${esc(x.color)}${x.material ? ' · ' + esc(x.material) : ''}</span>` : ''}
                    ${hecho && x.hecho_en ? `<span class="rec-chip"><i class="bi bi-check2 me-1"></i>Hecho el ${fecha(x.hecho_en)}</span>` : ''}
                </div>
                ${x.compro_ya ? `<div class="rec-ya"><i class="bi bi-check-circle-fill me-1" style="color:#2f9e44"></i>Parece que ya registraste filamento de este color. <a href="#" data-hecho="${x.id}" data-valor="1" class="fw-bold">Marcar como hecho</a></div>` : ''}
            </div>
            <div class="text-nowrap">
                <button class="btn btn-sm btn-outline-dark" data-editar="${x.id}" title="Editar"><i class="bi bi-pencil"></i></button>
                <button class="btn btn-sm btn-outline-danger" data-eliminar="${x.id}" title="Eliminar"><i class="bi bi-trash"></i></button>
            </div>
        </div>`;
    }).join('');
}

// Clientes, cotizaciones, materiales y colores para el formulario completo
async function cargarCatalogos() {
    if (catalogos) return catalogos;
    const [c, q, m, k] = await Promise.all([get('clientes/listar'), get('cotizaciones/listar'), get('materiales/listar'), get('inventario/colores')]);
    catalogos = {
        clientes: c.datos || [],
        cotizaciones: q.datos || [],
        materiales: (m.datos || []).filter((x) => num(x.activo) === 1),
        colores: [...new Set((k.datos || []).map((x) => x.color))],
    };
    return catalogos;
}

async function formulario(x = null) {
    const cat = await cargarCatalogos();
    const optCot = '<option value="">— Ninguna —</option>' + cat.cotizaciones.map((q) =>
        `<option value="${q.id}" ${x && String(x.cotizacion_id) === String(q.id) ? 'selected' : ''}>${esc(q.nombre_pieza)}${q.cliente ? ' · ' + esc(q.cliente) : ''}</option>`).join('');

    const { value } = await Swal.fire({
        titleText: x ? 'Editar recordatorio' : 'Nuevo recordatorio',
        width: 640,
        html: `
        <div class="text-start">
            <label class="form-label mb-1">¿Qué tienes que hacer?</label>
            <input id="fTexto" class="form-control mb-2" maxlength="255" placeholder="Ej. Comprar filamento gris para la cotización de Pokémon" value="${esc(x?.texto)}">
            <label class="form-label mb-1">Detalle <span class="text-muted small">(opcional)</span></label>
            <textarea id="fDetalle" class="form-control mb-2" rows="2" maxlength="500" placeholder="Ej. Arreglar el STL adaptado a llavero">${esc(x?.detalle)}</textarea>
            <div class="row g-2 mb-2">
                <div class="col-6"><label class="form-label mb-1">Tipo</label>
                    <select id="fTipo" class="form-select">${Object.entries(TIPOS).map(([k, t]) => `<option value="${k}" ${(x?.tipo || 'otro') === k ? 'selected' : ''}>${t.nombre}</option>`).join('')}</select></div>
                <div class="col-6"><label class="form-label mb-1">Prioridad</label>
                    <select id="fPrio" class="form-select"><option value="normal">Normal</option><option value="urgente" ${x?.prioridad === 'urgente' ? 'selected' : ''}>Urgente (sale en la campana)</option></select></div>
            </div>
            <div id="fMat" class="row g-2 mb-2" style="display:none">
                <div class="col-6"><label class="form-label mb-1">Material</label>
                    <select id="fMaterial" class="form-select">${opciones(cat.materiales, '— Cuál —', x?.material_id)}</select></div>
                <div class="col-6"><label class="form-label mb-1">Color</label>
                    <input id="fColor" class="form-control" list="fColores" placeholder="Ej. Gris" value="${esc(x?.color)}">
                    <datalist id="fColores">${cat.colores.map((c) => `<option value="${esc(c)}">`).join('')}</datalist></div>
            </div>
            <div class="row g-2 mb-2">
                <div class="col-6"><label class="form-label mb-1">Fecha límite <span class="text-muted small">(opcional)</span></label>
                    <input id="fFecha" type="date" class="form-control" value="${esc(x?.fecha_limite)}"></div>
                <div class="col-6"><label class="form-label mb-1">Cliente <span class="text-muted small">(opcional)</span></label>
                    <select id="fCliente" class="form-select">${opciones(cat.clientes, '— Ninguno —', x?.cliente_id)}</select></div>
            </div>
            <label class="form-label mb-1">Cotización relacionada <span class="text-muted small">(opcional)</span></label>
            <select id="fCot" class="form-select">${optCot}</select>
        </div>`,
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonText: 'Guardar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: ORO,
        didOpen: (pop) => {
            const sync = () => { pop.querySelector('#fMat').style.display = pop.querySelector('#fTipo').value === 'material' ? '' : 'none'; };
            pop.querySelector('#fTipo').addEventListener('change', sync);
            sync();
        },
        preConfirm: () => {
            const p = Swal.getPopup();
            const texto = p.querySelector('#fTexto').value.trim();
            if (texto.length < 3) {
                Swal.showValidationMessage('Escribe qué debes recordar');
                return false;
            }
            return {
                texto,
                detalle: p.querySelector('#fDetalle').value.trim(),
                tipo: p.querySelector('#fTipo').value,
                prioridad: p.querySelector('#fPrio').value,
                fecha_limite: p.querySelector('#fFecha').value,
                cliente_id: p.querySelector('#fCliente').value,
                cotizacion_id: p.querySelector('#fCot').value,
                material_id: p.querySelector('#fMaterial').value,
                color: p.querySelector('#fColor').value.trim(),
            };
        },
    });
    if (!value) return;

    const r = x ? await post('recordatorios/actualizar', { id: x.id, ...value }) : await post('recordatorios/crear', value);
    if (r.codigo !== 1) return error(r);
    toast.fire({ icon: 'success', title: r.mensaje });
    cargar();
}

// ── Eventos ──
$('formRapido').addEventListener('submit', async (e) => {
    e.preventDefault();
    const texto = $('rTexto').value.trim();
    if (texto.length < 3) {
        $('rTexto').focus();
        return;
    }
    const r = await post('recordatorios/crear', { texto, tipo: $('rTipo').value });
    if (r.codigo !== 1) return error(r);
    $('rTexto').value = '';
    toast.fire({ icon: 'success', title: 'Recordatorio guardado' });
    if (estado !== 'pendiente') cambiarEstado('pendiente'); else cargar();
    $('rTexto').focus();
});

$('btnNuevo').addEventListener('click', () => formulario());
$('rBusca').addEventListener('input', pintar);

function cambiarEstado(e) {
    estado = e;
    document.querySelectorAll('#filtrosEstado .btn').forEach((b) => b.classList.toggle('activo', b.dataset.estado === e));
    cargar();
}

document.addEventListener('click', async (e) => {
    const est = e.target.closest('[data-estado]');
    if (est) return cambiarEstado(est.dataset.estado);

    const tip = e.target.closest('[data-tipo]');
    if (tip) {
        tipoFiltro = tip.dataset.tipo;
        return pintar();
    }

    const hecho = e.target.closest('[data-hecho]');
    if (hecho) {
        e.preventDefault();
        const r = await post('recordatorios/hecho', { id: hecho.dataset.hecho, hecho: hecho.dataset.valor });
        if (r.codigo !== 1) return error(r);
        toast.fire({ icon: 'success', title: r.mensaje });
        return cargar();
    }

    const ed = e.target.closest('[data-editar]');
    if (ed) return formulario(lista.find((x) => String(x.id) === ed.dataset.editar));

    const el = e.target.closest('[data-eliminar]');
    if (el) {
        if (!(await confirmar('¿Eliminar este recordatorio? Si ya lo hiciste, mejor márcalo como hecho para conservar el historial.', 'Eliminar'))) return;
        const r = await post('recordatorios/eliminar', { id: el.dataset.eliminar });
        if (r.codigo !== 1) return error(r);
        toast.fire({ icon: 'success', title: r.mensaje });
        cargar();
    }
});

$('rTipo').innerHTML = Object.entries(TIPOS).map(([k, t]) => `<option value="${k}" ${k === 'otro' ? 'selected' : ''}>${t.nombre}</option>`).join('');
cargar();
