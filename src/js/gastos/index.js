import Swal from 'sweetalert2';
import { $, ORO, esc, num, Q, toast, crearApi, confirmar, error, opciones, hoy, mesActual, fecha, postGasto } from '../comun.js';

const BASE = $('appGastos').dataset.base || '';
const { get, post } = crearApi(BASE);
const CARRETE_VACIO_G = 250; // peso fijo de un carrete vacío (báscula − 250 g = filamento que queda)
if ($('lnkMateriales')) $('lnkMateriales').href = `${BASE}/materiales`;

// Qué hace cada tipo de categoría en el estado de resultados
const AYUDA_TIPO = {
    operativo: 'Gasto de operación: se resta de tu utilidad neta del mes.',
    incluido: 'Ya está dentro del costo de cada pieza: aquí solo cuenta como salida de dinero, no se resta otra vez de la utilidad.',
    directo: 'Costo de una pieza (pintora, envío, otros).',
};
// Desde este monto el comprobante es obligatorio (el servidor lo exige igual)
const COMPROBANTE_DESDE = 100;
const kgf = (n) => num(n).toLocaleString('es-GT', { minimumFractionDigits: 3, maximumFractionDigits: 3 }) + ' kg';
let gastos = [];
let categorias = [];
let movimientos = [];
let pendientesPintora = [];
let cajaActual = null;
let inventario = { materiales: [], compras: [] };

async function cargar() {
    cargarCaja();
    cargarInventario();
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
        cuerpo.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-3">No hay gastos en este periodo.</td></tr>';
        return;
    }
    cuerpo.innerHTML = gastos.map((g) => `
        <tr>
            <td>${fecha(g.fecha)}</td>
            <td>${esc(g.categoria)}</td>
            <td>${esc(g.descripcion)}${num(g.ediciones) > 0 ? ` <button class="btn btn-sm btn-link p-0" data-hist-gasto="${g.id}" title="Ver cómo estaba antes"><span class="hc-chip">Editado ${num(g.ediciones)}×</span></button>` : ''}</td>
            <td>${g.venta_id ? `<span class="hc-chip">Venta #${g.venta_id}</span>` : '<span class="text-muted">General</span>'}</td>
            <td>${g.origen === 'bolsillo' ? '<span class="hc-chip" style="background:#fff4e0;color:#b26a00">De mi bolsillo</span>' : '<span class="hc-chip">Caja</span>'}</td>
            <td class="text-end fw-bold">${Q(g.monto)}</td>
            <td class="text-end text-nowrap">
                ${g.comprobante ? `<a class="btn btn-sm btn-outline-secondary" href="${BASE}/API/gastos/comprobante?id=${g.id}" target="_blank" rel="noopener" title="Ver comprobante"><i class="bi bi-paperclip"></i></a>` : ''}
                <button class="btn btn-sm btn-outline-dark" data-editar="${g.id}" title="Editar"><i class="bi bi-pencil"></i></button>
            </td>
        </tr>`).join('');
}

const radioOrigen = (actual = 'caja', nombre = 'gOrigen') => `
    <div class="d-flex gap-3 mb-2">
        <label class="form-check"><input class="form-check-input" type="radio" name="${nombre}" value="caja" ${actual !== 'bolsillo' ? 'checked' : ''}> <span class="form-check-label">Caja del negocio</span></label>
        <label class="form-check"><input class="form-check-input" type="radio" name="${nombre}" value="bolsillo" ${actual === 'bolsillo' ? 'checked' : ''}> <span class="form-check-label">De mi bolsillo</span></label>
    </div>`;

async function formGasto(g = null) {
    // Aquí van los gastos generales. Pintora/envíos: Pagos pendientes. Filamento: Inventario.
    const tipoEdit = g ? g.tipo_categoria : null;
    const lista = categorias.filter((c) => (tipoEdit ? c.tipo === tipoEdit : c.tipo === 'operativo' || c.tipo === 'incluido'));
    const tieneComprobante = Boolean(g?.comprobante);

    const { value } = await Swal.fire({
        titleText: g ? 'Editar gasto' : 'Nuevo gasto',
        html: `
            <div class="text-start">
                ${g ? '' : '<div class="hc-ayuda mb-2">Para <b>comprar filamento</b> usa “Comprar filamento” en el inventario. Para pagar a la <b>pintora o envíos</b> usa “Registrar pago” en pagos pendientes.</div>'}
                <label class="form-label mb-1">Fecha</label>
                <input id="gFecha" type="date" class="form-control mb-2" max="${hoy()}" value="${g ? String(g.fecha).slice(0, 10) : hoy()}">
                <label class="form-label mb-1">Categoría</label>
                <select id="gCategoria" class="form-select mb-2">${opciones(lista, 'Elige una categoría', g?.categoria_id ?? null)}</select>
                <div id="gAyuda" class="hc-ayuda mb-2"></div>
                <label class="form-label mb-1">Descripción</label>
                <input id="gDescripcion" type="text" class="form-control mb-2" placeholder="Ej. Boquilla 0.4 mm" value="${esc(g?.descripcion)}">
                <label class="form-label mb-1">Monto (Q)</label>
                <input id="gMonto" type="number" min="0" step="any" class="form-control mb-2" value="${g ? num(g.monto) : ''}">
                <label class="form-label mb-1">Pagado con</label>
                ${radioOrigen(g?.origen || 'caja')}
                <div id="gAvisoCaja" class="hc-ayuda mb-2"></div>
                <label class="form-label mb-1">Comprobante <span id="gReq" class="hc-ayuda"></span>${tieneComprobante ? ' <span class="hc-ayuda">(sube otro solo si quieres reemplazar el actual; el anterior queda guardado)</span>' : ''}</label>
                <input id="gArchivo" type="file" accept="image/*,application/pdf" class="form-control">
            </div>`,
        didOpen: (pop) => {
            const sel = pop.querySelector('#gCategoria');
            const monto = pop.querySelector('#gMonto');
            const ayudaCat = () => {
                const c = categorias.find((x) => String(x.id) === sel.value);
                pop.querySelector('#gAyuda').textContent = c ? AYUDA_TIPO[c.tipo] || '' : '';
            };
            const ayudaMonto = () => {
                const m = parseFloat(monto.value) || 0;
                const exige = m >= COMPROBANTE_DESDE && !tieneComprobante;
                pop.querySelector('#gReq').textContent = exige ? `(obligatorio: el gasto es de Q${COMPROBANTE_DESDE} o más)` : `(obligatorio desde Q${COMPROBANTE_DESDE})`;
                pop.querySelector('#gReq').style.color = exige ? '#b42318' : '';
                const origen = pop.querySelector('input[name="gOrigen"]:checked').value;
                const libre = cajaActual ? num(cajaActual.disponible) : null;
                pop.querySelector('#gAvisoCaja').innerHTML = origen === 'caja' && libre !== null
                    ? `Disponible para gastar: <b style="color:${m > libre + 0.005 ? '#b42318' : '#117a43'}">${Q(libre)}</b>${m > libre + 0.005 ? ' · no alcanza: elige “De mi bolsillo”' : ''}`
                    : origen === 'bolsillo' ? 'Se registra como dinero que pones en el negocio y luego se gasta: la caja queda igual.' : '';
            };
            sel.addEventListener('change', ayudaCat);
            pop.addEventListener('input', ayudaMonto);
            ayudaCat();
            ayudaMonto();
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
            const fechaGasto = p.querySelector('#gFecha').value;
            const invalido = (m) => {
                Swal.showValidationMessage(m);
                return false;
            };
            if (!categoria_id) return invalido('Elige la categoría');
            if (!descripcion) return invalido('Escribe la descripción');
            if (!(monto > 0)) return invalido('Escribe el monto');
            if (!fechaGasto || fechaGasto > hoy()) return invalido('La fecha no puede ser futura');
            const archivo = p.querySelector('#gArchivo').files[0] || null;
            if (archivo && archivo.size > 5 * 1024 * 1024) return invalido('El comprobante pesa más de 5 MB');
            if (monto >= COMPROBANTE_DESDE && !archivo && !tieneComprobante) return invalido(`Los gastos de Q${COMPROBANTE_DESDE} o más llevan comprobante`);
            return { fecha: fechaGasto, categoria_id, descripcion, monto, origen: p.querySelector('input[name="gOrigen"]:checked').value, comprobante: archivo };
        },
    });
    if (!value) return;

    const r = g ? await post('gastos/actualizar', { id: g.id, ...value }) : await postGasto(post, 'gastos/crear', value);
    if (r.cancelado) return;
    if (r.codigo !== 1) return error(r);
    toast.fire({ icon: 'success', title: r.mensaje });
    cargar();
}

// Cómo estaba un gasto o una compra antes de cada edición
async function verHistorial(tipo, id) {
    const r = await get('gastos/historial', { tipo, id });
    if (r.codigo !== 1) return error(r);

    const nombreMat = (mid) => inventario.materiales.find((m) => String(m.id) === String(mid))?.nombre || `Material #${mid}`;
    const filas = (r.datos || []).map((h) => {
        const a = h.anterior || {};
        const detalle = tipo === 'compra'
            ? `${fecha(a.fecha)} · ${esc(nombreMat(a.material_id))} · ${kgf(a.kg)} · ${Q(a.costo_total)}`
            : `${fecha(a.fecha)} · ${esc(a.descripcion)} · <b>${Q(a.monto)}</b> · ${a.origen === 'bolsillo' ? 'de mi bolsillo' : 'caja'}`;
        return `<tr><td class="text-nowrap hc-ayuda">${fecha(h.cambiado_en)}</td><td>${detalle}</td></tr>`;
    }).join('');

    await Swal.fire({
        titleText: 'Cómo estaba antes',
        width: 640,
        html: `<div class="text-start"><div class="hc-ayuda mb-2">Cada fila es lo que había ANTES de un cambio (la fecha es cuándo se editó).</div>
            <table class="table table-sm"><tbody>${filas || '<tr><td class="text-muted">Sin ediciones.</td></tr>'}</tbody></table></div>`,
        confirmButtonText: 'Cerrar',
        confirmButtonColor: ORO,
    });
}

