import Swal from 'sweetalert2';
import { selectorColor } from '../selectorColor.js';
import { $, ORO, esc, num, Q, toast, crearApi, confirmar, error, opciones, hoy, fecha, cobrarVenta, editarPago, puntoColor } from '../comun.js';

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
    cargarAlertas();
}

// ── Aviso de poco filamento ─────────────────────────────────────────────────
const gr = (n) => num(n).toLocaleString('es-GT', { maximumFractionDigits: 0 }) + ' g';
const textoAlerta = (a) => a.agotado
    ? `<b>${esc(a.color)}</b> (${esc(a.material)}): <b>se acabó</b>${a.pedidos?.length ? ` y lo necesita${a.pedidos.length > 1 ? 'n' : ''} ${a.pedidos.map(esc).join(', ')}` : ''}`
    : `<b>${esc(a.color)}</b> (${esc(a.material)}) está por acabarse: quedan <b>${gr(a.gramos)}</b>${a.carretes > 1 ? ` en ${a.carretes} carretes` : ''}${a.pedidos?.length ? ` · lo espera${a.pedidos.length > 1 ? 'n' : ''} ${a.pedidos.map(esc).join(', ')}` : ''}`;

async function cargarAlertas() {
    const r = await get('inventario/alertas');
    const lista = r.codigo === 1 ? r.datos : [];
    $('alertasFil').innerHTML = lista.length
        ? `<div class="mb-3 p-2 rounded" style="background:#fff4f6;border:1px solid #f1b5c4;border-left:5px solid #d6336c">
               <div class="fw-bold mb-1" style="color:#b0224f"><i class="bi bi-exclamation-triangle-fill me-1"></i>Filamento por reponer</div>
               ${lista.map((a) => `<div class="small">${textoAlerta(a)}</div>`).join('')}
               <a href="${BASE}/gastos" class="small">Ir al inventario para comprar o corregir</a>
           </div>`
        : '';
}

// ── Tablero ─────────────────────────────────────────────────────────────────
// Aviso: la pieza ya va (o ya pasó) por la pintora y quedan costos sin pagar (pintora, envío, otros)
function avisoPintora(p) {
    const faltas = [['pintora', num(p.falta_pintura)], ['envío', num(p.falta_envio)], ['otros', num(p.falta_otros)]].filter(([, v]) => v > 0.005);
    const enEtapa = ['impreso', 'pintando', 'listo', 'entregado'].includes(p.estado);
    if (!p.venta_id || !faltas.length || !enEtapa) return '';
    return `<a href="${BASE}/gastos" class="small neg d-block mt-1" title="Regístralo en Gastos > Pagos pendientes"><i class="bi bi-exclamation-circle"></i> Falta pagar: ${faltas.map(([n, v]) => `${n} <b>${Q(v)}</b>`).join(' · ')}</a>`;
}

// Si la cotización no lleva pintura, desde Impreso se pasa directo a Listo para entregar
function siguienteDe(p) {
    if (p.estado === 'impreso' && Number(p.sin_pintura) === 1) return 'listo';
    return ETAPAS[p.estado]?.siguiente;
}

function botonEtapa(p) {
    if (p.estado === 'listo') {
        return `<button class="btn btn-sm btn-hc w-100 mt-2" data-acc="finalizar" data-id="${p.id}"><i class="bi bi-flag-fill"></i> Finalizar venta</button>`;
    }
    const sig = siguienteDe(p);
    if (sig) {
        return `<button class="btn btn-sm btn-outline-dark w-100 mt-2" data-acc="avanzar" data-id="${p.id}">Pasar a ${ETAPAS[sig].titulo} <i class="bi bi-arrow-right"></i></button>`;
    }
    return p.estado === 'entregado' ? '<div class="hc-ayuda mt-2"><i class="bi bi-lock-fill"></i> Entregado: ya no se puede cambiar</div>' : '';
}

const btnFilamento = (p) => `<button class="btn btn-sm btn-outline-secondary py-0 px-2" data-acc="filamento" data-id="${p.id}" title="Ver filamento de esta pieza"><i class="bi bi-hypnotize"></i></button>`;

