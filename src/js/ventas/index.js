import Swal from 'sweetalert2';
import { $, ORO, esc, num, Q, Q0, toast, crearApi, confirmar, error, opciones, hoy, mesActual, fecha, signo, cobrarVenta, editarPago } from '../comun.js';

const BASE = $('appVentas').dataset.base || '';
const { get, post } = crearApi(BASE);
let ventas = [];
let categorias = [];

// ── Listado ─────────────────────────────────────────────────────────────────
async function cargar() {
    const mes = $('mes').value;
    const r = await get('ventas/listar', mes ? { mes } : {});
    ventas = r.datos || [];
    pintar();
}

function pintar() {
    const suma = (campo) => ventas.reduce((s, v) => s + num(v[campo]), 0);
    $('kpis').innerHTML = `
        <div class="hc-kpi"><div class="t">Vendido</div><div class="v">${Q(suma('precio_venta'))}</div></div>
        <div class="hc-kpi"><div class="t">Cobrado</div><div class="v">${Q(suma('pagado'))}</div></div>
        <div class="hc-kpi"><div class="t">Por cobrar</div><div class="v ${suma('saldo') > 0 ? 'neg' : ''}">${Q(suma('saldo'))}</div></div>
        <div class="hc-kpi"><div class="t">Ganancia real</div><div class="v ${signo(suma('ganancia_real'))}">${Q(suma('ganancia_real'))}</div></div>`;

    const cuerpo = document.querySelector('#tablaVentas tbody');
    if (!ventas.length) {
        cuerpo.innerHTML = '<tr><td colspan="10" class="text-center text-muted py-3">No hay ventas en este periodo.</td></tr>';
        return;
    }

    cuerpo.innerHTML = ventas.map((v) => `
        <tr>
            <td>${fecha(v.fecha_venta)}</td>
            <td><b>${esc(v.descripcion)}</b></td>
            <td>${esc(v.cliente)}</td>
            <td class="text-end fw-bold">${Q0(v.precio_venta)}</td>
            <td class="text-end">${Q(v.pagado)}</td>
            <td class="text-end ${num(v.saldo) > 0 ? 'neg' : ''}">${Q(v.saldo)}</td>
            <td class="text-end">${v.ganancia_estimada === null ? '—' : Q(v.ganancia_estimada)}</td>
            <td class="text-end fw-bold ${signo(v.ganancia_real)}">${Q(v.ganancia_real)}${num(v.gastos_pendientes) === 1 ? ' <span class="hc-chip" title="Aún no registras los gastos de esta pieza: se usan los de la cotización">est.</span>' : ''}</td>
            <td class="text-end">${v.margen_real_pct === null ? '—' : `${num(v.margen_real_pct)}%`}</td>
            <td class="text-end"><button class="btn btn-sm btn-outline-dark" data-detalle="${v.id}"><i class="bi bi-eye"></i> Detalle</button></td>
        </tr>`).join('');
}