$('mes').addEventListener('change', cargar);
$('btnNuevoGasto').addEventListener('click', () => formGasto());
$('tablaGastos').addEventListener('click', (e) => {
    const ed = e.target.closest('[data-editar]');
    const hist = e.target.closest('[data-hist-gasto]');
    if (hist) return verHistorial('gasto', hist.dataset.histGasto);
    if (!ed) return;
    const g = gastos.find((x) => String(x.id) === ed.dataset.editar);
    // Las compras de filamento se editan como compra (kg y costo)
    if (g.tipo_categoria === 'inventario') {
        const compra = inventario.compras.find((c) => String(c.gasto_id) === String(g.id));
        return compra ? formCompra(compra) : Swal.fire({ icon: 'info', title: 'Compra sin kg registrados', text: 'Esta compra es anterior al inventario. Regístrala de nuevo con “Comprar filamento”.', confirmButtonColor: ORO });
    }
    formGasto(g);
});

(async () => {
    $('mes').value = mesActual();
    categorias = (await get('gastos/categorias')).datos || [];
    cargar();
})();

// Tarjeta de resumen con su propio color e icono (cada rubro se distingue de un vistazo)
const kpi = (color, icono, titulo, valor, nota = '') => `
    <div class="caja-box k" style="--c:${color}"><span><i class="bi bi-${icono}"></i>${titulo}</span><b>${valor}</b>${nota ? `<small>${nota}</small>` : ''}</div>`;

// ── Caja del negocio ────────────────────────────────────────────────────────
async function cargarCaja() {
    const r = await get('caja/resumen');
    if (r.codigo !== 1) return;

    const c = r.datos.caja;
    cajaActual = c;
    movimientos = r.datos.movimientos || [];

    $('cajaKpis').innerHTML = `
        <div class="caja-grid">
            ${kpi('#1d5fa8', 'safe2-fill', 'Caja hoy', Q(c.caja), `Cobrado ${Q(c.cobros)} + aportes ${Q(c.aportes)} − retiros ${Q(c.retiros)} − pagado ${Q(c.gastos)}`)}
            ${kpi('#d6336c', 'hourglass-split', 'Por pagar (pintora y envíos)', Q(c.por_pagar), 'Lo que aún no pagas de las piezas vendidas (pintora, envíos y otros): se resta de lo disponible')}
            ${kpi(c.disponible < 0 ? '#b42318' : '#0f9d58', 'wallet2', 'Disponible para gastar', Q(c.disponible), c.disponible < 0 ? 'No alcanza: hoy debes más de lo que tienes' : 'Caja menos lo que ya debes pagar. Es una sola bolsa: abajo se divide en ganancia y reposición')}
        </div>
        <div class="caja-grid mt-2">
            ${kpi('#c98a00', 'trophy-fill', 'Ganancia que puedes retirar', Q(c.ganancia_retirable), 'Lo ganado con piezas entregadas, ya descontados costos, gastos de operación y lo que retiraste. Es lo único que puedes sacar de la caja (ya sin la reserva)')}
            ${kpi('#6d4c41', 'tools', 'Reserva para repuestos y equipo', `${Q(c.reserva)} <small style="font-size:.9rem;font-weight:400">de ${Q(c.reserva_meta)}</small>`, 'Parte de tu ganancia que se queda en el negocio para repuestos o equipo. Se cambia en Costos y margen')}
            ${kpi('#7048e8', 'arrow-repeat', 'Para reponer material y capital', Q(c.para_reponer), `Se llena primero. Material por reponer: ${Q(c.material_cubierto)}${num(c.reponer_pendiente) > num(c.material_cubierto) + 0.005 ? ` de ${Q(c.reponer_pendiente)}` : ''} · tu capital en el negocio: ${Q(c.capital_en_negocio)}. No es ganancia`)}
        </div>
        ${franjaCapital(c)}
        ${c.caja < 0 ? '<div class="hc-ayuda mt-2">La caja sale negativa porque pagaste cosas antes de cobrar. Si fue con tu dinero, regístralo con “Poner dinero”.</div>' : ''}`;

    document.querySelector('#tablaAportes tbody').innerHTML = movimientos.length
        ? movimientos.map((m) => `
            <tr>
                <td>${fecha(m.fecha)}</td>
                <td><span class="hc-chip">${m.tipo === 'aporte' ? 'Pusiste' : m.tipo === 'devolucion' ? 'Capital devuelto' : 'Sacaste'}</span></td>
                <td>${esc(m.descripcion)}</td>
                <td class="text-end fw-bold" style="color:${m.tipo === 'aporte' ? '#117a43' : '#b42318'}">${m.tipo === 'aporte' ? '+' : '−'}${Q(m.monto)}</td>
                <td class="text-end">${m.gasto_id ? '<span class="hc-ayuda" title="Viene de un gasto pagado de tu bolsillo: edita ese gasto">de un gasto</span>' : m.tipo === 'devolucion' ? '' : `<button class="btn btn-sm btn-outline-danger" data-del-mov="${m.id}"><i class="bi bi-trash"></i></button>`}</td>
            </tr>`).join('')
        : '<tr><td colspan="5" class="text-center text-muted py-2">Aún no registras dinero que pongas o saques del negocio.</td></tr>';
}

// Inversión y devolución de capital: se activa cuando el negocio vale varias veces lo que has puesto
function franjaCapital(c) {
    if (!(num(c.inversion) > 0)) return '';
    const pct = Math.min(100, Math.round((num(c.patrimonio) / Math.max(num(c.capital_meta), 1)) * 100));
    const veces = num(c.capital_multiplo);
    const btn = $('btnCapital');
    if (btn) {
        btn.disabled = !c.capital_sano;
        btn.title = c.capital_sano
            ? `Puedes devolver hasta ${Q(c.capital_devolvible)}`
            : `Se activa cuando el negocio valga ${veces} veces tu inversión (${Q(c.capital_meta)})`;
    }
    const detalle = c.capital_sano
        ? `<b style="color:#087f5b">Negocio sano.</b> Puedes devolver hasta <b>${Q(c.capital_devolvible)}</b> de tu capital${num(c.capital_devolvible) < 0.005 ? ' (hoy no sobra dinero después de reponer material y llenar la reserva)' : ''}. Te quedan por recuperar ${Q(c.capital_neto)}.`
        : `El botón “Devolver capital” se activa cuando el negocio valga <b>${veces} veces</b> lo que has puesto (${Q(c.capital_meta)}). Hoy vale <b>${Q(c.patrimonio)}</b>.`;
    return `
        <div class="mt-3 p-3" style="border:1px dashed #087f5b;border-radius:12px;background:#f1faf7">
            <div class="d-flex justify-content-between flex-wrap gap-1 mb-1">
                <b style="color:#087f5b"><i class="bi bi-piggy-bank me-1"></i>Tu inversión: ${Q(c.inversion)}</b>
                <span class="hc-ayuda">Patrimonio ${Q(c.patrimonio)} de ${Q(c.capital_meta)}</span>
            </div>
            <div class="progress mb-2" style="height:10px"><div class="progress-bar" style="width:${pct}%;background:#087f5b"></div></div>
            <div class="hc-ayuda">${detalle} <span class="text-muted">Incluye lo que pusiste en efectivo y el filamento que ya tenías.</span></div>
        </div>`;
}

async function formDevolver() {
    const c = cajaActual;
    const { value } = await Swal.fire({
        titleText: 'Devolver capital',
        html: `
            <div class="text-start">
                <div class="hc-ayuda mb-2">Dinero que pusiste y ahora recuperas. No es ganancia ni baja tu utilidad. Hoy puedes devolver hasta <b>${Q(c?.capital_devolvible ?? 0)}</b>.</div>
                <label class="form-label mb-1">Monto (Q)</label>
                <input id="dMonto" type="number" min="0" step="any" class="form-control mb-2" max="${c?.capital_devolvible ?? 0}">
                <label class="form-label mb-1">Detalle</label>
                <input id="dDetalle" class="form-control mb-2" value="Devolución de capital">
                <label class="form-label mb-1">Fecha</label>
                <input id="dFecha" type="date" class="form-control" value="${hoy()}">
            </div>`,
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonText: 'Devolver',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: ORO,
        preConfirm: () => {
            const p = Swal.getPopup();
            const monto = parseFloat(p.querySelector('#dMonto').value);
            if (!(monto > 0)) { Swal.showValidationMessage('Escribe el monto'); return false; }
            if (monto > num(c?.capital_devolvible) + 0.005) { Swal.showValidationMessage(`Puedes devolver hasta ${Q(c?.capital_devolvible)}`); return false; }
            return { tipo: 'devolucion', monto, descripcion: p.querySelector('#dDetalle').value.trim() || 'Devolución de capital', fecha: p.querySelector('#dFecha').value };
        },
    });
    if (!value) return;
    const r = await post('caja/crear', value);
    if (r.codigo !== 1) return error(r);
    toast.fire({ icon: 'success', title: r.mensaje });
    cargarCaja();
}