function tarjeta(p) {
    const conVenta = Boolean(p.venta_id);
    const saldo = num(p.saldo);
    const debe = conVenta && saldo > 0;
    const final = FINALES.includes(p.estado);
    const vencido = p.fecha_entrega && p.fecha_entrega < hoy() && !final;

    const cancelado = p.estado === 'cancelado';
    const dinero = cancelado
        ? `<div class="small mt-1" style="color:#b42318"><i class="bi bi-x-circle-fill"></i> Cancelado${p.cancel_fecha ? ' el ' + fecha(p.cancel_fecha) : ''}</div>
           ${conVenta ? `<div class="small">Devuelto <b>${Q(p.cancel_devuelto)}</b> · Retenido <b class="pos">${Q(p.cancel_retenido)}</b></div>` : ''}
           ${p.cancel_motivo ? `<div class="hc-ayuda">${esc(p.cancel_motivo)}</div>` : ''}`
        : conVenta
        ? `<div class="d-flex justify-content-between small mt-1">
               <span class="fs-5 fw-bold">${Q(p.precio_venta)}</span>
               ${debe ? `<span class="neg fw-bold">Debe ${Q(saldo)}</span>` : '<span class="pos"><i class="bi bi-check-circle-fill"></i> Pagado</span>'}
           </div>`
        : '<div class="hc-ayuda mt-1">Sin venta registrada</div>';

    return `
        <div class="tarjeta">
            <div class="d-flex justify-content-between align-items-start">
                <b>${esc(p.nombre_mascota)}</b>
                <span class="d-flex gap-2 align-items-center">
                    ${btnFilamento(p)}
                    ${conVenta || final ? '' : `<button class="btn btn-sm btn-link text-danger p-0" data-acc="eliminar" data-id="${p.id}" title="Eliminar"><i class="bi bi-trash"></i></button>`}
                </span>
            </div>
            <div class="hc-ayuda">${esc(p.cliente)}</div>
            ${p.estado === 'solicitado' && p.fecha_pedido ? `<div class="small"><i class="bi bi-clock-history me-1"></i>Solicitado el <b>${fecha(p.fecha_pedido)}</b></div>` : ''}
            ${p.fecha_entrega ? `<div class="small ${vencido ? 'neg fw-bold' : ''}"><i class="bi bi-calendar-event me-1"></i>${fecha(p.fecha_entrega)}${vencido ? ' · atrasado' : ''}</div>` : ''}
            ${dinero}
            ${cancelado ? '' : avisoPintora(p)}
            ${num(p.falla_g) > 0 ? `<div class="small neg mt-1"><i class="bi bi-x-octagon-fill"></i> Fallas: <b>${gr(p.falla_g)}</b> · ${Q(p.falla_q)} extra</div>` : ''}
            ${p.notas ? `<div class="hc-ayuda mt-1">${esc(p.notas)}</div>` : ''}
            <div class="d-flex gap-1 mt-2">
                ${final ? '' : `<button class="btn btn-sm btn-outline-dark" data-acc="editar" data-id="${p.id}" title="Editar"><i class="bi bi-pencil"></i></button>`}
                ${p.estado === 'imprimiendo' && conVenta ? `<button class="btn btn-sm btn-outline-danger" data-acc="falla" data-id="${p.id}" title="La impresión falló: registrar el filamento perdido"><i class="bi bi-x-octagon"></i> Falló</button>` : ''}
                ${cancelado ? '' : conVenta
                    ? `<button class="btn btn-sm btn-hc ms-auto" data-acc="cobros" data-id="${p.id}"><i class="bi bi-cash-coin"></i> Cobros</button>`
                    : (p.estado === 'cancelado' ? '' : `<button class="btn btn-sm btn-hc ms-auto" data-acc="venta" data-id="${p.id}"><i class="bi bi-receipt"></i> Venta</button>`)}
            </div>
            ${botonEtapa(p)}
            ${final ? '' : `<button class="btn btn-sm btn-link text-danger p-0 mt-2" data-acc="cancelar" data-id="${p.id}"><i class="bi bi-x-circle"></i> Cancelar pedido</button>`}
        </div>`;
}

// Tarjeta de un pedido entregado: línea de tiempo con anticipo, pago completo y entrega
function tarjetaEntregada(p) {
    const conVenta = Boolean(p.venta_id);
    const paso = (texto, fechaTxt, extra = '') => `<li class="${fechaTxt ? '' : 'vacio'}">${texto}: <b>${fechaTxt ? fecha(fechaTxt) : 'sin registro'}</b>${extra}</li>`;
    const sinAnticipo = !p.anticipo_fecha;
    return `
        <div class="tarjeta tarjeta-entregada">
            <div class="d-flex justify-content-between align-items-start">
                <b>${esc(p.nombre_mascota)}</b>
                <span class="d-flex gap-2 align-items-center">${btnFilamento(p)}<span class="hc-chip"><i class="bi bi-lock-fill"></i> Entregado</span></span>
            </div>
            <div class="hc-ayuda">${esc(p.cliente)}</div>
            ${conVenta ? `<div class="small mt-1">Total <b class="fs-5">${Q(p.precio_venta)}</b> · Cobrado <b class="pos">${Q(p.pagado)}</b></div>` : ''}
            <ul class="linea-tiempo">
                ${paso('Pedido', p.fecha_pedido)}
                ${sinAnticipo ? '<li class="vacio">Anticipo: <b>sin anticipo</b> (se pagó al final)</li>' : paso('Anticipo', p.anticipo_fecha, ` · ${Q(p.anticipo_monto)}`)}
                ${paso('Pagado completo', p.ultimo_pago_fecha)}
                ${paso('Entregado', p.entregado_en)}
            </ul>
            ${num(p.falla_g) > 0 ? `<div class="small neg"><i class="bi bi-x-octagon-fill"></i> Fallas: <b>${gr(p.falla_g)}</b> · ${Q(p.falla_q)} extra</div>` : ''}
            ${p.notas ? `<div class="hc-ayuda mt-1">${esc(p.notas)}</div>` : ''}
            ${conVenta ? `<button class="btn btn-sm btn-hc w-100 mt-2" data-acc="cobros" data-id="${p.id}"><i class="bi bi-cash-coin"></i> Ver cobros y comprobantes</button>` : ''}
        </div>`;
}

function pintarEntregados() {
    const lista = pedidos
        .filter((p) => p.estado === 'entregado')
        .sort((a, b) => String(b.entregado_en || '').localeCompare(String(a.entregado_en || '')) || b.id - a.id);
    $('nEntregados').textContent = lista.length ? `· ${lista.length}` : '';
    $('entregados').innerHTML = lista.length
        ? lista.map(tarjetaEntregada).join('')
        : '<div class="hc-ayuda">Todavía no has entregado ningún pedido. Cuando finalices una venta aparecerá aquí.</div>';
}

function pintarCancelados() {
    const lista = pedidos
        .filter((p) => p.estado === 'cancelado')
        .sort((a, b) => String(b.cancel_fecha || '').localeCompare(String(a.cancel_fecha || '')) || b.id - a.id);
    $('cabCancelados').style.display = lista.length ? '' : 'none';
    $('nCancelados').textContent = lista.length ? `· ${lista.length}` : '';
    $('cancelados').innerHTML = lista.map((p) => tarjeta(p).replace('class="tarjeta"', 'class="tarjeta tarjeta-cancelada"')).join('');
}

function pintar() {
    // “Entregado” y “Cancelado” van cada uno en su propia sección abajo del tablero
    const claves = Object.keys(ETAPAS).filter((k) => k !== 'entregado' && k !== 'cancelado');
    pintarEntregados();
    pintarCancelados();

    $('tablero').innerHTML = claves.map((clave) => {
        const e = ETAPAS[clave];
        const lista = pedidos.filter((p) => p.estado === clave);
        // En Solicitado el más antiguo va primero (el que se pidió hace más tiempo tiene prioridad)
        if (clave === 'solicitado') {
            lista.sort((a, b) => String(a.fecha_pedido).localeCompare(String(b.fecha_pedido)) || a.id - b.id);
        }
        return `
        <div class="columna">
            <h3><span>${e.titulo}</span><span>${lista.length}</span></h3>
            ${e.ayuda ? `<div class="hc-ayuda mb-2">${e.ayuda}</div>` : ''}
            ${lista.map(tarjeta).join('') || '<div class="hc-ayuda text-center">—</div>'}
        </div>`;
    }).join('');
}

