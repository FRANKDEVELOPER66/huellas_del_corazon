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

// ---- Vista previa de comprobantes: se abre en una ventana grande encima de la pantalla, sin cambiar de página ----
// Cualquier enlace a ".../comprobante?id=" se intercepta aquí (funciona también dentro de otros avisos, sin cerrarlos).
function abrirVisorComprobante(url) {
    if (document.getElementById('hcVisor')) return;
    const fondo = document.createElement('div');
    fondo.id = 'hcVisor';
    fondo.style.cssText = 'position:fixed;inset:0;z-index:5000;background:rgba(15,15,15,.82);display:flex;flex-direction:column;align-items:center;justify-content:center;padding:16px';
    fondo.innerHTML = `
        <div style="width:min(1000px,96vw);height:min(90vh,900px);background:#fff;border-radius:12px;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.5)">
            <div style="background:#1c1c1c;color:#fff;padding:10px 16px;display:flex;align-items:center;gap:10px">
                <i class="bi bi-paperclip" style="color:#f6c33b;font-size:1.2rem"></i>
                <b class="flex-grow-1">Comprobante</b>
                <a href="${esc(url)}" target="_blank" rel="noopener" data-sin-visor class="btn btn-sm btn-outline-light"><i class="bi bi-box-arrow-up-right me-1"></i>Abrir aparte</a>
                <button type="button" id="hcVisorCerrar" class="btn btn-sm" style="background:#f6c33b;color:#1c1c1c;font-weight:700"><i class="bi bi-x-lg me-1"></i>Cerrar</button>
            </div>
            <div id="hcVisorCuerpo" style="flex:1;overflow:auto;background:#2b2b2b;display:flex;align-items:center;justify-content:center;color:#ddd">Cargando…</div>
        </div>`;
    document.body.appendChild(fondo);

    let blobUrl = null;
    const cerrar = () => {
        document.removeEventListener('keydown', alTeclear, true);
        if (blobUrl) URL.revokeObjectURL(blobUrl);
        fondo.remove();
    };
    const alTeclear = (e) => {
        if (e.key === 'Escape') { e.stopPropagation(); e.preventDefault(); cerrar(); }
    };
    document.addEventListener('keydown', alTeclear, true);
    fondo.addEventListener('click', (e) => { if (e.target === fondo || e.target.closest('#hcVisorCerrar')) cerrar(); });

    const cuerpo = fondo.querySelector('#hcVisorCuerpo');
    fetch(url, { credentials: 'same-origin' })
        .then((r) => { if (!r.ok) throw new Error(); return r.blob(); })
        .then((b) => {
            if (!document.getElementById('hcVisor')) return;
            blobUrl = URL.createObjectURL(b);
            if (b.type.startsWith('image/')) {
                cuerpo.innerHTML = `<img src="${blobUrl}" alt="Comprobante" style="max-width:100%;max-height:100%;object-fit:contain">`;
            } else if (b.type === 'application/pdf') {
                cuerpo.innerHTML = `<iframe src="${blobUrl}" title="Comprobante" style="width:100%;height:100%;border:0;background:#fff"></iframe>`;
            } else {
                cuerpo.innerHTML = '<div class="p-4 text-center">Este tipo de archivo no se puede mostrar aquí.<br>Usa “Abrir aparte”.</div>';
            }
        })
        .catch(() => { cuerpo.textContent = 'No se pudo cargar el comprobante.'; });
}

if (!window.__hcVisorComprobante) {
    window.__hcVisorComprobante = true;
    document.addEventListener('click', (e) => {
        const a = e.target.closest && e.target.closest('a[href*="/comprobante?id="]');
        if (!a || a.hasAttribute('data-sin-visor') || e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0) return;
        e.preventDefault();
        abrirVisorComprobante(a.getAttribute('href'));
    });
}
