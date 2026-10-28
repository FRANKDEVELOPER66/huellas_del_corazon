import Swal from 'sweetalert2';
import { $, ORO, esc, num, Q, toast, crearApi, confirmar, error, opciones, hoy, mesActual, fecha } from '../comun.js';

const BASE = $('appGastos').dataset.base || '';
const { get, post } = crearApi(BASE);

// Qué hace cada tipo de categoría en el estado de resultados
const AYUDA_TIPO = {
    directo: 'Costo de una pieza (pintora, envío). Mejor agrégalo desde Ventas > Detalle para ligarlo a esa venta.',
    inventario: 'Compra de material nuevo: no es gasto del mes, pasa a tu inventario de filamento.',
    operativo: 'Gasto de operación: se resta de tu utilidad neta del mes.',
    incluido: 'Ya está dentro del costo de cada pieza: aquí solo cuenta como salida de dinero, no se resta otra vez de la utilidad.',
};
let gastos = [];
let categorias = [];
let movimientos = [];
let pendientesPintora = [];

async function cargar() {
    cargarCaja();
    cargarPintora();
    const mes = $('mes').value;
    const r = await get('gastos/listar', mes ? { mes } : {});
    gastos = r.datos || [];
    pintar();
}

function pintar() {
    const total = gastos.reduce((s, g) => s + num(g.monto), 0);
    $('kpis').innerHTML = `
        <div class="hc-kpi"><div class="t">Total del periodo</div><div class="v">${Q(total)}</div></div>
        <div class="hc-kpi"><div class="t">Gastos registrados</div><div class="v">${gastos.length}</div></div>`;

    const porCat = {};
    gastos.forEach((g) => (porCat[g.categoria] = (porCat[g.categoria] || 0) + num(g.monto)));
    $('porCategoria').innerHTML = Object.entries(porCat)
        .sort((a, b) => b[1] - a[1])
        .map(([c, v]) => `<span class="hc-chip me-1 mb-1">${esc(c)}: <b>${Q(v)}</b></span>`)
        .join('');

    const cuerpo = document.querySelector('#tablaGastos tbody');
    if (!gastos.length) {
        cuerpo.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3">No hay gastos en este periodo.</td></tr>';
        return;
    }
    cuerpo.innerHTML = gastos.map((g) => `
        <tr>
            <td>${fecha(g.fecha)}</td>
            <td>${esc(g.categoria)}</td>
            <td>${esc(g.descripcion)}</td>
            <td>${g.venta_id ? `<span class="hc-chip">Venta #${g.venta_id}</span>` : '<span class="text-muted">General</span>'}</td>
            <td class="text-end fw-bold">${Q(g.monto)}</td>
            <td class="text-end text-nowrap">
                ${g.comprobante ? `<a class="btn btn-sm btn-outline-secondary" href="${BASE}/API/gastos/comprobante?id=${g.id}" target="_blank" rel="noopener" title="Ver comprobante"><i class="bi bi-paperclip"></i></a>` : ''}
                <button class="btn btn-sm btn-outline-dark" data-editar="${g.id}"><i class="bi bi-pencil"></i></button>
                <button class="btn btn-sm btn-outline-danger" data-eliminar="${g.id}"><i class="bi bi-trash"></i></button>
            </td>
        </tr>`).join('');
}

async function formGasto(g = null) {
    const { value } = await Swal.fire({
        titleText: g ? 'Editar gasto' : 'Nuevo gasto',
        html: `
            <div class="text-start">
                <label class="form-label mb-1">Fecha</label>
                <input id="gFecha" type="date" class="form-control mb-2" value="${g ? String(g.fecha).slice(0, 10) : hoy()}">
                <label class="form-label mb-1">Categoría</label>
                <select id="gCategoria" class="form-select mb-2">${opciones(categorias, 'Elige una categoría', g?.categoria_id ?? null)}</select>
                <div id="gAyuda" class="hc-ayuda mb-2"></div>
                <label class="form-label mb-1">Descripción</label>
                <input id="gDescripcion" type="text" class="form-control mb-2" placeholder="Ej. Rollo PLA negro" value="${esc(g?.descripcion)}">
                <label class="form-label mb-1">Monto (Q)</label>
                <input id="gMonto" type="number" min="0" step="any" class="form-control mb-2" value="${g ? num(g.monto) : ''}">
                <label class="form-label mb-1">Comprobante de la transferencia ${g?.comprobante ? '(sube otro solo si quieres reemplazar el actual)' : '(opcional)'}</label>
                <input id="gArchivo" type="file" accept="image/*,application/pdf" class="form-control">
            </div>`,
        didOpen: (pop) => {
            const sel = pop.querySelector('#gCategoria');
            const mostrar = () => {
                const c = categorias.find((x) => String(x.id) === sel.value);
                pop.querySelector('#gAyuda').textContent = c ? AYUDA_TIPO[c.tipo] || '' : '';
            };
            sel.addEventListener('change', mostrar);
            mostrar();
        },
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonText: 'Guardar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: ORO,
        preConfirm: () => {
            const p = Swal.getPopup();
            const categoria_id = p.querySelector('#gCategoria').value;
            const descripcion = p.querySelector('#gDescripcion').value.trim();
            const monto = parseFloat(p.querySelector('#gMonto').value);
            const invalido = (m) => {
                Swal.showValidationMessage(m);
                return false;
            };
            if (!categoria_id) return invalido('Elige la categoría');
            if (!descripcion) return invalido('Escribe la descripción');
            if (!(monto > 0)) return invalido('Escribe el monto');
            const archivo = p.querySelector('#gArchivo').files[0] || null;
            if (archivo && archivo.size > 5 * 1024 * 1024) return invalido('El comprobante pesa más de 5 MB');
            return { fecha: p.querySelector('#gFecha').value, categoria_id, descripcion, monto, comprobante: archivo };
        },
    });
    if (!value) return;

    const r = g ? await post('gastos/actualizar', { id: g.id, ...value }) : await post('gastos/crear', value);
    if (r.codigo !== 1) return error(r);
    toast.fire({ icon: 'success', title: r.mensaje });
    cargar();
}

async function eliminar(g) {
    if (!(await confirmar('¿Eliminar este gasto?'))) return;
    const r = await post('gastos/eliminar', { id: g.id });
    if (r.codigo !== 1) return error(r);
    toast.fire({ icon: 'success', title: r.mensaje });
    cargar();
}

$('mes').addEventListener('change', cargar);
$('btnNuevoGasto').addEventListener('click', () => formGasto());
$('tablaGastos').addEventListener('click', (e) => {
    const ed = e.target.closest('[data-editar]');
    const del = e.target.closest('[data-eliminar]');
    if (ed) formGasto(gastos.find((g) => String(g.id) === ed.dataset.editar));
    if (del) eliminar(gastos.find((g) => String(g.id) === del.dataset.eliminar));
});

(async () => {
    $('mes').value = mesActual();
    categorias = (await get('gastos/categorias')).datos || [];
    cargar();
})();

// ── Caja del negocio ────────────────────────────────────────────────────────
async function cargarCaja() {
    const r = await get('caja/resumen');
    if (r.codigo !== 1) return;

    const c = r.datos.caja;
    movimientos = r.datos.movimientos || [];

    $('cajaKpis').innerHTML = `
        <div class="caja-grid">
            <div class="caja-box azul"><span>Caja hoy</span><b>${Q(c.caja)}</b>
                <small>Cobrado ${Q(c.cobros)} + aportes ${Q(c.aportes)} − retiros ${Q(c.retiros)} − pagado ${Q(c.gastos)}</small></div>
            <div class="caja-box ambar"><span>Por pagar (pintora y envíos)</span><b>${Q(c.por_pagar)}</b>
                <small>Lo que aún no pagas de las piezas vendidas (pintora, envíos y otros): se resta de lo disponible</small></div>
            <div class="caja-box ${c.disponible < 0 ? 'rojo' : 'verde'}"><span>Disponible para gastar</span><b>${Q(c.disponible)}</b>
                <small>${c.disponible < 0 ? 'No alcanza: hoy debes más de lo que tienes' : 'Caja menos lo que ya debes pagar: lo que puedes gastar hoy en filamento u otras compras'}</small></div>
        </div>
        ${c.caja < 0 ? '<div class="hc-ayuda mt-2">La caja sale negativa porque pagaste cosas antes de cobrar. Si fue con tu dinero, regístralo con “Poner dinero”.</div>' : ''}`;

    document.querySelector('#tablaAportes tbody').innerHTML = movimientos.length
        ? movimientos.map((m) => `
            <tr>
                <td>${fecha(m.fecha)}</td>
                <td><span class="hc-chip">${m.tipo === 'aporte' ? 'Pusiste' : 'Sacaste'}</span></td>
                <td>${esc(m.descripcion)}</td>
                <td class="text-end fw-bold" style="color:${m.tipo === 'aporte' ? '#117a43' : '#b42318'}">${m.tipo === 'aporte' ? '+' : '−'}${Q(m.monto)}</td>
                <td class="text-end"><button class="btn btn-sm btn-outline-danger" data-del-mov="${m.id}"><i class="bi bi-trash"></i></button></td>
            </tr>`).join('')
        : '<tr><td colspan="5" class="text-center text-muted py-2">Aún no registras dinero que pongas o saques del negocio.</td></tr>';
}

async function formMovimiento(tipo) {
    const esAporte = tipo === 'aporte';
    const { value } = await Swal.fire({
        titleText: esAporte ? 'Poner dinero en el negocio' : 'Sacar dinero del negocio',
        html: `
            <div class="text-start">
                <div class="hc-ayuda mb-2">${esAporte
                    ? 'Dinero tuyo que pones para comprar material u otras cosas del negocio.'
                    : 'Ganancia que te llevas. No puede ser más de lo disponible.'}</div>
                <label class="form-label mb-1">Monto (Q)</label>
                <input id="mMonto" type="number" min="0" step="any" class="form-control mb-2">
                <label class="form-label mb-1">Detalle</label>
                <input id="mDetalle" class="form-control mb-2" placeholder="${esAporte ? 'Ej. Capital para empezar' : 'Ej. Mi ganancia de octubre'}">
                <label class="form-label mb-1">Fecha</label>
                <input id="mFecha" type="date" class="form-control" value="${hoy()}">
            </div>`,
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonText: 'Guardar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: ORO,
        preConfirm: () => {
            const p = Swal.getPopup();
            const monto = parseFloat(p.querySelector('#mMonto').value);
            const descripcion = p.querySelector('#mDetalle').value.trim();
            if (!(monto > 0) || !descripcion) {
                Swal.showValidationMessage('Escribe el monto y el detalle');
                return false;
            }
            return { tipo, monto, descripcion, fecha: p.querySelector('#mFecha').value };
        },
    });
    if (!value) return;

    const r = await post('caja/crear', value);
    if (r.codigo !== 1) return error(r);
    toast.fire({ icon: 'success', title: r.mensaje });
    cargarCaja();
}

$('btnAporte').addEventListener('click', () => formMovimiento('aporte'));
$('btnRetiro').addEventListener('click', () => formMovimiento('retiro'));
$('tablaAportes').addEventListener('click', async (e) => {
    const b = e.target.closest('[data-del-mov]');
    if (!b || !(await confirmar('¿Eliminar este movimiento de caja?'))) return;
    const r = await post('caja/eliminar', { id: b.dataset.delMov });
    if (r.codigo !== 1) return error(r);
    cargarCaja();
});

// ── Pagos a la pintora ──────────────────────────────────────────────────────
const ETAPAS = { solicitado: 'Solicitado', imprimiendo: 'Imprimiendo', impreso: 'Impreso', pintando: 'En pintura', listo: 'Listo', entregado: 'Entregado' };

async function cargarPintora() {
    const r = await get('gastos/pintora');
    if (r.codigo !== 1) return;

    const d = r.datos;
    pendientesPintora = d.pendientes;

    const tabla = d.pendientes.length
        ? `<div class="table-responsive mt-3"><table class="table table-sm align-middle mb-0 hc-tabla-nw">
            <thead><tr><th>Pieza</th><th>Pintora</th><th>Etapa</th><th class="text-end">Acordado</th><th class="text-end">Pagado</th><th class="text-end">Falta</th><th></th></tr></thead>
            <tbody>${d.pendientes.map((f) => `
                <tr>
                    <td><b>${esc(f.descripcion)}</b></td>
                    <td>${esc(f.pintor || '—')}</td>
                    <td>${ETAPAS[f.etapa] || '—'}</td>
                    <td class="text-end">${Q(f.acordado)}</td>
                    <td class="text-end">${Q(f.pagado)}</td>
                    <td class="text-end fw-bold" style="color:#b26a00">${Q(f.pendiente)}</td>
                    <td class="text-end"><button class="btn btn-sm btn-hc" data-pagar-pintora="${f.venta_id}"><i class="bi bi-cash-coin"></i> Registrar pago</button></td>
                </tr>`).join('')}</tbody></table></div>`
        : '<div class="hc-ayuda mt-2">No le debes nada a la pintora por ahora.</div>';

    $('pintora').innerHTML = `
        <div class="caja-grid">
            <div class="caja-box ${d.total_pendiente > 0 ? 'ambar' : 'verde'}"><span>Le debes a la pintora</span><b>${Q(d.total_pendiente)}</b>
                <small>Lo acordado en tus cotizaciones menos lo que ya le pagaste</small></div>
            <div class="caja-box azul"><span>Ya le pagaste</span><b>${Q(d.total_pagado)}</b>
                <small>Cada pago queda con su comprobante de transferencia</small></div>
        </div>
        ${tabla}`;
}

async function pagarPintora(f) {
    const { value } = await Swal.fire({
        titleText: `Pago a la pintora: ${f.descripcion}`,
        html: `
            <div class="text-start">
                <div class="hc-ayuda mb-2">Acordado ${Q(f.acordado)} · pagado ${Q(f.pagado)} · falta <b>${Q(f.pendiente)}</b></div>
                <label class="form-label mb-1">Monto que transferiste (Q)</label>
                <input id="tMonto" type="number" min="0" step="any" class="form-control mb-2" value="${f.pendiente}">
                <label class="form-label mb-1">Fecha</label>
                <input id="tFecha" type="date" class="form-control mb-2" value="${hoy()}">
                <label class="form-label mb-1">Captura de la transferencia</label>
                <input id="tArchivo" type="file" accept="image/*,application/pdf" class="form-control">
            </div>`,
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonText: 'Registrar pago',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: ORO,
        preConfirm: () => {
            const p = Swal.getPopup();
            const monto = parseFloat(p.querySelector('#tMonto').value);
            const archivo = p.querySelector('#tArchivo').files[0] || null;
            if (!(monto > 0)) {
                Swal.showValidationMessage('Escribe el monto');
                return false;
            }
            if (archivo && archivo.size > 5 * 1024 * 1024) {
                Swal.showValidationMessage('El comprobante pesa más de 5 MB');
                return false;
            }
            return { monto, fecha: p.querySelector('#tFecha').value, comprobante: archivo };
        },
    });
    if (!value) return;

    const categoria = categorias.find((c) => /^Pintura/i.test(c.nombre));
    if (!categoria) {
        return Swal.fire({ icon: 'info', title: 'Falta la categoría “Pintura (pintora)”', text: 'Debe existir en las categorías de gasto.', confirmButtonColor: ORO });
    }

    const r = await post('gastos/crear', {
        categoria_id: categoria.id,
        venta_id: f.venta_id,
        pintor_id: f.pintor_id || '',
        descripcion: `Pago a pintora: ${f.descripcion}`,
        ...value,
    });
    if (r.codigo !== 1) return error(r);
    toast.fire({ icon: 'success', title: 'Pago a la pintora registrado' });
    cargar();
}

$('pintora').addEventListener('click', (e) => {
    const b = e.target.closest('[data-pagar-pintora]');
    if (!b) return;
    const f = pendientesPintora.find((x) => String(x.venta_id) === b.dataset.pagarPintora);
    if (f) pagarPintora(f);
});