// ── Pasar a la siguiente etapa (con confirmación, sin regreso) ──────────────
// Vista previa de lo que se va a gastar de filamento (para no mandar a imprimir con colores equivocados)
async function vistaPreviaFilamento(p) {
    const [r, inv] = await Promise.all([get('pedidos/filamento', { id: p.id }), get('inventario/colores')]);
    const recordatorio = `<div class="text-start mt-2 p-2 rounded" style="background:#eef4ff;border:1px solid #b9cdf5;border-left:5px solid #1d4ed8">
        <i class="bi bi-printer-fill"></i> <b>Primero envía la impresión en Bambu Studio</b> y después confirma aquí. Compara los colores y gramos de abajo con tu laminado.</div>`;
    if (r.codigo !== 1 || !r.datos.partes.length) {
        return `<div class="text-start p-2 rounded" style="background:#fff4f6;border:1px solid #f1b5c4;border-left:5px solid #d6336c">
            <b>No hay detalle de filamento</b> (${r.datos?.sin_cotizacion ? 'el pedido no tiene cotización ligada' : 'la cotización no tiene partes'}). No se descontará nada del inventario: revisa qué vas a imprimir.</div>${recordatorio}`;
    }
    const merma = num(r.datos.merma_pct);
    const stock = inv.codigo === 1 ? inv.datos || [] : [];
    const porColor = {};
    (r.datos.plan?.length ? r.datos.plan : r.datos.partes).forEach((x) => {
        const k = `${x.material_id}|${x.color || ''}`;
        porColor[k] ??= { color: x.color, material: x.material, material_id: x.material_id, g: 0 };
        porColor[k].g += num(x.gramos);
    });
    const lista = Object.values(porColor).sort((a, b) => b.g - a.g);
    const filas = lista.map((c) => {
        const gReal = c.g * (1 + merma / 100);
        const hay = stock.find((x) => String(x.material_id) === String(c.material_id) && x.color === c.color);
        const queda = hay ? num(hay.gramos) : 0;
        const falta = gReal > queda;
        return `<div class="d-flex justify-content-between align-items-center py-1" style="border-bottom:1px solid #eee">
            <span>${puntoColor(c.color, 22)}<b>${esc(c.color || 'Sin color')}</b> <span class="hc-ayuda">${esc(c.material)}</span></span>
            <span class="text-end"><b>${c.g.toFixed(1)} g</b>${merma > 0 ? ` <span class="hc-ayuda">(≈ ${gReal.toFixed(0)} g con merma)</span>` : ''}
                <div class="small ${falta ? 'neg fw-bold' : 'hc-ayuda'}">${falta ? `<i class="bi bi-exclamation-triangle-fill"></i> Solo hay ${queda.toFixed(0)} g en inventario` : `En inventario: ${queda.toFixed(0)} g`}</div></span></div>`;
    }).join('');
    const total = lista.reduce((t, c) => t + c.g, 0);
    const multi = lista.length > 1;
    const cam = r.datos.cambio;
    const bannerCambio = r.datos.plan?.length
        ? `<div class="text-start mt-2 p-2 rounded" style="background:${cam && num(cam.sobrecosto) > 0.004 ? '#fff4f6' : '#eefaf2'};border:1px solid ${cam && num(cam.sobrecosto) > 0.004 ? '#f1b5c4' : '#b9e3c8'}">
            <i class="bi bi-arrow-left-right"></i> <b>Colores cambiados respecto a la cotización.</b>
            ${cam && num(cam.sobrecosto) > 0.004 ? `Cuesta <b class="neg">Q${num(cam.sobrecosto).toFixed(2)} más</b> y se resta de la ganancia.` : (cam && num(cam.sobrecosto) < -0.004 ? `Ahorra <b class="pos">Q${(-num(cam.sobrecosto)).toFixed(2)}</b> y se suma a la ganancia.` : 'El costo no cambia.')}</div>`
        : '';
    return `<div class="text-start">
        <h6 class="mb-1">Filamento que se va a descontar${multi ? ` · <span class="neg">${lista.length} colores</span>` : ''}</h6>
        ${filas}
        <div class="d-flex justify-content-between pt-1"><b>Total</b><b>${total.toFixed(1)} g</b></div>
    </div>${bannerCambio}${recordatorio}
    <div class="text-start mt-2 p-2 rounded" style="background:#fff8e6;border:1px solid #f0d58a;border-left:5px solid #c99a14">
        <i class="bi bi-eye-fill"></i> <b>Revisa bien:</b> ¿los colores y la cantidad de colores coinciden con lo que vas a imprimir? Si la cotización quedó incompleta, cancela y corrígela antes.</div>`;
}

