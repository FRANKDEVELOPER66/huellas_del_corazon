import Swal from 'sweetalert2';
import { $, ORO, esc, num, Q, toast, crearApi, confirmar, error, opciones, hoy, fecha, cobrarVenta, editarPago } from '../comun.js';

const BASE = $('appPedidos').dataset.base || '';
const { get, post } = crearApi(BASE);

// Etapas en orden. Solo se avanza una a la vez y NO hay vuelta atrás.
// De “Listo para entregar” se pasa a “Entregado” únicamente con “Finalizar venta”.
const ETAPAS = {
    solicitado: { titulo: 'Solicitado', ayuda: 'Pedido recibido', siguiente: 'imprimiendo' },
    imprimiendo: { titulo: 'Imprimiendo', ayuda: 'En la impresora', siguiente: 'impreso' },
    impreso: { titulo: 'Impreso', ayuda: 'Embalar y enviar a la pintora', siguiente: 'pintando' },
    pintando: { titulo: 'En pintura', ayuda: 'Con la pintora', siguiente: 'listo' },
    listo: { titulo: 'Listo para entregar', ayuda: 'Al finalizar se verifica el pago', siguiente: 'entregado' },
    entregado: { titulo: 'Entregado', ayuda: 'Venta finalizada: ya no cambia', siguiente: null },
    cancelado: { titulo: 'Cancelado', ayuda: '', siguiente: null },
};
const FINALES = ['entregado', 'cancelado'];

let pedidos = [];
let clientes = [];
let cotizaciones = [];

async function cargar() {
    pedidos = (await get('pedidos/listar')).datos || [];
    pintar();
}

// ── Tablero ─────────────────────────────────────────────────────────────────
// Aviso: la pieza ya va (o ya pasó) por la pintora y quedan costos sin pagar (pintora, envío, otros)
function avisoPintora(p) {
    const faltas = [['pintora', num(p.falta_pintura)], ['envío', num(p.falta_envio)], ['otros', num(p.falta_otros)]].filter(([, v]) => v > 0.005);
    const enEtapa = ['impreso', 'pintando', 'listo', 'entregado'].includes(p.estado);
    if (!p.venta_id || !faltas.length || !enEtapa) return '';
    return `<a href="${BASE}/gastos" class="small neg d-block mt-1" title="Regístralo en Gastos > Pagos pendientes"><i class="bi bi-exclamation-circle"></i> Falta pagar: ${faltas.map(([n, v]) => `${n} <b>${Q(v)}</b>`).join(' · ')}</a>`;
}

function botonEtapa(p) {
    if (p.estado === 'listo') {
        return `<button class="btn btn-sm btn-hc w-100 mt-2" data-acc="finalizar" data-id="${p.id}"><i class="bi bi-flag-fill"></i> Finalizar venta</button>`;
    }
    const sig = ETAPAS[p.estado]?.siguiente;
    if (sig) {
        return `<button class="btn btn-sm btn-outline-dark w-100 mt-2" data-acc="avanzar" data-id="${p.id}">Pasar a ${ETAPAS[sig].titulo} <i class="bi bi-arrow-right"></i></button>`;
    }
    return p.estado === 'entregado' ? '<div class="hc-ayuda mt-2"><i class="bi bi-lock-fill"></i> Entregado: ya no se puede cambiar</div>' : '';
}