// ── Detalle de una venta ────────────────────────────────────────────────────
async function detalle(id) {
    const r = await get('ventas/detalle', { id });
    if (r.codigo !== 1) return error(r);
    const { venta: v, pagos, gastos } = r.datos;
    const saldo = num(v.saldo);

    const filasPagos = pagos.length
        ? pagos.map((p) => `<tr><td>${fecha(p.fecha)}</td><td>${esc(p.tipo)}</td><td class="text-end">${Q(p.monto)}</td>
            <td class="text-end text-nowrap">${p.comprobante ? `<a class="me-2" href="${BASE}/API/ventas/comprobante?id=${p.id}" target="_blank" rel="noopener" title="Ver comprobante"><i class="bi bi-paperclip"></i></a>` : ''}<button class="btn btn-sm btn-link p-0" data-edit-pago="${p.id}" title="Editar pago"><i class="bi bi-pencil"></i></button></td></tr>`).join('')
        : '<tr><td colspan="4" class="hc-ayuda">Sin pagos registrados</td></tr>';

    const filasGastos = gastos.length
        ? gastos.map((g) => `<tr><td>${fecha(g.fecha)}</td><td>${esc(g.categoria)}${g.descripcion ? ` · ${esc(g.descripcion)}` : ''}</td><td class="text-end">${Q(g.monto)}</td>
            <td class="text-end text-nowrap">${g.comprobante ? `<a class="me-2" href="${BASE}/API/gastos/comprobante?id=${g.id}" target="_blank" rel="noopener" title="Ver comprobante"><i class="bi bi-paperclip"></i></a>` : ''}<button class="btn btn-sm btn-link p-0 me-2" data-edit-gasto="${g.id}" title="Editar gasto"><i class="bi bi-pencil"></i></button><button class="btn btn-sm btn-link text-danger p-0" data-del-gasto="${g.id}" title="Eliminar gasto"><i class="bi bi-trash"></i></button></td></tr>`).join('')
        : '<tr><td colspan="4" class="hc-ayuda">Sin gastos registrados para esta pieza</td></tr>';

    const res = await Swal.fire({
        titleText: v.descripcion,
        width: 720,
        html: `
            <div class="text-start small">
                <div class="d-flex flex-wrap gap-3 mb-3">
                    <span><b>Cliente:</b> ${esc(v.cliente)}</span>
                    <span><b>Fecha:</b> ${fecha(v.fecha_venta)}</span>
                    <span><b>Precio:</b> ${Q(v.precio_venta)}</span>
                    <span><b>Cobrado:</b> ${Q(v.pagado)}</span>
                    <span class="${saldo > 0 ? 'neg' : 'pos'}"><b>Saldo:</b> ${Q(saldo)}</span>
                </div>
                <h6>Pagos</h6>
                <table class="table table-sm"><tbody>${filasPagos}</tbody></table>
                <h6>Gastos de esta pieza</h6>
                <table class="table table-sm"><tbody>${filasGastos}</tbody></table>
                <h6>Cuadro</h6>
                <table class="table table-sm mb-2"><tbody>
                    <tr><td>Costo estimado (cotización)</td><td class="text-end">${v.costo_estimado === null ? '—' : Q(v.costo_estimado)}</td></tr>
                    <tr><td>Ganancia estimada</td><td class="text-end">${v.ganancia_estimada === null ? '—' : Q(v.ganancia_estimada)}</td></tr>
                    <tr><td><b>Ganancia real</b>${num(v.gastos_pendientes) === 1 ? ' <span class="hc-chip">est.</span>' : ''}</td><td class="text-end fw-bold ${signo(v.ganancia_real)}">${Q(v.ganancia_real)} (${v.margen_real_pct === null ? '—' : `${num(v.margen_real_pct)}%`})</td></tr>
                </tbody></table>
                ${num(v.gastos_pendientes) === 1 ? '<div class="hc-ayuda mb-2">Esta ganancia aún es estimada: con “Agregar gasto” registra lo que de verdad pagaste (pintora, envío) y se vuelve real.</div>' : ''}
                <button class="btn btn-sm btn-outline-danger" data-del-venta="${v.id}"><i class="bi bi-trash"></i> Eliminar venta</button>
            </div>`,
        showConfirmButton: saldo > 0,
        showDenyButton: true,
        showCancelButton: true,
        confirmButtonText: 'Registrar pago',
        denyButtonText: 'Agregar gasto',
        cancelButtonText: 'Cerrar',
        confirmButtonColor: ORO,
        denyButtonColor: '#444',
        didOpen: (popup) => {
            popup.addEventListener('click', async (e) => {
                const ep = e.target.closest('[data-edit-pago]');
                const eg = e.target.closest('[data-edit-gasto]');
                const bg = e.target.closest('[data-del-gasto]');
                const bv = e.target.closest('[data-del-venta]');
                if (!ep && !eg && !bg && !bv) return;

                Swal.close();
                if (bv) return eliminarVenta(v);

                if (ep) {
                    // Los pagos se corrigen, no se borran
                    await editarPago(post, pagos.find((x) => String(x.id) === ep.dataset.editPago), BASE);
                } else if (eg) {
                    await editarGasto(gastos.find((x) => String(x.id) === eg.dataset.editGasto));
                } else if (await confirmar('¿Eliminar este gasto?')) {
                    const r2 = await post('gastos/eliminar', { id: bg.dataset.delGasto });
                    if (r2.codigo !== 1) await error(r2);
                }
                await cargar();
                detalle(id);
            });
        },
    });

    if (res.isConfirmed) {
        if (await registrarPago(v)) await cargar();
        detalle(id);
    } else if (res.isDenied) {
        if (await agregarGasto(v)) await cargar();
        detalle(id);
    }
}

const registrarPago = (v) => cobrarVenta(post, v);