async function avanzar(p) {
    const actual = ETAPAS[p.estado];
    const sig = ETAPAS[siguienteDe(p)];

    let aviso = '';
    if (p.estado === 'impreso' && siguienteDe(p) === 'listo') aviso = '<div class="hc-ayuda mt-2">Esta pieza no lleva pintura en la cotización: se salta “En pintura”.</div>';
    if (p.estado === 'solicitado') {
        if (!p.venta_id) aviso = '<div class="neg mt-2"><b>Este pedido todavía no tiene venta registrada.</b></div>';
        else if (num(p.pagado) > 0) aviso = `<div class="pos mt-2">Anticipo registrado: <b>${Q(p.pagado)}</b></div>`;
        else aviso = '<div class="neg mt-2"><b>Todavía no hay anticipo registrado.</b></div>';
    }

    // Al pasar a Imprimiendo se descuenta el filamento: se muestra qué va a salir y se confirma con calma
    const imprime = p.estado === 'solicitado';
    let previa = '';
    if (imprime) previa = await vistaPreviaFilamento(p);
    // Los colores solo se cambian aquí (antes de imprimir): después ya sale del inventario
    const puedeCambiar = imprime && (await get('pedidos/filamento', { id: p.id })).datos?.editable;

    const { isConfirmed, isDenied } = await Swal.fire({
        icon: imprime ? 'warning' : 'question',
        titleText: `¿Pasar “${p.nombre_mascota}” a ${sig.titulo}?`,
        width: imprime ? 640 : undefined,
        html: `${previa}<div class="${imprime ? 'mt-3' : ''}">Esta acción <b>no se puede deshacer</b>: el pedido ya no podrá regresar a “${esc(actual.titulo)}”.</div>${aviso}`,
        showCancelButton: true,
        showDenyButton: Boolean(puedeCambiar),
        denyButtonText: '<i class="bi bi-arrow-left-right"></i> Cambiar colores',
        denyButtonColor: '#444',
        focusCancel: true,
        confirmButtonText: `Sí, pasar a ${sig.titulo}`,
        cancelButtonText: 'Cancelar',
        confirmButtonColor: ORO,
        // Solo al pasar a Imprimiendo: el botón se activa a los 3 segundos
        didOpen: imprime ? () => {
            const btn = Swal.getConfirmButton();
            const texto = `Sí, pasar a ${sig.titulo}`;
            let seg = 3;
            btn.disabled = true;
            btn.textContent = `${texto} (${seg})`;
            const reloj = setInterval(() => {
                seg -= 1;
                if (seg <= 0) {
                    clearInterval(reloj);
                    btn.disabled = false;
                    btn.textContent = texto;
                } else {
                    btn.textContent = `${texto} (${seg})`;
                }
            }, 1000);
            // si se cierra antes, se detiene el reloj
            const cierre = new MutationObserver(() => { if (!document.body.contains(btn)) { clearInterval(reloj); cierre.disconnect(); } });
            cierre.observe(document.body, { childList: true, subtree: true });
        } : undefined,
    });
    if (isDenied) {
        // Cambió los colores: se vuelve a mostrar la confirmación ya con los colores nuevos
        await editarColores(p);
        return avanzar(p);
    }
    if (!isConfirmed) return;

    const r = await post('pedidos/estado', { id: p.id, desde: p.estado });
    if (r.codigo !== 1) {
        await error(r);
        return cargar();
    }
    toast.fire({ icon: 'success', title: r.mensaje });
    await cargar();
    avisarFilamento(r.alertas);
}

// Después de gastar filamento: avisa de los colores que quedaron bajos
function avisarFilamento(alertas) {
    if (!alertas?.length) return;
    Swal.fire({
        icon: 'warning',
        title: 'Filamento por acabarse',
        html: `<div class="text-start">${alertas.map((a) => `<div class="mb-1">${textoAlerta(a)}</div>`).join('')}</div>`,
        confirmButtonText: 'Entendido',
        confirmButtonColor: ORO,
    });
}