function tarjeta(p) {
    const conVenta = Boolean(p.venta_id);
    const saldo = num(p.saldo);
    const debe = conVenta && saldo > 0;
    const final = FINALES.includes(p.estado);
    const vencido = p.fecha_entrega && p.fecha_entrega < hoy() && !final;

    const dinero = conVenta
        ? `<div class="d-flex justify-content-between small mt-1">
               <span>${Q(p.precio_venta)}</span>
               ${debe ? `<span class="neg fw-bold">Debe ${Q(saldo)}</span>` : '<span class="pos"><i class="bi bi-check-circle-fill"></i> Pagado</span>'}
           </div>`
        : '<div class="hc-ayuda mt-1">Sin venta registrada</div>';

    return `
        <div class="tarjeta">
            <div class="d-flex justify-content-between align-items-start">
                <b>${esc(p.nombre_mascota)}</b>
                ${conVenta || final ? '' : `<button class="btn btn-sm btn-link text-danger p-0" data-acc="eliminar" data-id="${p.id}" title="Eliminar"><i class="bi bi-trash"></i></button>`}
            </div>
            <div class="hc-ayuda">${esc(p.cliente)}</div>
            ${p.fecha_entrega ? `<div class="small ${vencido ? 'neg fw-bold' : ''}"><i class="bi bi-calendar-event me-1"></i>${fecha(p.fecha_entrega)}${vencido ? ' · atrasado' : ''}</div>` : ''}
            ${dinero}
            ${avisoPintora(p)}
            ${p.notas ? `<div class="hc-ayuda mt-1">${esc(p.notas)}</div>` : ''}
            <div class="d-flex gap-1 mt-2">
                ${final ? '' : `<button class="btn btn-sm btn-outline-dark" data-acc="editar" data-id="${p.id}" title="Editar"><i class="bi bi-pencil"></i></button>`}
                ${conVenta
                    ? `<button class="btn btn-sm btn-hc ms-auto" data-acc="cobros" data-id="${p.id}"><i class="bi bi-cash-coin"></i> Cobros</button>`
                    : (p.estado === 'cancelado' ? '' : `<button class="btn btn-sm btn-hc ms-auto" data-acc="venta" data-id="${p.id}"><i class="bi bi-receipt"></i> Venta</button>`)}
            </div>
            ${botonEtapa(p)}
        </div>`;
}

function pintar() {
    // “Cancelado” solo aparece si hay pedidos cancelados
    const claves = Object.keys(ETAPAS).filter((k) => k !== 'cancelado' || pedidos.some((p) => p.estado === 'cancelado'));

    $('tablero').innerHTML = claves.map((clave) => {
        const e = ETAPAS[clave];
        const lista = pedidos.filter((p) => p.estado === clave);
        return `
        <div class="columna">
            <h3><span>${e.titulo}</span><span>${lista.length}</span></h3>
            ${e.ayuda ? `<div class="hc-ayuda mb-2">${e.ayuda}</div>` : ''}
            ${lista.map(tarjeta).join('') || '<div class="hc-ayuda text-center">—</div>'}
        </div>`;
    }).join('');
}

// ── Pasar a la siguiente etapa (con confirmación, sin regreso) ──────────────
async function avanzar(p) {
    const actual = ETAPAS[p.estado];
    const sig = ETAPAS[actual.siguiente];

    let aviso = '';
    if (p.estado === 'solicitado') {
        if (!p.venta_id) aviso = '<div class="neg mt-2"><b>Este pedido todavía no tiene venta registrada.</b></div>';
        else if (num(p.pagado) > 0) aviso = `<div class="pos mt-2">Anticipo registrado: <b>${Q(p.pagado)}</b></div>`;
        else aviso = '<div class="neg mt-2"><b>Todavía no hay anticipo registrado.</b></div>';
    }

    const { isConfirmed } = await Swal.fire({
        icon: 'question',
        titleText: `¿Pasar “${p.nombre_mascota}” a ${sig.titulo}?`,
        html: `<div>Esta acción <b>no se puede deshacer</b>: el pedido ya no podrá regresar a “${esc(actual.titulo)}”.</div>${aviso}`,
        showCancelButton: true,
        focusCancel: true,
        confirmButtonText: `Sí, pasar a ${sig.titulo}`,
        cancelButtonText: 'Cancelar',
        confirmButtonColor: ORO,
    });
    if (!isConfirmed) return;

    const r = await post('pedidos/estado', { id: p.id, desde: p.estado });
    if (r.codigo !== 1) {
        await error(r);
        return cargar();
    }
    toast.fire({ icon: 'success', title: r.mensaje });
    cargar();
}

