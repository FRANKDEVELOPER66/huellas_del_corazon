// Utilidades compartidas por las pantallas del cotizador (Ventas, Gastos, Pintores, Balance)
import Swal from 'sweetalert2';

export const ORO = '#c99a14';
export const $ = (id) => document.getElementById(id);
export const num = (n) => Number(n || 0);
export const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
export const Q = (n) => 'Q' + num(n).toLocaleString('es-GT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
export const Q0 = (n) => 'Q' + num(n).toLocaleString('es-GT', { maximumFractionDigits: 0 });
export const signo = (n) => (num(n) < 0 ? 'neg' : 'pos');
export const hoy = () => new Date().toLocaleDateString('en-CA'); // YYYY-MM-DD
export const mesActual = () => hoy().slice(0, 7); // YYYY-MM
export const fecha = (s) => {
    if (!s) return '—';
    const [a, m, d] = String(s).slice(0, 10).split('-');
    return `${d}/${m}/${a}`;
};
export const etiquetaMes = (ym) => new Date(`${ym}-01T12:00:00`).toLocaleDateString('es-GT', { month: 'long', year: 'numeric' });

export const toast = Swal.mixin({ toast: true, position: 'top-end', showConfirmButton: false, timer: 2500, timerProgressBar: true });

export const opciones = (lista, vacio = null, seleccionado = null) =>
    (vacio ? `<option value="">${vacio}</option>` : '') +
    lista.map((x) => `<option value="${x.id}" ${seleccionado !== null && String(x.id) === String(seleccionado) ? 'selected' : ''}>${esc(x.nombre)}</option>`).join('');

export const error = (r, titulo = 'No se pudo completar') =>
    Swal.fire({ icon: 'error', title: titulo, text: r.mensaje, confirmButtonColor: ORO });

export async function confirmar(titulo, boton = 'Eliminar') {
    const { isConfirmed } = await Swal.fire({
        icon: 'question',
        title: titulo,
        showCancelButton: true,
        confirmButtonText: boton,
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#b42318',
    });
    return isConfirmed;
}

const errorServidor = { codigo: 0, mensaje: 'No se pudo comunicar con el servidor' };

// Si PHP devuelve un error o aviso en HTML en lugar de JSON, se muestra ese error (y en la consola).
const leer = async (respuesta) => {
    const texto = await respuesta.text();
    try {
        return JSON.parse(texto);
    } catch (e) {
        console.error('Respuesta del servidor:', texto);
        const limpio = texto.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 300);
        return { codigo: 0, mensaje: `El servidor no devolvió JSON: ${limpio}` };
    }
};

export function crearApi(base) {
    const get = async (ruta, params = {}) => {
        try {
            const url = new URL(`${base}/API/${ruta}`, window.location.origin);
            Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v));
            return await leer(await fetch(url));
        } catch (e) {
            return errorServidor;
        }
    };

    const post = async (ruta, datos = {}) => {
        try {
            const fd = new FormData();
            Object.entries(datos).forEach(([k, v]) => {
                if (v !== null && v !== undefined) fd.append(k, v);
            });
            return await leer(await fetch(`${base}/API/${ruta}`, { method: 'POST', body: fd }));
        } catch (e) {
            return errorServidor;
        }
    };

    return { get, post };
}

// ── Pagos con comprobante (lo usan Ventas y Pedidos) ────────────────────────
// Formulario de pago: monto, tipo, fecha y comprobante opcional.
// Con `pago` sirve para EDITAR uno ya registrado. Devuelve los valores, o null si se cancela.
export async function formPago({ saldo = 0, pagado = 0, pago = null, base = '' }) {
    const editar = Boolean(pago);
    const tipoActual = editar ? pago.tipo : num(pagado) === 0 ? 'anticipo' : 'saldo';

    const { value } = await Swal.fire({
        titleText: editar ? 'Editar pago' : 'Registrar pago',
        html: `
            <div class="text-start">
                <label class="form-label mb-1">Monto (Q)${editar ? '' : ` — saldo pendiente: ${Q(saldo)}`}</label>
                <input id="pMonto" type="number" min="0" step="any" class="form-control mb-2" value="${editar ? num(pago.monto) : saldo}">
                <label class="form-label mb-1">Tipo</label>
                <select id="pTipo" class="form-select mb-2">
                    ${[['anticipo', 'Anticipo'], ['saldo', 'Saldo'], ['otro', 'Otro']].map(([k, t]) => `<option value="${k}" ${tipoActual === k ? 'selected' : ''}>${t}</option>`).join('')}
                </select>
                <label class="form-label mb-1">Fecha</label>
                <input id="pFecha" type="date" class="form-control mb-2" value="${editar ? String(pago.fecha).slice(0, 10) : hoy()}">
                <label class="form-label mb-1">Comprobante de la transferencia ${editar && pago.comprobante ? '(sube otro solo si quieres reemplazar el actual)' : '(opcional)'}</label>
                ${editar && pago.comprobante ? `<div class="mb-1"><a href="${base}/API/ventas/comprobante?id=${pago.id}" target="_blank" rel="noopener"><i class="bi bi-paperclip"></i> Ver comprobante actual</a></div>` : ''}
                <input id="pArchivo" type="file" accept="image/*,application/pdf" class="form-control">
            </div>`,
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonText: editar ? 'Guardar cambios' : 'Registrar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: ORO,
        preConfirm: () => {
            const p = Swal.getPopup();
            const monto = parseFloat(p.querySelector('#pMonto').value);
            const archivo = p.querySelector('#pArchivo').files[0] || null;
            if (!(monto > 0)) {
                Swal.showValidationMessage('Escribe el monto');
                return false;
            }
            if (archivo && archivo.size > 5 * 1024 * 1024) {
                Swal.showValidationMessage('El comprobante pesa más de 5 MB');
                return false;
            }
            return { monto, tipo: p.querySelector('#pTipo').value, fecha: p.querySelector('#pFecha').value, comprobante: archivo };
        },
    });
    return value || null;
}

// Pide los datos, registra el pago y avisa. Devuelve true si se registró.
export async function cobrarVenta(post, v) {
    const valores = await formPago({ saldo: num(v.saldo), pagado: num(v.pagado) });
    if (!valores) return false;

    const r = await post('ventas/pago', { venta_id: v.id, ...valores });
    if (r.codigo !== 1) {
        await error(r);
        return false;
    }
    toast.fire({ icon: 'success', title: r.mensaje });
    return true;
}

// Corrige un pago ya registrado (monto, tipo, fecha o comprobante). Devuelve true si se guardó.
export async function editarPago(post, pago, base = '') {
    const valores = await formPago({ pago, base });
    if (!valores) return false;

    const r = await post('ventas/pago/actualizar', { id: pago.id, ...valores });
    if (r.codigo !== 1) {
        await error(r);
        return false;
    }
    toast.fire({ icon: 'success', title: r.mensaje });
    return true;
}

// ── Gastos: avisa si parece repetido y deja confirmarlo ─────────────────────
// Devuelve la respuesta del servidor, o { codigo: 0, cancelado: true } si el usuario no confirma.
export async function postGasto(post, ruta, datos) {
    let r = await post(ruta, datos);
    if (r.codigo !== 1 && r.duplicado) {
        if (!(await confirmar(r.mensaje, 'Sí, es otro gasto'))) return { codigo: 0, cancelado: true };
        r = await post(ruta, { ...datos, confirmar_duplicado: 1 });
    }
    return r;
}