// ── Cambiar los colores reales de un pedido que todavía no se imprime ──────
// Los gramos y las horas son los de la cotización (ya laminados); solo cambia de qué color sale cada gramo.
// Devuelve true si se guardó algo.
async function editarColores(p, datos) {
    const d = datos || (await get('pedidos/filamento', { id: p.id })).datos;
    const total = num(d.total_g);
    const merma = num(d.merma_pct);
    const catalogo = d.colores || [];
    if (!catalogo.length) {
        await Swal.fire({ icon: 'info', title: 'No hay colores en inventario', text: 'Registra tus carretes en Inventario para poder elegirlos.', confirmButtonColor: ORO });
        return false;
    }

    // Punto de partida: lo ya elegido, o los colores de la cotización juntados por color
    const base = {};
    const origen = d.plan?.length ? d.plan : d.partes;
    origen.forEach((x) => {
        const k = `${x.material_id}|${x.color || ''}`;
        base[k] ??= { clave: k, g: 0 };
        base[k].g += num(x.gramos);
    });
    const filasIni = Object.values(base).map((b) => {
        const c = catalogo.find((x) => `${x.material_id}|${x.color}`.toLowerCase() === b.clave.toLowerCase());
        return { clave: c ? `${c.material_id}|${c.color}` : '', g: Number(b.g.toFixed(2)) };
    });
    const porClave = (k) => catalogo.find((c) => `${c.material_id}|${c.color}` === k);

    const { isConfirmed } = await Swal.fire({
        titleText: `Colores reales · ${p.nombre_mascota}`,
        width: 680,
        html: `<div class="text-start">
            <div class="hc-ayuda mb-2">Reparte los <b>${total.toFixed(1)} g</b> de la cotización entre los colores que de verdad vas a usar (como los da Bambu Studio, sin merma). Los gramos totales y las horas no cambian.</div>
            <div id="cfFilas"></div>
            <div class="d-flex gap-2 mt-2">
                <button type="button" class="btn btn-sm btn-outline-dark" id="cfMas"><i class="bi bi-plus-lg"></i> Agregar color</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="cfResto">Poner lo que falta</button>
            </div>
            <div id="cfCuenta" class="mt-3 fw-bold"></div>
            <div id="cfCosto" class="mt-2"></div>
        </div>`,
        showCancelButton: true,
        showDenyButton: Boolean(d.plan?.length),
        denyButtonText: 'Volver a la cotización',
        confirmButtonText: 'Guardar colores',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: ORO,
        didOpen: (pop) => {
            const cont = pop.querySelector('#cfFilas');
            const btnOk = Swal.getConfirmButton();
            const nuevaFila = (clave = '', g = '') => {
                const div = document.createElement('div');
                div.className = 'd-flex gap-2 align-items-center mb-2 cf-fila';
                div.innerHTML = `<div class="cf-sel" style="flex:1;min-width:0"></div>
                    <input type="number" class="form-control cf-g" min="0" step="0.01" placeholder="Gramos" style="max-width:130px" value="${g}">
                    <button type="button" class="btn btn-outline-danger cf-quitar" title="Quitar"><i class="bi bi-x-lg"></i></button>`;
                const [mid, ...resto] = String(clave).split('|');
                const sc = selectorColor({
                    items: catalogo,
                    valor: clave ? { material_id: mid, color: resto.join('|') } : null,
                    // "Igual que la fila de arriba": el color de la fila anterior
                    anterior: () => {
                        const filas = [...cont.querySelectorAll('.cf-fila')];
                        for (let i = filas.indexOf(div) - 1; i >= 0; i--) {
                            const s = filas[i].__sc.get();
                            if (s) return s;
                        }
                        return null;
                    },
                    alCambiar: () => refrescar(),
                });
                div.__sc = sc;
                div.querySelector('.cf-sel').appendChild(sc.el);
                cont.appendChild(div);
            };
            const claveDe = (f) => { const s = f.__sc.get(); return s ? `${s.material_id}|${s.color}` : ''; };
            const leer = () => [...cont.querySelectorAll('.cf-fila')].map((f) => ({ clave: claveDe(f), g: parseFloat(f.querySelector('.cf-g').value) || 0 }));
            const refrescar = () => {
                const filas = leer();
                const suma = filas.reduce((s, f) => s + f.g, 0);
                const dif = total - suma;
                const exacto = Math.abs(dif) <= 0.1 + 1e-9;
                const completas = filas.length > 0 && filas.every((f) => f.clave && f.g > 0);
                pop.querySelector('#cfCuenta').innerHTML = exacto
                    ? `<span class="pos"><i class="bi bi-check-circle-fill"></i> Asignado ${suma.toFixed(1)} de ${total.toFixed(1)} g</span>`
                    : `<span class="neg"><i class="bi bi-x-circle-fill"></i> Asignado ${suma.toFixed(1)} de ${total.toFixed(1)} g · ${dif > 0 ? `faltan ${dif.toFixed(1)} g` : `sobran ${Math.abs(dif).toFixed(1)} g`}</span>`;
                btnOk.disabled = !(exacto && completas);

                // Costo: el mismo criterio de la cotización (mayor entre referencia del material y costo real del color)
                let nuevo = 0;
                const sinAlcance = [];
                const juntos = {};
                filas.forEach((f) => { if (f.clave) juntos[f.clave] = (juntos[f.clave] || 0) + f.g; });
                Object.entries(juntos).forEach(([k, g]) => {
                    const c = porClave(k);
                    if (!c) return;
                    nuevo += g / 1000 * num(c.cotiza_a);
                    if (g * (1 + merma / 100) > num(c.gramos)) sinAlcance.push(`${c.color} (hay ${Math.round(c.gramos)} g)`);
                });
                nuevo = Math.round(nuevo * (1 + merma / 100) * 100) / 100;
                const cot = num(d.costo_cotizado);
                const sobre = Math.round((nuevo - cot) * 100) / 100;
                let costoHtml = `<div class="hc-ayuda">Material cotizado: ${Q(cot)} · con estos colores: <b>${Q(nuevo)}</b></div>`;
                if (exacto && completas) {
                    if (sobre > 0.004) {
                        costoHtml = `<div class="p-2 rounded" style="background:#fff4f6;border:1px solid #f1b5c4;border-left:5px solid #d6336c">
                            <i class="bi bi-exclamation-triangle-fill neg"></i> <b>Estos colores cuestan Q${sobre.toFixed(2)} más</b> que lo cotizado (${Q(cot)} → ${Q(nuevo)}). Ese dinero <b>se resta de la ganancia</b> de esta venta. Para la próxima cotización, usa los colores que de verdad vas a imprimir.</div>`;
                    } else if (sobre < -0.004) {
                        costoHtml = `<div class="p-2 rounded" style="background:#eefaf2;border:1px solid #b9e3c8"><i class="bi bi-piggy-bank-fill pos"></i> <b>Estos colores ahorran Q${(-sobre).toFixed(2)}</b> (${Q(cot)} → ${Q(nuevo)}). Ese ahorro <b>se suma a la ganancia</b> de esta venta.</div>`;
                    } else {
                        costoHtml = `<div class="p-2 rounded" style="background:#eefaf2;border:1px solid #b9e3c8"><i class="bi bi-check-circle-fill pos"></i> El costo no cambia (${Q(cot)} → ${Q(nuevo)}). La ganancia no cambia.</div>`;
                    }
                }
                if (sinAlcance.length) costoHtml += `<div class="neg small mt-1"><i class="bi bi-exclamation-triangle-fill"></i> No alcanza con la merma: ${sinAlcance.map(esc).join(', ')}</div>`;
                pop.querySelector('#cfCosto').innerHTML = costoHtml;
            };
            (filasIni.length ? filasIni : [{ clave: '', g: '' }]).forEach((f) => nuevaFila(f.clave, f.g));
            pop.querySelector('#cfMas').addEventListener('click', () => { nuevaFila(); refrescar(); });
            pop.querySelector('#cfResto').addEventListener('click', () => {
                const filas = [...cont.querySelectorAll('.cf-fila')];
                const ult = filas[filas.length - 1];
                if (!ult) return;
                const otras = leer().slice(0, -1).reduce((s, f) => s + f.g, 0);
                ult.querySelector('.cf-g').value = Math.max(0, total - otras).toFixed(2);
                refrescar();
            });
            cont.addEventListener('click', (e) => {
                if (e.target.closest('.cf-quitar')) {
                    if (cont.querySelectorAll('.cf-fila').length > 1) e.target.closest('.cf-fila').remove();
                    refrescar();
                }
            });
            cont.addEventListener('input', refrescar);
            cont.addEventListener('change', refrescar);
            refrescar();
        },
        preConfirm: () => {
            const filas = [...document.querySelectorAll('.cf-fila')].map((f) => {
                const s = f.__sc.get();
                const [mid, ...resto] = (s ? `${s.material_id}|${s.color}` : '|').split('|');
                return { material_id: Number(mid), color: resto.join('|'), gramos: parseFloat(f.querySelector('.cf-g').value) || 0 };
            });
            return filas;
        },
    }).then(async (res) => {
        if (res.isDenied) {
            const r = await post('pedidos/filamento-restablecer', { id: p.id });
            if (r.codigo !== 1) { await error(r); return { isConfirmed: false }; }
            toast.fire({ icon: 'success', title: r.mensaje });
            return { isConfirmed: true };
        }
        if (!res.isConfirmed) return { isConfirmed: false };
        const r = await post('pedidos/filamento-guardar', { id: p.id, items: JSON.stringify(res.value) });
        if (r.codigo !== 1) { await error(r); return { isConfirmed: false }; }
        if (num(r.sobrecosto) > 0.004) {
            await Swal.fire({ icon: 'warning', title: 'Colores guardados', html: `El cambio cuesta <b>Q${num(r.sobrecosto).toFixed(2)} más</b> y se resta de la ganancia de esta venta.`, confirmButtonColor: ORO });
        } else if (num(r.sobrecosto) < -0.004) {
            await Swal.fire({ icon: 'success', title: 'Colores guardados', html: `El cambio ahorra <b>Q${(-num(r.sobrecosto)).toFixed(2)}</b> y se suma a la ganancia de esta venta.`, confirmButtonColor: ORO });
        } else {
            toast.fire({ icon: 'success', title: r.mensaje });
        }
        return { isConfirmed: true };
    });
    return isConfirmed;
}