// ── Finalizar venta: exige el pago completo y deja el pedido en Entregado ───
async function finalizar(p) {
    if (!p.venta_id) {
        return Swal.fire({ icon: 'info', title: 'Primero registra la venta', text: 'Usa el botón “Venta” de la tarjeta con el precio al que vendiste.', confirmButtonColor: ORO });
    }

    const r = await get('ventas/detalle', { id: p.venta_id });
    if (r.codigo !== 1) return error(r);
    const v = r.datos.venta;
    const saldo = num(v.saldo);

    const { value } = await Swal.fire({
        titleText: `Finalizar venta: ${p.nombre_mascota}`,
        html: `
            <div class="text-start">
                <div class="d-flex flex-wrap gap-3 mb-2 small">
                    <span><b>Total:</b> ${Q(v.precio_venta)}</span>
                    <span><b>Cobrado:</b> ${Q(v.pagado)}</span>
                    <span class="${saldo > 0 ? 'neg' : 'pos'}"><b>Saldo:</b> ${Q(saldo)}</span>
                </div>
                <label class="form-label mb-1">Fecha de entrega</label>
                <input id="fFecha" type="date" class="form-control mb-2" value="${hoy()}">
                ${saldo > 0 ? `
                    <div class="alert alert-warning small">Para entregar, el pedido debe quedar <b>pagado completo</b>. Se va a registrar el pago del saldo: <b>${Q(saldo)}</b>.</div>
                    <label class="form-label mb-1">Comprobante de la transferencia (opcional)</label>
                    <input id="fArchivo" type="file" accept="image/*,application/pdf" class="form-control mb-2">`
                    : '<div class="alert alert-success small">Ya está pagado completo.</div>'}
                <div class="neg small"><b>Después de esto el pedido queda “Entregado” y ya no se puede cambiar.</b></div>
            </div>`,
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonText: saldo > 0 ? `Registrar ${Q(saldo)} y entregar` : 'Finalizar y entregar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: ORO,
        preConfirm: () => {
            const pop = Swal.getPopup();
            const archivo = pop.querySelector('#fArchivo')?.files[0] || null;
            if (archivo && archivo.size > 5 * 1024 * 1024) {
                Swal.showValidationMessage('El comprobante pesa más de 5 MB');
                return false;
            }
            return { fecha: pop.querySelector('#fFecha')?.value || hoy(), comprobante: archivo };
        },
    });
    if (!value) return;

    const res = await post('pedidos/finalizar', { id: p.id, ...value });
    if (res.codigo !== 1) {
        await error(res, 'No se pudo finalizar');
        return cargar();
    }
    toast.fire({ icon: 'success', title: res.mensaje, timer: 4000 });
    cargar();
}

// ── Cobros de un pedido: pagos, saldo y comprobantes ────────────────────────
async function cobros(p) {
    const r = await get('ventas/detalle', { id: p.venta_id });
    if (r.codigo !== 1) return error(r);

    const { venta: v, pagos } = r.datos;
    const saldo = num(v.saldo);

    const filas = pagos.length
        ? pagos.map((x) => `
            <tr>
                <td>${fecha(x.fecha)}</td>
                <td>${esc(x.tipo)}</td>
                <td class="text-end">${Q(x.monto)}</td>
                <td class="text-end">${x.comprobante
                    ? `<a href="${BASE}/API/ventas/comprobante?id=${x.id}" target="_blank" rel="noopener"><i class="bi bi-paperclip"></i> Ver</a>`
                    : '<span class="text-muted">—</span>'}</td>
                <td class="text-end"><button class="btn btn-sm btn-link p-0" data-edit-pago="${x.id}" title="Editar pago"><i class="bi bi-pencil"></i></button></td>
            </tr>`).join('')
        : '<tr><td colspan="5" class="hc-ayuda">Todavía no hay pagos</td></tr>';

    const res = await Swal.fire({
        titleText: `Cobros: ${p.nombre_mascota}`,
        html: `
            <div class="text-start small">
                <div class="d-flex flex-wrap gap-3 mb-2">
                    <span><b>Total:</b> ${Q(v.precio_venta)}</span>
                    <span><b>Cobrado:</b> ${Q(v.pagado)}</span>
                    <span class="${saldo > 0 ? 'neg' : 'pos'}"><b>Saldo:</b> ${Q(saldo)}</span>
                </div>
                <table class="table table-sm mb-0">
                    <thead><tr><th>Fecha</th><th>Tipo</th><th class="text-end">Monto</th><th class="text-end">Comprobante</th><th></th></tr></thead>
                    <tbody>${filas}</tbody>
                </table>
            </div>`,
        width: 560,
        showConfirmButton: saldo > 0,
        confirmButtonText: 'Registrar pago (anticipo u otro)',
        didOpen: (pop) => {
            pop.addEventListener('click', async (e) => {
                const b = e.target.closest('[data-edit-pago]');
                if (!b) return;
                Swal.close();
                const pago = pagos.find((x) => String(x.id) === b.dataset.editPago);
                if (pago) await editarPago(post, pago, BASE);
                await cargar();
                const actual = pedidos.find((x) => String(x.id) === String(p.id));
                if (actual) cobros(actual);
            });
        },
        showCancelButton: true,
        cancelButtonText: 'Cerrar',
        confirmButtonColor: ORO,
    });

    if (res.isConfirmed && (await cobrarVenta(post, v))) {
        await cargar();
        const actual = pedidos.find((x) => String(x.id) === String(p.id));
        if (actual) cobros(actual);
    }
}