async function formMovimiento(tipo) {
    const esAporte = tipo === 'aporte';
    const { value } = await Swal.fire({
        titleText: esAporte ? 'Poner dinero en el negocio' : 'Sacar dinero del negocio',
        html: `
            <div class="text-start">
                <div class="hc-ayuda mb-2">${esAporte
                    ? 'Dinero tuyo que pones para comprar material u otras cosas del negocio.'
                    : `Ganancia que te llevas. Hoy puedes retirar como ganancia: <b>${Q(cajaActual?.ganancia_retirable ?? 0)}</b>. No puedes sacar más que eso: el resto es dinero para reponer material.`}</div>
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
$('btnCapital').addEventListener('click', () => formDevolver());
$('tablaAportes').addEventListener('click', async (e) => {
    const b = e.target.closest('[data-del-mov]');
    if (!b || !(await confirmar('¿Eliminar este movimiento de caja?'))) return;
    const r = await post('caja/eliminar', { id: b.dataset.delMov });
    if (r.codigo !== 1) return error(r);
    cargarCaja();
});

// ── Cierre de período: conciliar con el banco y bloquear ────────────────────
// Eliges hasta qué fecha cierras, comparas la caja de la app (a esa fecha) con el saldo del banco y subes la
// captura. Lo que no marcas es "registrado pero aún no pasa por el banco" y se arrastra al siguiente cierre.
// Lo que sobre o falte es dinero que se movió en el banco sin registrarse en la app.
let conc = null;

async function cargarConciliacion() {
    const r = await get('cierres/preparar', $('cHasta').value ? { hasta: $('cHasta').value } : {});
    if (r.codigo !== 1) return error(r);
    conc = r.datos;
    $('cHasta').value = conc.hasta;

    $('cEstado').innerHTML = conc.ultimo
        ? `<div class="alert alert-success py-2 mb-0 d-flex flex-wrap justify-content-between align-items-center gap-2">
               <span><i class="bi bi-lock-fill me-1"></i>Cerrado hasta el <b>${fecha(conc.ultimo.hasta)}</b>. Lo nuevo empieza el ${fecha(conc.desde)}.</span>
               <button class="btn btn-sm btn-outline-danger" id="btnReabrir" data-id="${conc.ultimo.id}"><i class="bi bi-unlock me-1"></i>Reabrir último cierre</button>
           </div>`
        : '<div class="alert alert-secondary py-2 mb-0"><i class="bi bi-unlock me-1"></i>Aún no has cerrado ningún período: todo se puede editar.</div>';

    $('cAviso').innerHTML = conc.aviso ? `<div class="alert alert-warning py-2">${esc(conc.aviso)}</div>` : '';
    if (!conc.aviso && conc.hasta === hoy()) {
        $('cAviso').innerHTML = '<div class="alert alert-warning py-2"><i class="bi bi-exclamation-triangle me-1"></i>Estás cerrando <b>hoy</b>: ya no podrás registrar nada con fecha de hoy hasta reabrir. Normalmente se cierra una fecha pasada (fin de semana, quincena o mes).</div>';
    }

    const CHIP = { cobro: ['Cobro', '#117a43'], aporte: ['Pusiste', '#117a43'], retiro: ['Sacaste', '#b42318'], devolucion: ['Capital devuelto', '#b42318'], gasto: ['Gasto', '#b42318'] };
    document.querySelector('#tablaConc tbody').innerHTML = conc.movimientos.length
        ? conc.movimientos.map((m) => `
            <tr>
                <td class="text-center"><input type="checkbox" class="form-check-input c-visto" data-ref="${esc(m.ref)}" data-monto="${m.monto}" checked></td>
                <td>${fecha(m.fecha)}</td>
                <td><span class="hc-chip">${CHIP[m.tipo]?.[0] || m.tipo}</span>${m.arrastrado ? ' <span class="hc-chip" title="Quedó sin verse en el banco en el cierre anterior">del cierre anterior</span>' : ''}</td>
                <td>${esc(m.descripcion)}</td>
                <td class="text-end fw-bold" style="color:${m.monto >= 0 ? '#117a43' : '#b42318'}">${m.monto >= 0 ? '+' : '−'}${Q(Math.abs(m.monto))}</td>
            </tr>`).join('')
        : `<tr><td colspan="5" class="text-center text-muted py-2">${conc.aviso ? '—' : 'No hay movimientos en este período.'}</td></tr>`;

    const estadoCierre = (h) => h.reabierto_en
        ? `<span class="hc-chip" title="${esc(h.reabierto_motivo || '')}">Reabierto</span>`
        : Math.abs(num(h.sin_explicar)) < 0.005
            ? '<span class="hc-chip" style="background:#dcf5e7;color:#0d6b3a">✔ Conciliado</span>'
            : `<span class="hc-chip" style="background:#fde4e1;color:#b42318">Sin explicar ${Q(h.sin_explicar)}</span>`;
    $('cHistorial').innerHTML = conc.historial.length
        ? `<div class="table-responsive"><table class="table table-sm align-middle mb-0 hc-tabla-nw">
            <thead><tr><th>Período</th><th class="text-end">Banco</th><th class="text-end">Caja app</th><th class="text-end">Aún no pasa</th><th>Estado</th><th>Nota</th><th>Captura</th></tr></thead>
            <tbody>${conc.historial.map((h) => `
                <tr class="${h.reabierto_en ? 'text-muted' : ''}"><td>${fecha(h.desde)} → ${fecha(h.hasta)}</td>
                <td class="text-end">${Q(h.saldo_banco)}</td><td class="text-end">${Q(h.saldo_sistema)}</td>
                <td class="text-end">${Q(h.no_en_banco)}</td>
                <td>${estadoCierre(h)}</td>
                <td>${esc(h.reabierto_en ? `Reabierto: ${h.reabierto_motivo || ''}` : h.nota || '')}</td>
                <td><a href="${BASE}/API/cierres/evidencia?id=${h.id}" target="_blank" rel="noopener"><i class="bi bi-image"></i> Ver</a></td></tr>`).join('')}</tbody></table></div>`
        : '<div class="hc-ayuda">Aún no has cerrado ningún período. Hazlo al menos una vez al mes.</div>';

    recalcularConciliacion();
}

function recalcularConciliacion() {
    if (!conc) return;
    if (conc.saldo_sistema === null) {
        $('cResultado').innerHTML = '';
        $('cNotaWrap').classList.add('d-none');
        return;
    }
    const caja = num(conc.saldo_sistema);
    const saldoTxt = $('cSaldo').value;
    const noEnBanco = [...document.querySelectorAll('.c-visto')].filter((c) => !c.checked).reduce((t, c) => t + num(c.dataset.monto), 0);

    if (saldoTxt === '') {
        $('cResultado').innerHTML = `<div class="hc-ayuda">Escribe el saldo que ves en tu banco al ${fecha(conc.hasta)} para compararlo con la caja de la app a esa fecha (${Q(caja)}).</div>`;
        $('cNotaWrap').classList.add('d-none');
        return;
    }

    const banco = num(saldoTxt);
    const esperado = Math.round((caja - noEnBanco) * 100) / 100;
    const sin = Math.round((banco - esperado) * 100) / 100;
    const cuadra = Math.abs(sin) < 0.005;

    const mensaje = cuadra
        ? `<b>Cuadra.</b> ${Math.abs(noEnBanco) > 0.005 ? `La diferencia de ${Q(banco - caja)} se explica con los movimientos que marcaste como “aún no está en mi banco”.` : 'La caja de la app y tu banco son iguales.'}`
        : sin < 0
            ? `<b>En el banco hay ${Q(-sin)} MENOS de lo esperado.</b> Salió dinero de tu cuenta que la app no sabe (por ejemplo, un envío que pagaste con la tarjeta). Búscalo en tu estado de cuenta y regístralo con su fecha ANTES de cerrar.`
            : `<b>En el banco hay ${Q(sin)} MÁS de lo esperado.</b> Entró dinero que la app no sabe (un cobro sin registrar, o dinero tuyo). Regístralo con su fecha ANTES de cerrar. También revisa si algún movimiento de la lista aún no sale del banco y desmárcalo.`;

    $('cResultado').innerHTML = `
        <div class="caja-grid">
            <div class="caja-box azul"><span>Caja en la app al ${fecha(conc.hasta)}</span><b>${Q(caja)}</b></div>
            <div class="caja-box azul"><span>Saldo en tu banco</span><b>${Q(banco)}</b></div>
            <div class="caja-box ${Math.abs(banco - caja) < 0.005 ? 'verde' : 'ambar'}"><span>Diferencia banco − caja</span><b>${Q(banco - caja)}</b></div>
        </div>
        <div class="caja-grid mt-2">
            <div class="caja-box ambar"><span>Registrado, aún no pasa por el banco</span><b>${Q(noEnBanco)}</b>
                <small>Suma de lo que desmarcaste</small></div>
            <div class="caja-box ${cuadra ? 'verde' : 'rojo'}"><span>Sin explicar</span><b>${Q(sin)}</b>
                <small>${cuadra ? 'Todo explicado' : 'Dinero que se movió en el banco sin registrarse'}</small></div>
        </div>
        <div class="alert ${cuadra ? 'alert-success' : 'alert-danger'} mt-2 mb-0">${mensaje}</div>`;
    $('cNotaWrap').classList.toggle('d-none', cuadra);
}

async function guardarConciliacion() {
    if (!conc || conc.aviso) return error({ mensaje: conc?.aviso || 'Elige la fecha del cierre' });
    if ($('cSaldo').value === '') return error({ mensaje: 'Escribe el saldo que muestra tu banco' });
    const archivo = $('cArchivo').files[0];
    if (!archivo) return error({ mensaje: 'Sube la captura (o el PDF) de tu banco donde se ve ese saldo' });

    const ok = await confirmar(`Vas a cerrar hasta el ${fecha(conc.hasta)}. Después NO podrás registrar ni cambiar nada con fecha hasta ese día (solo reabriendo el cierre). ¿Cerrar?`, 'Sí, cerrar');
    if (!ok) return;

    const noVistos = [...document.querySelectorAll('.c-visto')].filter((c) => !c.checked).map((c) => c.dataset.ref);
    const r = await post('cierres/cerrar', {
        hasta: conc.hasta,
        saldo_banco: $('cSaldo').value,
        no_vistos: JSON.stringify(noVistos),
        nota: $('cNota').value.trim(),
        comprobante: archivo,
    });
    if (r.codigo !== 1) return error(r);
    toast.fire({ icon: r.sin_explicar === 0 ? 'success' : 'warning', title: r.mensaje });
    $('cNota').value = '';
    $('cSaldo').value = '';
    $('cArchivo').value = '';
    $('cHasta').value = '';
    cargarConciliacion();
    cargar();
}

async function reabrirCierre(id) {
    const { value: motivo, isConfirmed } = await Swal.fire({
        title: 'Reabrir el último cierre',
        text: 'Podrás corregir lo que tenga fecha de ese período. Después tendrás que cerrarlo de nuevo.',
        input: 'text',
        inputPlaceholder: 'Por qué lo reabres (mínimo 5 letras)',
        showCancelButton: true,
        confirmButtonText: 'Reabrir',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#b42318',
    });
    if (!isConfirmed) return;
    const r = await post('cierres/reabrir', { id, motivo: (motivo || '').trim() });
    if (r.codigo !== 1) return error(r);
    toast.fire({ icon: 'success', title: r.mensaje });
    $('cHasta').value = '';
    cargarConciliacion();
    cargar();
}

$('btnConciliar').addEventListener('click', () => {
    const panel = $('conciliar');
    panel.classList.toggle('d-none');
    if (!panel.classList.contains('d-none')) cargarConciliacion();
});
$('cHasta').addEventListener('change', cargarConciliacion);
$('cSaldo').addEventListener('input', recalcularConciliacion);
$('tablaConc').addEventListener('change', recalcularConciliacion);
$('cTodos').addEventListener('click', () => { document.querySelectorAll('.c-visto').forEach((c) => (c.checked = true)); recalcularConciliacion(); });
$('btnGuardarConc').addEventListener('click', guardarConciliacion);
$('cEstado').addEventListener('click', (e) => {
    const b = e.target.closest('#btnReabrir');
    if (b) reabrirCierre(b.dataset.id);
});

// ── Pagos pendientes de las piezas: pintora, envío y otros insumos ───────────
const ETAPAS = { solicitado: 'Solicitado', imprimiendo: 'Imprimiendo', impreso: 'Impreso', pintando: 'En pintura', listo: 'Listo', entregado: 'Entregado' };
const CONCEPTOS = { pintura: 'Pintora', envio: 'Envío', otros: 'Otros insumos' };

async function cargarPintora() {
    const r = await get('gastos/pintora');
    if (r.codigo !== 1) return;

    const d = r.datos;
    pendientesPintora = d.pendientes;
    const pc = d.por_concepto;

    const PALETA = ['#6f42c1', '#0d6efd', '#d63384', '#198754', '#fd7e14', '#0aa2c0', '#b02a37', '#6c757d'];
    const TEXTO = {
        pintura: (f) => `Pago a la pintora${f.pintor ? ` (${esc(f.pintor)})` : ''}`,
        envio: () => 'Pago del envío',
        otros: () => 'Pago de otros insumos',
    };

    // Una tarjeta por pieza, con su color, y adentro lo que falta pagar de esa pieza
    const piezas = new Map();
    d.pendientes.forEach((f, i) => {
        if (!piezas.has(f.venta_id)) piezas.set(f.venta_id, { f, falta: 0, lineas: [] });
        const g2 = piezas.get(f.venta_id);
        g2.falta += f.pendiente;
        g2.lineas.push({ f, i });
    });

    const tabla = d.pendientes.length
        ? `<div class="mt-3">${[...piezas.values()].map(({ f: p0, falta, lineas }) => `
            <div class="pieza-card" style="--pc:${PALETA[p0.venta_id % PALETA.length]}">
                <div class="pieza-head">
                    <div><small>PIEZA</small><b>${esc(p0.descripcion)}</b></div>
                    <div class="text-end"><span class="hc-chip">${ETAPAS[p0.etapa] || 'Sin pedido'}</span>
                        <div class="hc-ayuda">Falta pagar de esta pieza: <b style="color:#b26a00">${Q(falta)}</b></div></div>
                </div>
                ${lineas.map(({ f, i }) => `
                <div class="pieza-linea">
                    <span class="cpt cpt-${f.concepto}">${CONCEPTOS[f.concepto]}</span>
                    <div class="flex-grow-1">
                        <div class="fw-semibold">Pendiente: ${TEXTO[f.concepto](f)} · pieza ${esc(f.descripcion)}</div>
                        <small class="hc-ayuda">Acordado ${Q(f.acordado)}${f.tramos ? ` (${Q(f.tramos.pintora)} a la pintora + ${Q(f.tramos.cliente)} al cliente · registra un pago por tramo)` : ''} · pagado ${Q(f.pagado)}</small>
                    </div>
                    <b class="pieza-falta">${Q(f.pendiente)}</b>
                    <span class="text-nowrap">
                        <button class="btn btn-sm btn-hc" data-pagar="${i}"><i class="bi bi-cash-coin"></i> Registrar pago</button>
                        <button class="btn btn-sm btn-outline-secondary" data-saldar="${i}" title="Ya no hay que pagar más de esto (costó menos o no hizo falta)">Saldar</button>
                    </span>
                </div>`).join('')}
            </div>`).join('')}</div>`
        : '<div class="hc-ayuda mt-2">No te falta pagar nada de las piezas vendidas.</div>';

    $('pintora').innerHTML = `
        <div class="caja-grid">
            <div class="caja-box ${d.total_pendiente > 0 ? 'ambar' : 'verde'}"><span>Te falta pagar</span><b>${Q(d.total_pendiente)}</b>
                <small>Pintora ${Q(pc.pintura)} · Envíos ${Q(pc.envio)} · Otros ${Q(pc.otros)}</small></div>
            <div class="caja-box azul"><span>Ya pagaste</span><b>${Q(d.total_pagado)}</b>
                <small>Pintora, envíos y otros de las piezas, cada uno con su comprobante</small></div>
        </div>
        <div class="hc-ayuda mt-2">Cada tarjeta es una pieza y cada línea un costo de esa pieza (pintora, envío, otros) que pusiste en la cotización y todavía no pagas. Con “Registrar pago” anotas la transferencia; si con eso ya no falta nada de ese concepto, queda saldado. “Saldar” sirve cuando costó menos o no hizo falta.</div>
        ${tabla}`;
}

async function pagarPendiente(f) {
    const nombre = CONCEPTOS[f.concepto];
    const { value } = await Swal.fire({
        titleText: `${nombre}: ${f.descripcion}`,
        html: `
            <div class="text-start">
                <div class="hc-ayuda mb-2">Acordado ${Q(f.acordado)} · pagado ${Q(f.pagado)} · falta <b>${Q(f.pendiente)}</b></div>
                <label class="form-label mb-1">Monto que pagaste (Q)</label>
                <input id="tMonto" type="number" min="0" step="any" class="form-control mb-2" value="${f.pendiente}">
                <label class="form-label mb-1">Fecha</label>
                <input id="tFecha" type="date" class="form-control mb-2" value="${hoy()}">
                <label class="form-label mb-1">Pagado con</label>
                ${radioOrigen('caja', 'tOrigen')}
                <label class="form-label mb-1">Captura de la transferencia (obligatoria desde Q${COMPROBANTE_DESDE})</label>
                <input id="tArchivo" type="file" accept="image/*,application/pdf" class="form-control mb-2">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="tSaldar" checked>
                    <label class="form-check-label" for="tSaldar">Con este pago queda saldado: ya no falta nada de ${nombre.toLowerCase()}</label>
                </div>
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
            if (monto >= COMPROBANTE_DESDE && !archivo) {
                Swal.showValidationMessage(`Los pagos de Q${COMPROBANTE_DESDE} o más llevan comprobante`);
                return false;
            }
            return { monto, fecha: p.querySelector('#tFecha').value, comprobante: archivo, saldar: p.querySelector('#tSaldar').checked, origen: p.querySelector('input[name="tOrigen"]:checked').value };
        },
    });
    if (!value) return;

    const categoria = categorias.find((c) => c.concepto === f.concepto);
    if (!categoria) {
        return Swal.fire({ icon: 'info', title: `Falta la categoría de gasto para “${nombre}”`, text: 'Corre el SQL de costos por concepto.', confirmButtonColor: ORO });
    }

    const r = await postGasto(post, 'gastos/crear', {
        categoria_id: categoria.id,
        venta_id: f.venta_id,
        pintor_id: f.concepto === 'pintura' ? f.pintor_id || '' : '',
        descripcion: `${nombre}: ${f.descripcion}`,
        monto: value.monto,
        fecha: value.fecha,
        comprobante: value.comprobante,
        cerrar: value.saldar ? 1 : 0,
        origen: value.origen,
    });
    if (r.cancelado) return;
    if (r.codigo !== 1) return error(r);
    toast.fire({ icon: 'success', title: `${nombre}: pago registrado` });
    cargar();
}