// ── Filamento de la pieza: qué lleva según la cotización y qué ya salió del inventario ──
const hrs = (min) => {
    const m = Math.round(num(min));
    return `${Math.floor(m / 60)} h ${String(m % 60).padStart(2, '0')} min`;
};

async function filamento(p) {
    const r = await get('pedidos/filamento', { id: p.id });
    if (r.codigo !== 1) return error(r);
    const { partes, consumos, sin_cotizacion: sinCot, merma_pct: merma, plan, cambio } = r.datos;

    if (!partes.length) {
        return Swal.fire({
            icon: 'info', titleText: p.nombre_mascota, confirmButtonColor: ORO,
            text: sinCot ? 'Este pedido no tiene cotización ligada, así que no hay detalle de filamento.' : 'La cotización no tiene partes registradas.',
        });
    }

    const filas = partes.map((x) => `<tr>
        <td>${esc(x.nombre || '—')}</td>
        <td class="text-nowrap">${puntoColor(x.color, 16)}${esc(x.color || '—')} <span class="hc-ayuda">${esc(x.material)}</span></td>
        <td class="text-end text-nowrap">${num(x.gramos).toFixed(2)} g</td>
        <td class="text-end text-nowrap">${hrs(x.minutos)}</td></tr>`).join('');

    // Total por color (suma las partes del mismo color)
    const porColor = {};
    (plan?.length ? plan : partes).forEach((x) => {
        const k = `${x.color || ''}|${x.material}`;
        porColor[k] ??= { color: x.color, material: x.material, g: 0 };
        porColor[k].g += num(x.gramos);
    });
    const lista = Object.values(porColor).sort((a, b) => b.g - a.g);
    const totalG = lista.reduce((s, c) => s + c.g, 0);
    const totalMin = partes.reduce((s, x) => s + num(x.minutos), 0);
    const chips = lista.map((c) => `<div class="d-flex justify-content-between align-items-center py-1" style="border-bottom:1px solid #eee">
        <span>${puntoColor(c.color, 22)}<b>${esc(c.color || 'Sin color')}</b> <span class="hc-ayuda">${esc(c.material)}</span></span>
        <b>${c.g.toFixed(2)} g</b></div>`).join('');

    const real = (consumos || []);
    const piezaG = real.filter((c) => c.tipo === 'pieza').reduce((s, c) => s + num(c.gramos), 0);
    const fallaG = real.filter((c) => c.tipo === 'falla').reduce((s, c) => s + num(c.gramos), 0);
    const bloqueReal = real.length ? `
        <h6 class="mt-3">Ya descontado del inventario</h6>
        ${real.map((c) => `<div class="d-flex justify-content-between py-1" style="border-bottom:1px solid #eee">
            <span>${puntoColor(c.color, 18)}${esc(c.color)} ${c.tipo === 'falla' ? '<span class="hc-chip" style="background:#fde8e8;color:#b42318">falla</span>' : ''}</span>
            <span>${num(c.gramos).toFixed(2)} g · ${Q(c.costo)}</span></div>`).join('')}
        <div class="hc-ayuda mt-1">Incluye la merma presupuestada${fallaG > 0 ? ` y ${fallaG.toFixed(0)} g perdidos en fallas` : ''}.</div>`
        : '<div class="hc-ayuda mt-3"><i class="bi bi-info-circle"></i> Todavía no se descuenta nada del inventario: sale cuando el pedido pasa a Imprimiendo.</div>';

    const bannerCambio = plan?.length ? `<div class="mb-2 p-2 rounded" style="background:${cambio && num(cambio.sobrecosto) > 0.004 ? '#fff4f6' : '#eefaf2'};border:1px solid ${cambio && num(cambio.sobrecosto) > 0.004 ? '#f1b5c4' : '#b9e3c8'}">
            <i class="bi bi-arrow-left-right"></i> <b>Colores cambiados respecto a la cotización.</b>
            ${cambio ? `Material cotizado ${Q(cambio.costo_cotizado)} → con estos colores ${Q(cambio.costo_nuevo)}. ` : ''}${cambio && num(cambio.sobrecosto) > 0.004 ? `<b class="neg">Q${num(cambio.sobrecosto).toFixed(2)} más, restados de la ganancia de esta venta.</b>` : (cambio && num(cambio.sobrecosto) < -0.004 ? `<b class="pos">Ahorra Q${(-num(cambio.sobrecosto)).toFixed(2)}, sumados a la ganancia de esta venta.</b>` : 'El costo no cambia.')}</div>` : '';

    const res = await Swal.fire({
        titleText: `Filamento · ${p.nombre_mascota}`,
        width: 640,
        showDenyButton: Boolean(r.datos.editable),
        denyButtonText: '<i class="bi bi-arrow-left-right"></i> Cambiar colores',
        denyButtonColor: '#444',
        html: `<div class="text-start small">
            ${bannerCambio}
            <h6>Por color <span class="hc-ayuda">(${plan?.length ? 'los que de verdad vas a usar' : 'según la cotización'})</span></h6>
            ${chips}
            <div class="d-flex justify-content-between pt-2"><b>Total</b><b>${totalG.toFixed(2)} g · ${hrs(totalMin)}</b></div>
            ${merma > 0 ? `<div class="hc-ayuda">Más ${num(merma)}% de merma al imprimir: ≈ ${(totalG * (1 + merma / 100)).toFixed(0)} g reales.</div>` : ''}
            <h6 class="mt-3">Por parte</h6>
            <table class="table table-sm mb-0"><thead><tr><th>Parte</th><th>Color</th><th class="text-end">Gramos</th><th class="text-end">Tiempo</th></tr></thead><tbody>${filas}</tbody></table>
            ${bloqueReal}
        </div>`,
        confirmButtonText: 'Cerrar',
        confirmButtonColor: ORO,
    });
    if (res.isDenied) {
        await editarColores(p, r.datos);
        filamento(p);
    }
}