// ── Pedido manual (los normales entran solos al vender desde Cotizar) ───────
async function cargarCatalogos() {
    if (!clientes.length) clientes = (await get('clientes/listar')).datos || [];
    if (!cotizaciones.length) {
        cotizaciones = ((await get('cotizaciones/listar')).datos || []).map((c) => ({ id: c.id, nombre: `#${c.id} · ${c.nombre_pieza}`, pieza: c.nombre_pieza }));
    }
}

async function formPedido(p = null) {
    await cargarCatalogos();
    if (!clientes.length) {
        return Swal.fire({ icon: 'info', title: 'Primero registra un cliente', text: 'Puedes hacerlo en Catálogos > Clientes.', confirmButtonColor: ORO });
    }

    const { value } = await Swal.fire({
        titleText: p ? 'Editar pedido' : 'Nuevo pedido',
        html: `
            <div class="text-start">
                <label class="form-label mb-1">Mascota</label>
                <input id="pMascota" class="form-control mb-2" placeholder="Ej. Panchito" value="${esc(p?.nombre_mascota)}">
                <label class="form-label mb-1">Cliente</label>
                <select id="pCliente" class="form-select mb-2">${opciones(clientes, 'Elige un cliente', p?.cliente_id ?? null)}</select>
                <label class="form-label mb-1">Cotización (opcional)</label>
                <select id="pCotizacion" class="form-select mb-2">${opciones(cotizaciones, 'Sin cotización', p?.cotizacion_id ?? null)}</select>
                <label class="form-label mb-1">Fecha de entrega prometida</label>
                <input id="pEntrega" type="date" class="form-control mb-2" value="${p?.fecha_entrega ? String(p.fecha_entrega).slice(0, 10) : ''}">
                <label class="form-label mb-1">Notas</label>
                <textarea id="pNotas" class="form-control" rows="2" placeholder="Color, detalles, lo que pidió el cliente…">${esc(p?.notas)}</textarea>
                ${p ? '' : '<div class="hc-ayuda mt-2">El pedido empieza en “Solicitado”.</div>'}
            </div>`,
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonText: 'Guardar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: ORO,
        didOpen: (pop) => {
            pop.querySelector('#pCotizacion').addEventListener('change', (e) => {
                const c = cotizaciones.find((x) => String(x.id) === e.target.value);
                if (c && !pop.querySelector('#pMascota').value.trim()) pop.querySelector('#pMascota').value = c.pieza;
            });
        },
        preConfirm: () => {
            const pop = Swal.getPopup();
            const nombre = pop.querySelector('#pMascota').value.trim();
            const cliente = pop.querySelector('#pCliente').value;
            if (!nombre || !cliente) {
                Swal.showValidationMessage('Escribe la mascota y elige el cliente');
                return false;
            }
            return {
                nombre_mascota: nombre,
                cliente_id: cliente,
                cotizacion_id: pop.querySelector('#pCotizacion').value,
                fecha_entrega: pop.querySelector('#pEntrega').value,
                notas: pop.querySelector('#pNotas').value.trim(),
            };
        },
    });
    if (!value) return;

    const r = p ? await post('pedidos/actualizar', { id: p.id, ...value }) : await post('pedidos/crear', value);
    if (r.codigo !== 1) return error(r);
    toast.fire({ icon: 'success', title: r.mensaje });
    cargar();
}