async function saldarConcepto(f) {
    const nombre = CONCEPTOS[f.concepto].toLowerCase();
    const { isConfirmed } = await Swal.fire({
        icon: 'question',
        titleText: `¿Ya no hay nada más que pagar de ${nombre}?`,
        html: `Para <b>${esc(f.descripcion)}</b> el costo final quedará en lo que ya pagaste: <b>${Q(f.pagado)}</b> (acordado ${Q(f.acordado)}). Úsalo si costó menos o no hizo falta.`,
        showCancelButton: true,
        focusCancel: true,
        confirmButtonText: 'Sí, saldar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: ORO,
    });
    if (!isConfirmed) return;

    const r = await post('gastos/cerrar', { venta_id: f.venta_id, concepto: f.concepto });
    if (r.codigo !== 1) return error(r);
    toast.fire({ icon: 'success', title: 'Costo saldado' });
    cargar();
}

$('pintora').addEventListener('click', (e) => {
    const pagar = e.target.closest('[data-pagar]');
    const saldar = e.target.closest('[data-saldar]');
    if (pagar) pagarPendiente(pendientesPintora[Number(pagar.dataset.pagar)]);
    if (saldar) saldarConcepto(pendientesPintora[Number(saldar.dataset.saldar)]);
});

// ── Inventario de filamento: kg y dinero ────────────────────────────────────
const ESTADO_INV = {
    ok: ['Suficiente', 'est-ok'],
    bajo: ['Poco filamento', 'est-bajo'],
    sin: ['Sin filamento', 'est-sin'],
};
const ORIGEN_COMPRA = { caja: 'Caja del negocio', bolsillo: 'De mi bolsillo', inicial: 'Inventario inicial' };