// ── Impresión fallida: el filamento perdido sale del carrete y se suma al costo de la pieza ──
const MOTIVOS_FALLA = ['Espagueti (se soltó la pieza)', 'Se despegó de la cama', 'Se atascó el filamento', 'Falló el soporte', 'Se acabó el filamento a medias', 'Otro'];

async function falla(p) {
    const r = await get('pedidos/carretes', { id: p.id });
    // Solo los carretes de los colores que lleva ESTA impresión
    const carretes = r.datos || [];
    if (!carretes.length) {
        return Swal.fire({ icon: 'info', title: 'No hay filamento de esta pieza', text: 'Los carretes de los colores que usa esta impresión no tienen filamento (o no están registrados en Gastos > Inventario de filamento).', confirmButtonColor: ORO });
    }
    const Q0 = (n) => `Q${num(n).toLocaleString('es-GT', { maximumFractionDigits: 0 })}`;
    // Si hay dos carretes del mismo color y material, se distinguen por su número
    const repetidos = {};
    carretes.forEach((k) => { const c = `${k.material_id}|${k.color}`; repetidos[c] = (repetidos[c] || 0) + 1; });
    const items = carretes.map((k) => ({
        id: k.id,
        material_id: k.material_id,
        material: k.material,
        color: k.color,
        gramos: num(k.gramos_actuales),
        etiqueta: repetidos[`${k.material_id}|${k.color}`] > 1 ? `carrete #${k.id}` : '',
        der: `costó ${Q0(num(k.costo) / num(k.peso_inicial_g) * 1000)}/kg`,
    }));
    let carrete = null;

    const { value } = await Swal.fire({
        titleText: `Falló “${p.nombre_mascota}”`,
        width: 520,
        html: `
            <div class="text-start">
                <div class="hc-ayuda mb-2">El filamento perdido sale del carrete y su costo se suma a <b>esta pieza</b> (baja su ganancia). El pedido sigue en Imprimiendo. Solo salen los colores que lleva esta impresión.</div>
                <label class="form-label mb-1">Carrete que se usó</label>
                <div id="fCarreteSel" class="mb-2"></div>
                <label class="form-label mb-1">Gramos perdidos</label>
                <input id="fGramos" type="number" min="1" step="any" class="form-control mb-1" placeholder="Ej. 80">
                <div id="fCosto" class="hc-ayuda mb-2"></div>
                <label class="form-label mb-1">Motivo</label>
                <select id="fMotivo" class="form-select mb-2">${MOTIVOS_FALLA.map((m) => `<option>${m}</option>`).join('')}</select>
                <input id="fOtro" class="form-control d-none" maxlength="200" placeholder="Describe qué pasó">
            </div>`,
        didOpen: (pop) => {
            const refrescar = () => {
                const k = carretes.find((x) => String(x.id) === String(carrete?.get()?.id));
                const g = parseFloat(pop.querySelector('#fGramos').value) || 0;
                pop.querySelector('#fCosto').innerHTML = k && g > 0
                    ? `Cuesta <b>${Q(g * num(k.costo) / num(k.peso_inicial_g))}</b>${g > num(k.gramos_actuales) ? ' · <span class="neg">el carrete solo tiene ' + gr(k.gramos_actuales) + '</span>' : ''}`
                    : '';
                pop.querySelector('#fOtro').classList.toggle('d-none', pop.querySelector('#fMotivo').value !== 'Otro');
            };
            carrete = selectorColor({ items, valor: items[0], alCambiar: () => refrescar(), vacio: 'Elige el carrete…' });
            pop.querySelector('#fCarreteSel').appendChild(carrete.el);
            pop.addEventListener('input', refrescar);
            refrescar();
        },
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonText: 'Registrar falla',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#b42318',
        preConfirm: () => {
            const pop = Swal.getPopup();
            const gramos = parseFloat(pop.querySelector('#fGramos').value);
            let motivo = pop.querySelector('#fMotivo').value;
            if (motivo === 'Otro') motivo = pop.querySelector('#fOtro').value.trim();
            if (!(gramos > 0)) { Swal.showValidationMessage('Escribe los gramos perdidos'); return false; }
            if (motivo.length < 3) { Swal.showValidationMessage('Describe qué pasó'); return false; }
            const sel = carrete.get();
            if (!sel) { Swal.showValidationMessage('Elige el carrete'); return false; }
            return { id: p.id, carrete_id: sel.id, gramos, motivo };
        },
    });
    if (!value) return;
    const res = await post('pedidos/falla', value);
    if (res.codigo !== 1) return error(res);
    await cargar();
    await Swal.fire({
        icon: 'success',
        title: 'Falla registrada',
        html: `<div class="text-start">
            <p class="mb-2">Se descontaron <b>${num(value.gramos).toLocaleString('es-GT')} g</b> del carrete.</p>
            <p class="mb-2">Costo de la falla: <b style="color:#b42318">${Q(res.costo)}</b>. Se suma al costo de fabricación de <b>${esc(p.nombre_mascota)}</b>.</p>
            <p class="mb-0">El precio de venta no cambia: tu <b>ganancia real</b> en esta pieza baja ${Q(res.costo)}. La ves en Ventas, al abrir la pieza.</p>
        </div>`,
        confirmButtonText: 'OK, entendido',
        confirmButtonColor: ORO,
    });
    avisarFilamento(res.alertas);
}