async function editarGasto(g) {
    const { value } = await Swal.fire({
        titleText: 'Editar gasto',
        html: `
            <div class="text-start">
                <div class="hc-ayuda mb-2">${esc(g.categoria)}</div>
                <label class="form-label mb-1">Descripción</label>
                <input id="eDesc" class="form-control mb-2" value="${esc(g.descripcion)}">
                <label class="form-label mb-1">Monto (Q)</label>
                <input id="eMonto" type="number" min="0" step="any" class="form-control mb-2" value="${num(g.monto)}">
                <label class="form-label mb-1">Fecha</label>
                <input id="eFecha" type="date" class="form-control mb-2" value="${String(g.fecha).slice(0, 10)}">
                <label class="form-label mb-1">Comprobante ${g.comprobante ? '(sube otro solo si quieres reemplazar el actual)' : '(opcional)'}</label>
                <input id="eArchivo" type="file" accept="image/*,application/pdf" class="form-control">
            </div>`,
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonText: 'Guardar cambios',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: ORO,
        preConfirm: () => {
            const p = Swal.getPopup();
            const monto = parseFloat(p.querySelector('#eMonto').value);
            const descripcion = p.querySelector('#eDesc').value.trim();
            const archivo = p.querySelector('#eArchivo').files[0] || null;
            if (!(monto > 0) || !descripcion) {
                Swal.showValidationMessage('Escribe la descripción y el monto');
                return false;
            }
            if (archivo && archivo.size > 5 * 1024 * 1024) {
                Swal.showValidationMessage('El comprobante pesa más de 5 MB');
                return false;
            }
            return { descripcion, monto, fecha: p.querySelector('#eFecha').value, comprobante: archivo };
        },
    });
    if (!value) return;

    const r = await post('gastos/actualizar', { id: g.id, ...value });
    if (r.codigo !== 1) return error(r);
    toast.fire({ icon: 'success', title: r.mensaje });
}

async function agregarGasto(v) {
    if (!categorias.length) categorias = (await get('gastos/categorias')).datos || [];
    // Primero las categorías que se ligan a una pieza (pintora, envíos)
    const ordenadas = [...categorias].sort((a, b) => num(b.directo) - num(a.directo));

    const { value } = await Swal.fire({
        titleText: 'Gasto de esta pieza',
        html: `
            <div class="text-start">
                <label class="form-label mb-1">Categoría</label>
                <select id="gCategoria" class="form-select mb-2">${opciones(ordenadas, 'Elige una categoría')}</select>
                <label class="form-label mb-1">Descripción (opcional)</label>
                <input id="gDescripcion" type="text" class="form-control mb-2" placeholder="Ej. Pintura de ${esc(v.descripcion)}">
                <label class="form-label mb-1">Monto (Q)</label>
                <input id="gMonto" type="number" min="0" step="any" class="form-control mb-2">
                <label class="form-label mb-1">Fecha</label>
                <input id="gFecha" type="date" class="form-control mb-2" value="${hoy()}">
                <label class="form-label mb-1">Comprobante de la transferencia (opcional)</label>
                <input id="gArchivo" type="file" accept="image/*,application/pdf" class="form-control">
            </div>`,
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonText: 'Guardar gasto',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: ORO,
        preConfirm: () => {
            const p = Swal.getPopup();
            const sel = p.querySelector('#gCategoria');
            const monto = parseFloat(p.querySelector('#gMonto').value);
            if (!sel.value) {
                Swal.showValidationMessage('Elige la categoría');
                return false;
            }
            if (!(monto > 0)) {
                Swal.showValidationMessage('Escribe el monto');
                return false;
            }
            return {
                categoria_id: sel.value,
                descripcion: p.querySelector('#gDescripcion').value.trim() || sel.options[sel.selectedIndex].text,
                monto,
                fecha: p.querySelector('#gFecha').value,
                comprobante: p.querySelector('#gArchivo').files[0] || null,
            };
        },
    });
    if (!value) return false;

    const r = await post('gastos/crear', { venta_id: v.id, ...value });
    if (r.codigo !== 1) {
        await error(r);
        return false;
    }
    toast.fire({ icon: 'success', title: r.mensaje });
    return true;
}

async function eliminarVenta(v) {
    if (!(await confirmar(`¿Eliminar la venta de ${v.descripcion}?`))) return detalle(v.id);
    const r = await post('ventas/eliminar', { id: v.id });
    if (r.codigo !== 1) return error(r);
    toast.fire({ icon: 'success', title: r.mensaje });
    cargar();
}

// ── Eventos ─────────────────────────────────────────────────────────────────
$('mes').addEventListener('change', cargar);
$('tablaVentas').addEventListener('click', (e) => {
    const btn = e.target.closest('[data-detalle]');
    if (btn) detalle(btn.dataset.detalle);
});

$('mes').value = mesActual();
cargar();