async function cargarInventario() {
    const r = await get('inventario/resumen');
    if (r.codigo !== 1) {
        $('inventario').innerHTML = `<div class="hc-ayuda">No se pudo cargar el inventario: ${esc(r.mensaje)}. ¿Corriste el SQL de inventario?</div>`;
        return;
    }
    const d = r.datos;
    inventario = d;

    const filasMat = d.materiales.length
        ? d.materiales.map((m) => {
            const [txt, cls] = ESTADO_INV[m.estado];
            return `<tr>
                <td><b>${esc(m.nombre)}</b>${num(m.activo) ? '' : ' <span class="hc-ayuda">(inactivo)</span>'}</td>
                <td class="text-end">${kgf(m.comprado_kg)}</td>
                <td class="text-end">${kgf(m.usado_kg)}${num(m.fallas_kg) > 0 ? `<div class="hc-ayuda neg">+ ${kgf(m.fallas_kg)} en fallas</div>` : ''}</td>
                <td class="text-end ${num(m.ajustes_kg) < 0 ? 'txt-sin' : ''}">${num(m.ajustes_kg) ? (num(m.ajustes_kg) > 0 ? '+' : '') + kgf(m.ajustes_kg) : '—'}</td>
                <td class="text-end fw-bold ${m.estado === 'ok' ? 'txt-ok' : m.estado === 'bajo' ? 'txt-bajo' : 'txt-sin'}">${kgf(m.disponible_kg)}</td>
                <td class="text-end">${Q(m.costo_prom_kg)}</td>
                <td class="text-end fw-bold">${Q(m.valor)}</td>
                <td><span class="est ${cls}">${txt}</span></td>
            </tr>`;
        }).join('')
        : '<tr><td colspan="8" class="text-center text-muted py-2">Aún no hay materiales. Créalos en Catálogos > Materiales.</td></tr>';

    const filasCompras = d.compras.length
        ? d.compras.map((c) => `
            <tr>
                <td>${fecha(c.fecha)}</td>
                <td>${esc(c.material)}${detalleCarretes(c.id)}</td>
                <td class="text-end">${kgf(c.kg)}</td>
                <td class="text-end">${Q(num(c.costo_total) / num(c.kg))}</td>
                <td class="text-end fw-bold">${Q(c.costo_total)}</td>
                <td><span class="hc-chip">${ORIGEN_COMPRA[c.origen]}</span>${num(c.ediciones) > 0 ? ` <button class="btn btn-sm btn-link p-0" data-hist-compra="${c.id}" title="Ver cómo estaba antes"><span class="hc-chip">Editado ${num(c.ediciones)}×</span></button>` : ''}</td>
                <td class="text-end text-nowrap">
                    ${c.comprobante ? `<a class="btn btn-sm btn-outline-secondary" href="${BASE}/API/gastos/comprobante?id=${c.gasto_id}" target="_blank" rel="noopener" title="Ver comprobante"><i class="bi bi-paperclip"></i></a>` : ''}
                    <button class="btn btn-sm btn-outline-dark" data-editar-compra="${c.id}" title="Editar"><i class="bi bi-pencil"></i></button>
                </td>
            </tr>`).join('')
        : '<tr><td colspan="7" class="text-center text-muted py-2">Aún no registras compras ni inventario inicial.</td></tr>';

    $('inventario').innerHTML = `
        <div class="caja-grid">
            ${kpi('#0b7285', 'box-seam-fill', 'Filamento disponible', kgf(d.total_kg), 'Lo que tienes para imprimir, sumando todos los materiales')}
            ${kpi('#d9480f', 'gem', 'Valor del inventario', Q(d.total_valor), 'Los kg disponibles al costo promedio de lo que has comprado')}
            ${d.carretes_ok
                ? kpi(d.alertas > 0 ? '#b42318' : '#5f3dc4', d.alertas > 0 ? 'exclamation-triangle-fill' : 'check2-circle', 'Colores por reponer', d.alertas, d.alertas > 0 ? `Con menos de ${gr(d.alerta_g)} o agotados y pedidos esperando` : `Todos los colores con más de ${gr(d.alerta_g)}`)
                : kpi(d.alertas > 0 ? '#b42318' : '#5f3dc4', d.alertas > 0 ? 'exclamation-triangle-fill' : 'check2-circle', 'Materiales por reponer', d.alertas, d.alertas > 0 ? `Con menos de ${num(d.minimo_kg)} kg o sin filamento` : `Todos con más de ${num(d.minimo_kg)} kg`)}
        </div>
        <div class="table-responsive mt-3"><table class="table table-sm align-middle mb-0 hc-tabla-nw">
            <thead><tr><th>Material</th><th class="text-end">Comprado</th><th class="text-end">Usado en piezas</th><th class="text-end">Ajustes de peso</th><th class="text-end">Disponible</th><th class="text-end">Costo prom./kg</th><th class="text-end">Valor</th><th>Estado</th></tr></thead>
            <tbody>${filasMat}</tbody></table></div>
        <div class="hc-ayuda mt-2">Los kg usados salen solos: cuando un pedido pasa a <b>Imprimiendo</b> se descuentan los gramos de sus partes (con la merma). Si no alcanza el filamento, el pedido no puede empezar a imprimir.</div>
        ${bloqueAvisos(d)}
        ${seccionCarretes(d)}
        <h3 class="h6 mt-3 mb-2">Compras y entradas de filamento</h3>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0 hc-tabla-nw" id="tablaCompras">
            <thead><tr><th>Fecha</th><th>Material</th><th class="text-end">Cantidad</th><th class="text-end">Costo por kg</th><th class="text-end">Total</th><th>Origen del dinero</th><th></th></tr></thead>
            <tbody>${filasCompras}</tbody></table></div>`;
    aplicarFiltroCarretes();
}


// ── Carretes ────────────────────────────────────────────────────────────────
const COLORES_BASE = ['Negro', 'Blanco', 'Gris', 'Rojo', 'Azul', 'Celeste', 'Verde', 'Verde militar', 'Amarillo', 'Naranja', 'Dorado', 'Plateado', 'Rosado', 'Morado', 'Café', 'Beige', 'Transparente'];
// Para pintar el puntito del color (lo más específico primero)
const PUNTOS = [['verde azulado', '#2a9d8f'], ['verde militar', '#4b5320'], ['rojo lava', '#c0392b'], ['ladrillo', '#a0432e'], ['marrón', '#6f4e37'], ['marron', '#6f4e37'], ['pink', '#f48fb1'], ['yellow', '#f4c20d'], ['celeste', '#5bc0eb'], ['dorado', '#d4af37'], ['plateado', '#b8bcc2'], ['negro', '#1f1f1f'], ['blanco', '#f4f4f4'],
    ['gris', '#8a8f98'], ['rojo', '#d62828'], ['azul', '#1d4ed8'], ['verde', '#2e9e4f'], ['amarillo', '#f4c20d'], ['naranja', '#f07b1c'], ['rosa', '#f48fb1'],
    ['morado', '#7048e8'], ['café', '#7b4a2d'], ['cafe', '#7b4a2d'], ['beige', '#e1cfae'], ['transparente', '#dfe7ee']];