// ── Cancelar pedido: el cliente se arrepiente. Se devuelve el 50% de lo cobrado; el resto es por el trabajo perdido ──
async function cancelar(p) {
    const r = await get('pedidos/cancelacion', { id: p.id });
    if (r.codigo !== 1) return error(r);
    const c = r.datos;
    const pagado = num(c.pagado);
    const perdido = num(c.material) + num(c.directos);

    const { value } = await Swal.fire({
        titleText: `Cancelar “${p.nombre_mascota}”`,
        width: 540,
        html: `
            <div class="text-start">
                <div class="hc-ayuda mb-2">Esto <b>no se puede deshacer</b>. El pedido queda cancelado y la venta queda como historial.</div>
                <div class="small mb-2 p-2 rounded" style="background:#f8f5ee">
                    <div class="d-flex justify-content-between"><span>Precio de la venta</span><b>${Q(c.precio)}</b></div>
                    <div class="d-flex justify-content-between"><span>Ya cobrado al cliente</span><b>${Q(pagado)}</b></div>
                    <div class="d-flex justify-content-between"><span>Filamento ya gastado</span><b class="neg">${Q(c.material)}</b></div>
                    <div class="d-flex justify-content-between"><span>Pagado a pintora / envío</span><b class="neg">${Q(c.directos)}</b></div>
                </div>
                ${pagado > 0 ? `
                <label class="form-label mb-1">Dinero que le devuelves al cliente <span class="hc-ayuda">(política: ${c.pct}% de lo cobrado)</span></label>
                <input id="cDev" type="number" min="0" max="${pagado}" step="0.01" class="form-control mb-1" value="${num(c.sugerido).toFixed(2)}">
                <div id="cRes" class="small mb-2"></div>` : '<div class="hc-ayuda mb-2">El cliente no había pagado nada: no hay dinero que devolver.</div>'}
                <label class="form-label mb-1">Motivo</label>
                <input id="cMotivo" class="form-control mb-2" maxlength="200" placeholder="Ej. El cliente ya no quiere la figura">
                <label class="form-label mb-1">Fecha de cancelación</label>
                <input id="cFecha" type="date" class="form-control" max="${hoy()}" value="${hoy()}">
            </div>`,
        didOpen: (pop) => {
            const refrescar = () => {
                const el = pop.querySelector('#cDev');
                if (!el) return;
                const dev = Math.min(Math.max(parseFloat(el.value) || 0, 0), pagado);
                const ret = pagado - dev;
                const res = ret - perdido;
                pop.querySelector('#cRes').innerHTML = `Te quedas con <b>${Q(ret)}</b> · resultado de esta pieza: <b class="${res >= 0 ? 'pos' : 'neg'}">${res >= 0 ? '+' : ''}${Q(res)}</b>`;
            };
            pop.addEventListener('input', refrescar);
            refrescar();
        },
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonText: 'Sí, cancelar pedido',
        cancelButtonText: 'Volver',
        confirmButtonColor: '#b42318',
        preConfirm: () => {
            const pop = Swal.getPopup();
            const motivo = pop.querySelector('#cMotivo').value.trim();
            const el = pop.querySelector('#cDev');
            const devolucion = el ? parseFloat(el.value) || 0 : 0;
            if (motivo.length < 5) { Swal.showValidationMessage('Escribe el motivo (mínimo 5 letras)'); return false; }
            if (devolucion < 0 || devolucion > pagado + 0.005) { Swal.showValidationMessage(`La devolución va de Q0.00 a ${Q(pagado)}`); return false; }
            return { id: p.id, motivo, devolucion, fecha: pop.querySelector('#cFecha').value };
        },
    });
    if (!value) return;
    const res = await post('pedidos/cancelar', value);
    if (res.codigo !== 1) return error(res);
    await cargar();
    await Swal.fire({
        icon: 'success',
        title: 'Pedido cancelado',
        html: `<div class="text-start">
            <p class="mb-1">Cobrado: <b>${Q(res.pagado)}</b></p>
            <p class="mb-1">Devuelto al cliente: <b>${Q(res.devuelto)}</b> <span class="hc-ayuda">(queda registrado como salida de caja)</span></p>
            <p class="mb-1">Retenido por trabajo perdido: <b class="pos">${Q(res.retenido)}</b></p>
            <p class="mb-2">Costos ya gastados: <b class="neg">${Q(num(res.material) + num(res.directos))}</b></p>
            <p class="mb-0">Resultado de esta pieza: <b class="${res.resultado >= 0 ? 'pos' : 'neg'}">${res.resultado >= 0 ? '+' : ''}${Q(res.resultado)}</b>, en el mes de la cancelación.</p>
        </div>`,
        confirmButtonText: 'OK, entendido',
        confirmButtonColor: ORO,
    });
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
                <input id="vPrecio" type="number" min="0" step="any" class="form-control mb-1" value="${num(p.precio_cotizado) > 0 ? num(p.precio_cotizado) : ''}">
                ${num(p.precio_cotizado) > 0 ? `<div class="hc-ayuda mb-2">Viene de la cotización (${Q(p.precio_cotizado)}). Cámbialo si vendiste a otro precio.</div>` : '<div class="mb-2"></div>'}
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
function alHacerClic(e) {
    const btn = e.target.closest('[data-acc]');
    if (!btn) return;
    const p = pedidos.find((x) => String(x.id) === btn.dataset.id);
    if (!p) return;

    const acc = btn.dataset.acc;
    if (acc === 'avanzar') avanzar(p);
    if (acc === 'finalizar') finalizar(p);
    if (acc === 'falla') falla(p);
    if (acc === 'cancelar') cancelar(p);
    if (acc === 'editar') formPedido(p);
    if (acc === 'cobros') cobros(p);
    if (acc === 'venta') registrarVenta(p);
    if (acc === 'eliminar') eliminar(p);
    if (acc === 'filamento') filamento(p);
}
$('tablero').addEventListener('click', alHacerClic);
$('entregados').addEventListener('click', alHacerClic);
$('cancelados').addEventListener('click', alHacerClic);

cargar();