// Venta de un pedido creado a mano que todavía no tiene venta
async function registrarVenta(p) {
    const { value } = await Swal.fire({
        titleText: `Venta: ${p.nombre_mascota}`,
        html: `
            <div class="text-start">
                <label class="form-label mb-1">Precio al que vendiste (Q)</label>
                <input id="vPrecio" type="number" min="0" step="any" class="form-control mb-2">
                <label class="form-label mb-1">Anticipo recibido (Q)</label>
                <input id="vAnticipo" type="number" min="0" step="any" class="form-control mb-2" value="0">
                <label class="form-label mb-1">Comprobante del anticipo <span class="hc-ayuda">(captura de la transferencia)</span></label>
                <input id="vArchivo" type="file" accept="image/*,application/pdf" class="form-control mb-2">
                <label class="form-label mb-1">Fecha de la venta</label>
                <input id="vFecha" type="date" class="form-control" value="${hoy()}">
            </div>`,
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonText: 'Registrar venta',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: ORO,
        preConfirm: () => {
            const pop = Swal.getPopup();
            const precio = parseFloat(pop.querySelector('#vPrecio').value);
            const anticipo = parseFloat(pop.querySelector('#vAnticipo').value) || 0;
            if (!(precio > 0)) {
                Swal.showValidationMessage('Escribe el precio de venta');
                return false;
            }
            if (anticipo < 0 || anticipo > precio) {
                Swal.showValidationMessage('El anticipo no puede ser mayor al precio');
                return false;
            }
            const archivo = pop.querySelector('#vArchivo').files[0] || null;
            if (archivo && archivo.size > 5 * 1024 * 1024) {
                Swal.showValidationMessage('El comprobante pesa más de 5 MB');
                return false;
            }
            if (archivo && !(anticipo > 0)) {
                Swal.showValidationMessage('Subiste un comprobante pero el anticipo está en 0');
                return false;
            }
            return { precio_venta: precio, anticipo, fecha_venta: pop.querySelector('#vFecha').value, comprobante: archivo };
        },
    });
    if (!value) return;

    const r = await post('ventas/crear', { pedido_id: p.id, ...value });
    if (r.codigo !== 1) return error(r, 'No se pudo registrar');
    toast.fire({ icon: 'success', title: 'Venta registrada' });
    cargar();
}

async function eliminar(p) {
    if (!(await confirmar(`¿Eliminar el pedido de ${p.nombre_mascota}?`))) return;
    const r = await post('pedidos/eliminar', { id: p.id });
    if (r.codigo !== 1) return error(r, 'No se puede eliminar');
    toast.fire({ icon: 'success', title: r.mensaje });
    cargar();
}

// ── Eventos ─────────────────────────────────────────────────────────────────
$('btnNuevo').addEventListener('click', () => formPedido());
$('tablero').addEventListener('click', (e) => {
    const btn = e.target.closest('[data-acc]');
    if (!btn) return;
    const p = pedidos.find((x) => String(x.id) === btn.dataset.id);
    if (!p) return;

    const acc = btn.dataset.acc;
    if (acc === 'avanzar') avanzar(p);
    if (acc === 'finalizar') finalizar(p);
    if (acc === 'editar') formPedido(p);
    if (acc === 'cobros') cobros(p);
    if (acc === 'venta') registrarVenta(p);
    if (acc === 'eliminar') eliminar(p);
});

cargar();