const puntoColor = (color, px = 14) => {
    const c = String(color || '').toLowerCase();
    const hit = PUNTOS.find(([k]) => c.includes(k));
    return `<span style="display:inline-block;width:${px}px;height:${px}px;border-radius:50%;vertical-align:${px > 16 ? -6 : -2}px;margin-right:8px;border:1px solid #0004;background:${hit ? hit[1] : '#cfd4da'}"></span>`;
};
// Para buscar sin importar mayúsculas ni acentos
const plano = (t) => String(t ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
let filtroCarretes = '';
const gr = (n) => num(n).toLocaleString('es-GT', { maximumFractionDigits: 0 }) + ' g';
const colorBarra = (pct) => (pct > 50 ? '#117a43' : pct >= 15 ? '#c98a00' : '#b42318');

const detalleCarretes = (compraId) => {
    const ks = (inventario.carretes || []).filter((k) => String(k.compra_id) === String(compraId));
    if (!ks.length) return '';
    const cuenta = {};
    ks.forEach((k) => (cuenta[k.color] = (cuenta[k.color] || 0) + 1));
    return `<div class="hc-ayuda">${Object.entries(cuenta).map(([c, n]) => `${n}× ${esc(c)}`).join(' · ')}</div>`;
};

function seccionCarretes(d) {
    if (!d.carretes_ok) {
        return '<div class="hc-ayuda mt-3"><b>Carretes:</b> falta ejecutar <code>sql/carretes.sql</code> para registrar cada carrete con su color.</div>';
    }
    const ks = d.carretes;
    if (!ks.length) {
        return '<h3 class="h6 mt-3 mb-2"><i class="bi bi-disc me-1"></i>Carretes</h3><div class="hc-ayuda">Aún no registras carretes. Usa <b>“Ya tenía filamento”</b> para los que tienes hoy, o <b>“Comprar filamento”</b> para los nuevos.</div>';
    }
    const vivos = ks.filter((k) => !k.agotado);
    const gramos = vivos.reduce((s, k) => s + num(k.gramos_actuales), 0);
    const valor = vivos.reduce((s, k) => s + num(k.valor), 0);
    return `<h3 class="h6 mt-3 mb-2"><i class="bi bi-disc me-1"></i>Carretes <span class="hc-ayuda fw-normal">${vivos.length} con filamento · ${gr(gramos)} · ${Q(valor)}</span></h3>
        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
            <div class="input-group" style="max-width:360px">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input id="fCarretes" type="search" class="form-control" placeholder="Buscar color o material (ej. blanco)" value="${esc(filtroCarretes)}" autocomplete="off">
            </div>
            <span id="fCarretesN" class="hc-ayuda"></span>
        </div>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0 hc-tabla-nw">
            <thead><tr><th>Color</th><th>Material</th><th>Le queda</th><th class="text-end">Costo nuevo</th><th class="text-end">Valor ahora</th><th>Entrada</th><th></th></tr></thead>
            <tbody id="tbodyCarretes">${filasCarretes(ks)}</tbody></table></div>
        <div class="hc-ayuda mt-1">Los gramos bajan solos cuando un pedido pasa a <b>Imprimiendo</b> (con la merma) y cuando registras una impresión fallida en Pedidos. Si lo que marca la báscula es distinto, corrígelo con el medidor.</div>`;
}

// Filas de la tabla de carretes, aplicando el buscador (color o material)
function filasCarretes(ks) {
    const q = plano(filtroCarretes).trim();
    const lista = ks.filter((k) => !q || plano(k.color).includes(q) || plano(k.material).includes(q));
    const orden = [...lista].sort((a, b) => Number(a.agotado) - Number(b.agotado));
    if (!orden.length) return '<tr><td colspan="7" class="hc-ayuda py-3">Ningún carrete coincide con la búsqueda.</td></tr>';
    return orden.map((k) => {
        const pct = Math.max(0, Math.min(100, (num(k.gramos_actuales) / num(k.peso_inicial_g)) * 100));
        return `<tr class="${k.agotado ? 'text-muted' : ''}">
            <td class="text-nowrap">${puntoColor(k.color, 26)}<b>${esc(k.color)}</b></td>
            <td>${esc(k.material)}</td>
            <td style="min-width:170px">
                <div class="d-flex justify-content-between"><b style="color:${k.agotado ? '#6c757d' : colorBarra(pct)}">${k.agotado ? 'Agotado' : gr(k.gramos_actuales)}</b><span class="hc-ayuda">de ${gr(k.peso_inicial_g)}</span></div>
                <div style="height:6px;background:#e9ecef;border-radius:3px"><div style="height:6px;border-radius:3px;width:${pct}%;background:${colorBarra(pct)}"></div></div>
            </td>
            <td class="text-end">${Q(k.costo)}<div class="hc-ayuda">${Q(k.costo_kg)}/kg</div></td>
            <td class="text-end fw-bold">${k.agotado ? '—' : Q(k.valor)}</td>
            <td><span class="hc-chip">${k.origen === 'inicial' ? 'Ya lo tenía' : fecha(k.fecha)}</span></td>
            <td class="text-end text-nowrap">
                <button class="btn btn-sm btn-outline-dark" data-peso-carrete="${k.id}" title="Corregir cuánto le queda"><i class="bi bi-speedometer2"></i></button>
                <button class="btn btn-sm btn-outline-dark" data-color-carrete="${k.id}" title="Cambiar el color"><i class="bi bi-palette"></i></button>
            </td>
        </tr>`;
    }).join('');
}

// Avisos de poco filamento por color, gramos por color y merma presupuestada contra real
function bloqueAvisos(d) {
    if (!d.carretes_ok) return '';
    const limite = num(d.alerta_g);
    const chips = (d.colores || []).length
        ? `<div class="mt-3"><div class="hc-ayuda mb-1">Filamento por color <span class="fw-normal">(aviso debajo de ${gr(limite)})</span></div>
            ${(d.colores || []).map((c) => {
                const poco = num(c.gramos) < limite;
                return `<span class="hc-chip me-1 mb-1" role="button" data-filtro-color="${esc(c.color)}" title="Ver solo este color" style="cursor:pointer;${poco ? 'background:#fde8ee;color:#b0224f' : ''}">${puntoColor(c.color, 20)}${esc(c.color)} <span class="hc-ayuda">${esc(c.material)}</span> <b>${gr(c.gramos)}</b>${c.carretes > 1 ? ` · ${c.carretes} carretes` : ''}</span>`;
            }).join('')}</div>`
        : '';

    const av = d.por_acabarse || [];
    const alertas = av.length
        ? `<div class="mt-3 p-2 rounded" style="background:#fff4f6;border:1px solid #f1b5c4;border-left:5px solid #d6336c">
            <div class="fw-bold mb-1" style="color:#b0224f"><i class="bi bi-exclamation-triangle-fill me-1"></i>Filamento por reponer</div>
            ${av.map((a) => `<div class="small">${a.agotado
                ? `<b>${esc(a.color)}</b> (${esc(a.material)}): <b>se acabó</b>${a.pedidos?.length ? ` y lo necesita ${a.pedidos.map(esc).join(', ')}` : ''}`
                : `<b>${esc(a.color)}</b> (${esc(a.material)}): quedan <b>${gr(a.gramos)}</b>${a.pedidos?.length ? ` · lo espera ${a.pedidos.map(esc).join(', ')}` : ''}`}</div>`).join('')}
           </div>`
        : '';

    const m = d.merma || {};
    let merma = '';
    if (d.paso2_ok && (num(m.presupuestada_g) > 0 || num(m.real_g) > 0)) {
        const pierdesMas = num(m.real_g) > num(m.presupuestada_g);
        merma = `<div class="mt-3 p-2 rounded" style="background:${pierdesMas ? '#fff4f6' : '#eefaf2'};border:1px solid ${pierdesMas ? '#f1b5c4' : '#b9e3c8'}">
            <div class="fw-bold mb-1"><i class="bi bi-percent me-1"></i>Merma: lo que cobras contra lo que pierdes</div>
            <div class="small">Presupuestada en tus cotizaciones: <b>${gr(m.presupuestada_g)}</b> · Perdida en fallas: <b class="${pierdesMas ? 'neg' : ''}">${gr(m.real_g)}</b> (${Q(m.costo_fallas)}) · Este mes: ${gr(m.mes_presupuestada_g)} contra ${gr(m.mes_real_g)}</div>
            <div class="hc-ayuda">${pierdesMas
                ? 'Estás perdiendo más filamento del que cobras: la diferencia sale de tu ganancia. Si pasa seguido, sube el porcentaje de merma en Costos y margen.'
                : 'La merma de tus cotizaciones alcanza para cubrir tus fallas.'}</div>
        </div>`;
    }
    return chips + alertas + merma;
}

const datalistColores = () => {
    const usados = (inventario.carretes || []).map((k) => k.color);
    const todos = [...new Set([...usados, ...COLORES_BASE])];
    return `<datalist id="dlColores">${todos.map((c) => `<option value="${esc(c)}"></option>`).join('')}</datalist>`;
};

// Registrar carretes nuevos: comprados (con comprobante) o que ya tenías (con lo que les queda).
async function formCarretes(inicial) {
    if (!inventario.carretes_ok) {
        return Swal.fire({ icon: 'info', title: 'Falta una tabla', text: 'Ejecuta sql/carretes.sql en tu base de datos y recarga la página.', confirmButtonColor: ORO });
    }
    const mats = inventario.materiales.filter((m) => num(m.activo) === 1);
    if (!mats.length) {
        return Swal.fire({ icon: 'info', title: 'Primero crea un material', text: 'Ve a Catálogos > Materiales.', confirmButtonColor: ORO });
    }

    const lineaCompra = () => `
        <div class="ln row g-2 align-items-end mb-2">
            <div class="col-5"><label class="form-label mb-1">Color</label><input class="form-control ln-color" list="dlColores" maxlength="40" placeholder="Ej. Rojo"></div>
            <div class="col-3"><label class="form-label mb-1">Cantidad</label><input type="number" min="1" max="50" step="1" class="form-control ln-cant" value="1"></div>
            <div class="col-3"><label class="form-label mb-1">Peso c/u (g)</label><input type="number" min="100" step="any" class="form-control ln-peso" value="1000"></div>
            <div class="col-1 text-end"><button type="button" class="btn btn-sm btn-outline-danger ln-del" title="Quitar"><i class="bi bi-x-lg"></i></button></div>
        </div>`;
    const lineaInicial = () => `
        <div class="ln border rounded p-2 mb-2">
            <div class="row g-2 mb-1">
                <div class="col-5"><label class="form-label mb-1">Material</label><select class="form-select ln-mat">${opciones(mats, 'Elige…')}</select></div>
                <div class="col-4"><label class="form-label mb-1">Color</label><input class="form-control ln-color" list="dlColores" maxlength="40" placeholder="Ej. Negro"></div>
                <div class="col-2"><label class="form-label mb-1">Cantidad</label><input type="number" min="1" max="50" step="1" class="form-control ln-cant" value="1"></div>
                <div class="col-1 text-end pt-4"><button type="button" class="btn btn-sm btn-outline-danger ln-del" title="Quitar"><i class="bi bi-x-lg"></i></button></div>
            </div>
            <div class="row g-2 align-items-end">
                <div class="col-3"><label class="form-label mb-1">Precio de uno nuevo (Q)</label><input type="number" min="0" step="any" class="form-control ln-precio"></div>
                <div class="col-3"><label class="form-label mb-1">Peso original (g)</label><input type="number" min="100" step="any" class="form-control ln-peso" value="1000"></div>
                <div class="col-3"><label class="form-label mb-1">En báscula (g)</label><input type="number" min="0" step="any" class="form-control ln-bascula" placeholder="opcional"></div>
                <div class="col-3"><label class="form-label mb-1">Le queda (g)</label><input type="number" min="0" step="any" class="form-control ln-queda" value="1000"></div>
            </div>
        </div>`;

    const { value } = await Swal.fire({
        titleText: inicial ? 'Filamento que ya tenía' : 'Comprar filamento',
        width: inicial ? 700 : 560,
        html: `
            <div class="text-start">
                ${datalistColores()}
                ${inicial ? `<div class="hc-ayuda mb-2">Carretes que <b>ya tenías</b> antes de usar la app. No salen de la caja. Un carrete nuevo ya lleva 1,000 g; si está usado, <b>pésalo con la báscula</b> y la app le resta el carrete vacío.</div>
                    <div class="row g-2 mb-2 align-items-end">
                        <div class="col-4"><label class="form-label mb-1">Carrete vacío pesa (g)</label><input id="cVacio" type="number" class="form-control bg-light" value="${CARRETE_VACIO_G}" readonly tabindex="-1" title="Fijo: peso de un carrete vacío"></div>
                        <div class="col-8 hc-ayuda">Se usa para calcular lo que le queda: báscula − carrete vacío.</div>
                    </div>` : `
                    <label class="form-label mb-1">Material</label>
                    <select id="cMaterial" class="form-select mb-2">${opciones(mats, 'Elige el material…')}</select>`}
                <div id="lineas">${inicial ? lineaInicial() : lineaCompra()}</div>
                <button type="button" id="btnOtra" class="btn btn-sm btn-outline-dark mb-2"><i class="bi bi-plus-lg me-1"></i>${inicial ? 'Agregar otro carrete' : 'Agregar otro color'}</button>
                <div id="cResumen" class="hc-ayuda mb-2"></div>
                ${inicial ? '' : `
                    <label class="form-label mb-1">Lo que pagaste en total (Q)</label>
                    <input id="cCosto" type="number" min="0" step="any" class="form-control mb-2">`}
                <label class="form-label mb-1">Fecha</label>
                <input id="cFecha" type="date" class="form-control mb-2" max="${hoy()}" value="${hoy()}">
                ${inicial ? '' : `
                    <label class="form-label mb-1">Pagado con</label>
                    ${radioOrigen('caja', 'cOrigen')}
                    <div id="cAvisoCaja" class="hc-ayuda mb-2"></div>
                    <label class="form-label mb-1">Proveedor o nota (opcional)</label><input id="cNota" class="form-control mb-2" placeholder="Ej. Tienda 3D Cobán">
                    <label class="form-label mb-1">Comprobante (obligatorio)</label>
                    <input id="cArchivo" type="file" accept="image/*,application/pdf" class="form-control mb-2">
                    <div class="form-check"><input class="form-check-input" type="checkbox" id="cActualizar">
                        <label class="form-check-label" for="cActualizar">Actualizar el costo por kg del material con esta compra (afecta las cotizaciones nuevas)</label></div>`}
            </div>`,
        didOpen: (pop) => {
            const cont = pop.querySelector('#lineas');
            const vacio = () => num(pop.querySelector('#cVacio')?.value);
            const refrescar = () => {
                let carretes = 0;
                let g = 0;
                let valor = 0;
                cont.querySelectorAll('.ln').forEach((ln) => {
                    const cant = Math.max(parseInt(ln.querySelector('.ln-cant').value, 10) || 0, 0);
                    const peso = num(ln.querySelector('.ln-peso').value);
                    carretes += cant;
                    if (inicial) {
                        const queda = num(ln.querySelector('.ln-queda').value);
                        g += cant * queda;
                        valor += peso > 0 ? cant * num(ln.querySelector('.ln-precio').value) * (queda / peso) : 0;
                    } else {
                        g += cant * peso;
                    }
                });
                const costo = num(pop.querySelector('#cCosto')?.value);
                pop.querySelector('#cResumen').innerHTML = carretes
                    ? `<b>${carretes}</b> carrete${carretes === 1 ? '' : 's'} · <b>${(g / 1000).toLocaleString('es-GT', { minimumFractionDigits: 3 })} kg</b>${inicial ? ` · valen <b>${Q(valor)}</b> con lo que les queda` : costo > 0 && g > 0 ? ` · sale a <b>${Q((costo / g) * 1000)} por kg</b>` : ''}`
                    : '';
                const aviso = pop.querySelector('#cAvisoCaja');
                if (aviso) {
                    const origen = pop.querySelector('input[name="cOrigen"]:checked').value;
                    const libre = cajaActual ? num(cajaActual.disponible) : null;
                    aviso.innerHTML = origen === 'caja' && libre !== null
                        ? `Disponible para gastar: <b style="color:${costo > libre + 0.005 ? '#b42318' : '#117a43'}">${Q(libre)}</b>${costo > libre + 0.005 ? ' · no alcanza: elige “De mi bolsillo”' : ''}`
                        : 'Se registra como dinero que pones en el negocio y luego se gasta: la caja queda igual.';
                }
            };
            pop.addEventListener('input', (e) => {
                // La báscula calcula lo que le queda: báscula − carrete vacío
                if (inicial && e.target.classList.contains('ln-bascula')) {
                    const ln = e.target.closest('.ln');
                    const b = parseFloat(e.target.value);
                    if (b > 0) ln.querySelector('.ln-queda').value = Math.max(Math.round((b - vacio()) * 10) / 10, 0);
                }
                // Si cambian el peso original y no han tocado "le queda", se mantiene igual al original
                if (inicial && e.target.classList.contains('ln-peso')) {
                    const ln = e.target.closest('.ln');
                    if (!ln.querySelector('.ln-bascula').value) ln.querySelector('.ln-queda').value = e.target.value;
                }
                refrescar();
            });
            pop.addEventListener('change', refrescar);
            pop.addEventListener('click', (e) => {
                if (e.target.closest('#btnOtra')) {
                    cont.insertAdjacentHTML('beforeend', inicial ? lineaInicial() : lineaCompra());
                    refrescar();
                }
                const del = e.target.closest('.ln-del');
                if (del && cont.querySelectorAll('.ln').length > 1) {
                    del.closest('.ln').remove();
                    refrescar();
                }
            });
            refrescar();
        },
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonText: 'Guardar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: ORO,
        preConfirm: () => {
            const p = Swal.getPopup();
            const invalido = (m) => {
                Swal.showValidationMessage(m);
                return false;
            };
            const lineas = [];
            for (const ln of p.querySelectorAll('.ln')) {
                const color = ln.querySelector('.ln-color').value.trim();
                const cantidad = parseInt(ln.querySelector('.ln-cant').value, 10);
                const peso = parseFloat(ln.querySelector('.ln-peso').value);
                if (inicial && !ln.querySelector('.ln-mat').value) return invalido('Elige el material de cada carrete');
                if (!color) return invalido('Escribe el color de cada carrete');
                if (!(cantidad >= 1)) return invalido(`Cantidad de ${color}: mínimo 1`);
                if (!(peso >= 100)) return invalido(`Peso de ${color}: escribe los gramos (normalmente 1000)`);
                if (inicial) {
                    const queda = parseFloat(ln.querySelector('.ln-queda').value);
                    const precio = parseFloat(ln.querySelector('.ln-precio').value);
                    if (!(queda > 0) || queda > peso) return invalido(`Cuánto le queda a ${color}: más de 0 y no más de ${peso} g`);
                    if (!(precio > 0)) return invalido(`Escribe cuánto cuesta uno nuevo de ${color} (aunque sea aproximado)`);
                    lineas.push({ material_id: ln.querySelector('.ln-mat').value, color, cantidad, peso_g: peso, gramos: queda, precio });
                } else {
                    lineas.push({ color, cantidad, peso_g: peso });
                }
            }
            const fechaC = p.querySelector('#cFecha').value;
            if (!inicial && !p.querySelector('#cMaterial').value) return invalido('Elige el material');
            if (!fechaC || fechaC > hoy()) return invalido('La fecha no puede ser futura');
            if (inicial) {
                return { origen: 'inicial', fecha: fechaC, carretes: JSON.stringify(lineas) };
            }
            const costo = parseFloat(p.querySelector('#cCosto').value);
            const archivo = p.querySelector('#cArchivo').files[0] || null;
            if (!(costo > 0)) return invalido('Escribe cuánto pagaste en total');
            if (!archivo) return invalido('La compra de filamento lleva comprobante');
            if (archivo.size > 5 * 1024 * 1024) return invalido('El comprobante pesa más de 5 MB');
            return {
                material_id: p.querySelector('#cMaterial').value,
                costo_total: costo,
                fecha: fechaC,
                origen: p.querySelector('input[name="cOrigen"]:checked').value,
                nota: p.querySelector('#cNota').value.trim(),
                actualizar_costo: p.querySelector('#cActualizar').checked ? 1 : 0,
                carretes: JSON.stringify(lineas),
                comprobante: archivo,
            };
        },
    });
    if (!value) return;

    const r = await post('inventario/comprar', value);
    if (r.codigo !== 1) return error(r);
    toast.fire({ icon: 'success', title: r.mensaje });
    cargar();
}

// Corregir cuánto le queda a un carrete (queda guardado el antes, el después y el motivo)
async function ajustarPeso(k) {
    const { value } = await Swal.fire({
        titleText: `${k.color} · ${k.material}`,
        html: `
            <div class="text-start">
                <div class="hc-ayuda mb-2">Hoy tiene registrado <b>${gr(k.gramos_actuales)}</b> de ${gr(k.peso_inicial_g)}. Pésalo con la báscula y corrige.</div>
                <div class="row g-2 mb-2 align-items-end">
                    <div class="col-4"><label class="form-label mb-1">Báscula (g)</label><input id="aBascula" type="number" min="0" step="any" class="form-control" placeholder="opcional"></div>
                    <div class="col-4"><label class="form-label mb-1">Carrete vacío (g)</label><input id="aVacio" type="number" class="form-control bg-light" value="${CARRETE_VACIO_G}" readonly tabindex="-1" title="Fijo: peso de un carrete vacío"></div>
                    <div class="col-4"><label class="form-label mb-1">Le queda (g)</label><input id="aGramos" type="number" min="0" step="any" class="form-control" value="${num(k.gramos_actuales)}"></div>
                </div>
                <label class="form-label mb-1">Motivo</label>
                <input id="aMotivo" class="form-control" maxlength="250" value="Pesado en báscula">
                <div class="hc-ayuda mt-1">Para dar de baja un carrete (se rompió, se perdió), pon 0 y escribe el motivo.</div>
            </div>`,
        didOpen: (pop) => {
            pop.addEventListener('input', (e) => {
                if (e.target.id === 'aBascula' || e.target.id === 'aVacio') {
                    const b = parseFloat(pop.querySelector('#aBascula').value);
                    if (b > 0) pop.querySelector('#aGramos').value = Math.max(Math.round((b - num(pop.querySelector('#aVacio').value)) * 10) / 10, 0);
                }
            });
        },
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonText: 'Guardar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: ORO,
        preConfirm: () => {
            const p = Swal.getPopup();
            const gramos = parseFloat(p.querySelector('#aGramos').value);
            const motivo = p.querySelector('#aMotivo').value.trim();
            if (!(gramos >= 0)) { Swal.showValidationMessage('Escribe cuánto le queda'); return false; }
            if (gramos > num(k.peso_inicial_g)) { Swal.showValidationMessage(`No puede tener más de ${gr(k.peso_inicial_g)}`); return false; }
            if (motivo.length < 3) { Swal.showValidationMessage('Escribe el motivo'); return false; }
            return { id: k.id, gramos, motivo };
        },
    });
    if (!value) return;
    const r = await post('inventario/carrete-peso', value);
    if (r.codigo !== 1) return error(r);
    toast.fire({ icon: 'success', title: r.mensaje });
    cargar();
}

async function cambiarColor(k) {
    const { value } = await Swal.fire({
        titleText: 'Cambiar el color',
        html: `${datalistColores()}<input id="kColor" class="form-control" list="dlColores" maxlength="40" value="${esc(k.color)}">`,
        showCancelButton: true,
        confirmButtonText: 'Guardar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: ORO,
        preConfirm: () => {
            const c = Swal.getPopup().querySelector('#kColor').value.trim();
            if (!c) { Swal.showValidationMessage('Escribe el color'); return false; }
            return c;
        },
    });
    if (!value) return;
    const r = await post('inventario/carrete-color', { id: k.id, color: value });
    if (r.codigo !== 1) return error(r);
    toast.fire({ icon: 'success', title: r.mensaje });
    cargar();
}

// Compra de filamento (o inventario inicial). Con `c` edita una compra ya registrada.
async function formCompra(c = null, inicial = false) {
    if (!c) return formCarretes(inicial);
    const conCarretes = (inventario.carretes || []).some((k) => String(k.compra_id) === String(c.id));
    const editar = Boolean(c);
    const esInicial = editar ? c.origen === 'inicial' : inicial;
    const mats = inventario.materiales.filter((m) => num(m.activo) === 1 || (editar && String(m.id) === String(c.material_id)));
    if (!mats.length) {
        return Swal.fire({ icon: 'info', title: 'Primero crea un material', text: 'Ve a Catálogos > Materiales.', confirmButtonColor: ORO });
    }
    const tieneComprobante = Boolean(c?.comprobante);

    const { value } = await Swal.fire({
        titleText: editar ? 'Editar compra de filamento' : esInicial ? 'Inventario inicial' : 'Comprar filamento',
        width: 520,
        html: `
            <div class="text-start">
                ${esInicial ? '<div class="hc-ayuda mb-2">Filamento que <b>ya tenías</b> antes de usar la app. No sale de la caja: solo suma kg y valor al inventario.</div>' : ''}
                <label class="form-label mb-1">Material</label>
                <select id="cMaterial" class="form-select mb-2" ${conCarretes ? 'disabled' : ''}>${opciones(mats, null, c?.material_id ?? null)}</select>
                ${conCarretes ? '<div class="hc-ayuda mb-2">Esta compra tiene carretes registrados: el material y los kg no se cambian aquí. Para corregir cuánto le queda a un carrete usa el botón del medidor en la tabla de carretes.</div>' : ''}
                <div class="row g-2 mb-1">
                    <div class="col-6"><label class="form-label mb-1">Cantidad (kg)</label>
                        <input id="cKg" type="number" min="0" step="0.001" class="form-control" placeholder="Ej. 1" value="${c ? num(c.kg) : ''}" ${conCarretes ? 'readonly' : ''}></div>
                    <div class="col-6"><label class="form-label mb-1">${esInicial ? 'Lo que vale (Q)' : 'Lo que pagaste en total (Q)'}</label>
                        <input id="cCosto" type="number" min="0" step="any" class="form-control" value="${c ? num(c.costo_total) : ''}"></div>
                </div>
                <div id="cPorKg" class="hc-ayuda mb-2"></div>
                <label class="form-label mb-1">Fecha</label>
                <input id="cFecha" type="date" class="form-control mb-2" max="${hoy()}" value="${c ? String(c.fecha).slice(0, 10) : hoy()}">
                ${esInicial ? '' : editar ? `<div class="hc-ayuda mb-2">Dinero: <b>${ORIGEN_COMPRA[c.origen]}</b> (no se cambia aquí)</div>` : `
                <label class="form-label mb-1">Pagado con</label>
                ${radioOrigen('caja', 'cOrigen')}
                <div id="cAvisoCaja" class="hc-ayuda mb-2"></div>`}
                ${editar ? '' : '<label class="form-label mb-1">Proveedor o nota (opcional)</label><input id="cNota" class="form-control mb-2" placeholder="Ej. Tienda 3D Cobán">'}
                ${esInicial ? '' : `<label class="form-label mb-1">Comprobante (obligatorio)${tieneComprobante ? ' <span class="hc-ayuda">sube otro solo si quieres reemplazar el actual; el anterior queda guardado</span>' : ''}</label>
                <input id="cArchivo" type="file" accept="image/*,application/pdf" class="form-control mb-2">`}
                ${editar ? '' : `<div class="form-check"><input class="form-check-input" type="checkbox" id="cActualizar">
                    <label class="form-check-label" for="cActualizar">Actualizar el costo por kg del material con esta compra (afecta las cotizaciones nuevas)</label></div>`}
            </div>`,
        didOpen: (pop) => {
            const mat = pop.querySelector('#cMaterial');
            const kg = pop.querySelector('#cKg');
            const costo = pop.querySelector('#cCosto');
            const refrescar = () => {
                const k = parseFloat(kg.value) || 0;
                const q = parseFloat(costo.value) || 0;
                const m = inventario.materiales.find((x) => String(x.id) === mat.value);
                const porKg = k > 0 ? q / k : 0;
                pop.querySelector('#cPorKg').innerHTML = k > 0 && q > 0
                    ? `Sale a <b>${Q(porKg)} por kg</b>${m ? ` · hoy el material está en ${Q(m.costo_kg)}/kg` : ''}`
                    : '';
                const aviso = pop.querySelector('#cAvisoCaja');
                if (aviso) {
                    const origen = pop.querySelector('input[name="cOrigen"]:checked').value;
                    const libre = cajaActual ? num(cajaActual.disponible) : null;
                    aviso.innerHTML = origen === 'caja' && libre !== null
                        ? `Disponible para gastar: <b style="color:${q > libre + 0.005 ? '#b42318' : '#117a43'}">${Q(libre)}</b>${q > libre + 0.005 ? ' · no alcanza: elige “De mi bolsillo”' : ''}`
                        : 'Se registra como dinero que pones en el negocio y luego se gasta: la caja queda igual.';
                }
            };
            pop.addEventListener('input', refrescar);
            refrescar();
        },
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonText: 'Guardar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: ORO,
        preConfirm: () => {
            const p = Swal.getPopup();
            const invalido = (m) => {
                Swal.showValidationMessage(m);
                return false;
            };
            const kg = parseFloat(p.querySelector('#cKg').value);
            const costo = parseFloat(p.querySelector('#cCosto').value);
            const fechaC = p.querySelector('#cFecha').value;
            const archivo = p.querySelector('#cArchivo')?.files[0] || null;
            if (!(kg > 0)) return invalido('Escribe los kg');
            if (!(costo > 0)) return invalido('Escribe cuánto costó en total');
            if (!fechaC || fechaC > hoy()) return invalido('La fecha no puede ser futura');
            if (archivo && archivo.size > 5 * 1024 * 1024) return invalido('El comprobante pesa más de 5 MB');
            if (!esInicial && !archivo && !tieneComprobante) return invalido('La compra de filamento lleva comprobante');
            return {
                material_id: p.querySelector('#cMaterial').value,
                kg,
                costo_total: costo,
                fecha: fechaC,
                origen: esInicial ? 'inicial' : editar ? c.origen : p.querySelector('input[name="cOrigen"]:checked').value,
                nota: p.querySelector('#cNota')?.value.trim() || '',
                actualizar_costo: p.querySelector('#cActualizar')?.checked ? 1 : 0,
                comprobante: archivo,
            };
        },
    });
    if (!value) return;

    const r = editar ? await post('inventario/actualizar', { id: c.id, ...value }) : await post('inventario/comprar', value);
    if (r.codigo !== 1) return error(r);
    toast.fire({ icon: 'success', title: r.mensaje });
    cargar();
}

$('btnComprar').addEventListener('click', () => formCompra());
$('btnInicial').addEventListener('click', () => formCompra(null, true));
// Buscador de carretes: solo repinta las filas, así el cursor no se pierde
function aplicarFiltroCarretes() {
    const tb = document.getElementById('tbodyCarretes');
    if (!tb) return;
    tb.innerHTML = filasCarretes(inventario.carretes || []);
    const n = document.getElementById('fCarretesN');
    if (n) {
        const q = plano(filtroCarretes).trim();
        const total = (inventario.carretes || []).filter((k) => !q || plano(k.color).includes(q) || plano(k.material).includes(q));
        const vivos = total.filter((k) => !k.agotado);
        n.innerHTML = q ? `${total.length} carrete${total.length === 1 ? '' : 's'} · <b>${gr(vivos.reduce((s, k) => s + num(k.gramos_actuales), 0))}</b> disponibles` : '';
    }
}
$('inventario').addEventListener('input', (e) => {
    if (e.target.id !== 'fCarretes') return;
    filtroCarretes = e.target.value;
    aplicarFiltroCarretes();
});
$('inventario').addEventListener('click', (e) => {
    const chip = e.target.closest('[data-filtro-color]');
    if (chip) {
        filtroCarretes = chip.dataset.filtroColor;
        const inp = document.getElementById('fCarretes');
        if (inp) { inp.value = filtroCarretes; inp.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
        aplicarFiltroCarretes();
        return;
    }
    const ed = e.target.closest('[data-editar-compra]');
    const hist = e.target.closest('[data-hist-compra]');
    const peso = e.target.closest('[data-peso-carrete]');
    const col = e.target.closest('[data-color-carrete]');
    if (peso) ajustarPeso(inventario.carretes.find((k) => String(k.id) === peso.dataset.pesoCarrete));
    if (col) cambiarColor(inventario.carretes.find((k) => String(k.id) === col.dataset.colorCarrete));
    if (ed) formCompra(inventario.compras.find((c) => String(c.id) === ed.dataset.editarCompra));
    if (hist) verHistorial('compra', hist.dataset.histCompra);
});
