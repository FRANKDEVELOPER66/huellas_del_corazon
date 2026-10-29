import { $, esc, num, Q, Q0, crearApi, error, mesActual, fecha, signo, etiquetaMes } from '../comun.js';
import { Dropdown } from "bootstrap";


const { get } = crearApi($('appBalance').dataset.base || '');

const pct = (n, d) => (d > 0 ? `${((n / d) * 100).toFixed(1)}%` : '—');
const vacio = (cols, texto) => `<tr><td colspan="${cols}" class="text-center text-muted py-3">${texto}</td></tr>`;
const mayus = (s) => s.replace(/^./, (c) => c.toUpperCase());

// Una fila coloreada del estado de resultados
const fila = (color, titulo, explicacion, monto, extra = '') => `
    <div class="est-fila est-${color} ${extra}">
        <div class="tit">${titulo}<span class="exp">${explicacion}</span></div>
        <div class="monto">${monto}</div>
    </div>`;
const sub = (texto, monto) => `<div class="est-sub"><span>${texto}</span><span>${monto}</span></div>`;

async function cargar() {
    const mes = $('mes').value || mesActual();
    const r = await get('dashboard/resumen', { mes });
    if (r.codigo !== 1) return error(r);

    const d = r.datos;
    $('tituloMes').textContent = mayus(etiquetaMes(mes));
    kpis(d.resultados);
    resultados(d.resultados, d.caja);
    apilada(d.resultados);
    material(d.resultados, d.balance);
    produccion(d.produccion);
    piezas(d.resultados);
    balance(d.balance, mes);
    meses(d.por_mes);
    flujo(d.flujo);
}

// ── Tarjetas de arriba ──────────────────────────────────────────────────────
function kpis(e) {
    $('kpis').innerHTML = `
        <div class="hc-kpi azul"><div class="t">Ventas entregadas</div><div class="v">${Q(e.ventas)}</div></div>
        <div class="hc-kpi rojo"><div class="t">Costo de ventas</div><div class="v">${Q(e.costo.total)}</div></div>
        <div class="hc-kpi verde"><div class="t">Utilidad bruta · ${pct(e.utilidad_bruta, e.ventas)}</div><div class="v ${signo(e.utilidad_bruta)}">${Q(e.utilidad_bruta)}</div></div>
        <div class="hc-kpi morado"><div class="t">Gastos de operación</div><div class="v">${Q(e.total_operativos)}</div></div>
        <div class="hc-kpi verde"><div class="t">Utilidad neta · ${pct(e.utilidad_neta, e.ventas)}</div><div class="v ${signo(e.utilidad_neta)}">${Q(e.utilidad_neta)}</div></div>`;
}

// ── Estado de resultados ────────────────────────────────────────────────────
function resultados(e, caja) {
    const c = e.costo;
    const aviso = c.estimados > 0
        ? ` <b>(${c.estimados} pieza${c.estimados > 1 ? 's' : ''} con costos por pagar: regístralos en Gastos &gt; Pagos pendientes)</b>`
        : '';

    const operativos = e.operativos.length
        ? e.operativos.map((o) => sub(esc(o.categoria), Q(o.total))).join('')
        : sub('<i>No registraste gastos de operación este mes</i>', Q(0));

    $('resultados').innerHTML = `
        ${fila('azul', 'VENTAS', `Lo que cobraste por las ${e.piezas} pieza${e.piezas === 1 ? '' : 's'} que entregaste este mes`, Q(e.ventas))}

        ${fila('rojo', '(−) COSTO DE VENTAS', 'Lo que costó fabricar esas piezas. No es ganancia tuya: es del filamento, la pintora, los envíos…', `−${Q(c.total)}`)}
        ${sub('Filamento usado', Q(c.material))}
        ${sub('Luz y desgaste de la máquina', Q(c.maquina))}
        ${sub('Empaque', Q(c.empaque))}
        ${sub(`Pintora, envíos y otros${aviso}`, Q(c.directos))}

        ${fila('verde', `= UTILIDAD BRUTA · ${pct(e.utilidad_bruta, e.ventas)}`, 'Lo que te queda de las ventas después de pagar la fabricación. Es el margen que buscas al cotizar.', Q(e.utilidad_bruta), 'mt-2')}

        ${fila('morado', '(−) GASTOS DE OPERACIÓN', 'Lo que cuesta mantener el negocio andando (no va en ninguna pieza): repuestos, herramientas, publicidad…', `−${Q(e.total_operativos)}`, 'mt-2')}
        ${operativos}

        ${fila('verde', `= UTILIDAD NETA · ${pct(e.utilidad_neta, e.ventas)}`, 'Tu ganancia final del mes', Q(e.utilidad_neta), 'est-total mt-2')}

        ${fila(caja.disponible < 0 ? 'rojo' : 'ambar', 'CAJA DISPONIBLE HOY', 'Es el dinero con el que compras filamento y pagas lo pendiente. No es lo mismo que la utilidad: viene de lo cobrado, lo que aportas y lo que ya pagaste.', Q(caja.disponible), 'est-total mt-4')}
        ${sub('Caja (cobrado + aportes − retiros − pagado)', Q(caja.caja))}
        ${sub('(−) Por pagar a la pintora, envíos y otros (pago sin registrar)', Q(caja.por_pagar))}
        ${sub('De lo disponible: ganancia que ya puedes retirar', Q(caja.ganancia_retirable))}
        ${sub('De lo disponible: reserva para repuestos y equipo', Q(caja.reserva))}
        ${sub('De lo disponible: para reponer material y fabricar (no es ganancia)', Q(caja.para_reponer))}`;
}

// ── Barra: de cada Q100 que vendes ──────────────────────────────────────────
function apilada(e) {
    if (e.ventas <= 0) {
        $('apilada').innerHTML = '<p class="hc-ayuda mb-0">Cuando entregues una pieza, aquí verás cómo se reparte cada Q100 que vendes.</p>';
        return;
    }
    const costo = (e.costo.total / e.ventas) * 100;
    const ops = (e.total_operativos / e.ventas) * 100;
    const neta = (e.utilidad_neta / e.ventas) * 100;
    const seg = (valor, color) => (valor > 0 ? `<div style="width:${valor}%;background:${color}">${valor >= 8 ? `Q${valor.toFixed(0)}` : ''}</div>` : '');

    $('apilada').innerHTML = `
        <div class="apilada">${seg(costo, '#b42318')}${seg(ops, '#6b3fa0')}${seg(Math.max(neta, 0), '#117a43')}</div>
        <div class="leyenda">
            <span><i style="background:#b42318"></i>Costo de fabricación: Q${costo.toFixed(1)}</span>
            <span><i style="background:#6b3fa0"></i>Gastos de operación: Q${ops.toFixed(1)}</span>
            <span><i style="background:#117a43"></i><b>Tu ganancia: Q${neta.toFixed(1)}</b></span>
        </div>`;
}

// ── Material nuevo ──────────────────────────────────────────────────────────
function material(e, b) {
    const usado = e.material.usado;
    const comprado = e.material.comprado;
    const inv = b.inventario;
    $('material').innerHTML = `
        ${fila('ambar', 'Comprado este mes', 'Dinero que invertiste en filamento nuevo (no es gasto: es inventario)', Q(comprado))}
        ${fila('rojo', 'Usado en lo que vendiste', 'Material de las piezas entregadas este mes', Q(usado))}
        ${fila(inv < 0 ? 'rojo' : 'verde', 'Inventario de filamento', 'Lo que te queda en rollos al cierre del mes', Q(inv))}
        <div class="hc-ayuda mt-2">
            ${usado > 0
                ? `Para <b>volver a comprar</b> el material que gastaste este mes necesitas apartar <b>${Q(usado)}</b> de lo que cobraste.`
                : 'Cuando entregues piezas, aquí verás cuánto debes apartar para reponer el material.'}
            ${inv < 0 ? '<br><b class="neg">El inventario sale negativo: te falta registrar compras de filamento en Gastos.</b>' : ''}
        </div>`;
}

// ── En producción ───────────────────────────────────────────────────────────
function produccion(p) {
    $('produccion').innerHTML = p.piezas
        ? `${fila('gris', `${p.piezas} pieza${p.piezas === 1 ? '' : 's'} sin entregar`, 'Aún no cuentan como venta', Q(p.valor))}
           ${fila('ambar', 'Anticipos recibidos', 'Dinero que ya cobraste de esas piezas', Q(p.anticipos))}
           <div class="hc-ayuda mt-1">Faltan por cobrar ${Q(Math.max(p.valor - p.anticipos, 0))}, que recibirás al entregar.</div>`
        : '<p class="hc-ayuda mb-0">No tienes piezas en producción.</p>';
}

// ── Piezas entregadas ───────────────────────────────────────────────────────
function piezas(e) {
    document.querySelector('#tablaPiezas tbody').innerHTML = e.piezas_detalle.length
        ? e.piezas_detalle.map((p) => `
            <tr>
                <td>${fecha(p.fecha)}</td>
                <td><b>${esc(p.descripcion)}</b></td>
                <td class="text-end">${Q0(p.precio)}</td>
                <td class="text-end">${Q(p.costo)}${p.estimado ? ' <span class="hc-chip" title="Aún falta pagar pintora, envío u otros de esta pieza">est.</span>' : ''}</td>
                <td class="text-end fw-bold ${signo(p.ganancia)}">${Q(p.ganancia)}</td>
            </tr>`).join('')
        : vacio(5, 'Ninguna pieza se entregó este mes.');
}

// ── Balance ─────────────────────────────────────────────────────────────────
function balance(b, mes) {
    $('balance').innerHTML = `
        <p class="hc-ayuda">Foto del negocio al ${fecha(b.al)}: lo que tienes, lo que debes y lo que realmente vale.</p>
        <div class="row g-3">
            <div class="col-lg-4">
                <h6 class="text-uppercase" style="color:#117a43">Lo que tienes</h6>
                ${fila(b.efectivo < 0 ? 'rojo' : 'verde', 'Efectivo', `Cobrado ${Q(b.cobros)} + aportes ${Q(b.aportes)} − retiros ${Q(b.retiros)} − pagado ${Q(b.pagos)}`, Q(b.efectivo))}
                ${fila('verde', 'Por cobrar', 'Ventas entregadas que aún no te pagan completas', Q(b.por_cobrar))}
                ${fila(b.inventario < 0 ? 'rojo' : 'ambar', 'Inventario de filamento', `Comprado ${Q(b.compras_inv)} − usado ${Q(b.material_usado)}${b.inventario < 0 ? ' · registra tus compras de filamento en Gastos' : ''}`, Q(b.inventario))}
                ${fila('ambar', 'Producción en proceso', 'Material y pagos a la pintora de piezas sin entregar', Q(b.en_proceso))}
                ${fila('verde', 'TOTAL QUE TIENES', 'Todo lo que el negocio posee hoy (efectivo + material + trabajo en proceso). No es tu ganancia ni lo que puedes gastar', Q(b.activos), 'est-total')}
            </div>
            <div class="col-lg-4">
                <h6 class="text-uppercase" style="color:#b26a00">Lo que debes</h6>
                ${fila('ambar', 'Anticipos de clientes', 'Dinero que ya cobraste de piezas que todavía no entregas: aún no es tuyo', Q(b.anticipos))}
                ${fila('ambar', 'Por pagar a la pintora y envíos', 'Costo de piezas entregadas que todavía no pagas', Q(b.por_pagar))}
                ${fila('ambar', 'TOTAL QUE DEBES', '', Q(b.pasivos), 'est-total')}
            </div>
            <div class="col-lg-4">
                <h6 class="text-uppercase" style="color:#1d5fa8">Lo que vale el negocio</h6>
                ${fila('azul', 'PATRIMONIO', 'Lo que tienes menos lo que debes: el capital que pusiste más lo que ganaste y dejaste en el negocio', Q(b.patrimonio), 'est-total')}
                <div class="hc-ayuda mt-2">No cuadra al centavo con la utilidad neta: la luz, el desgaste y el empaque se estiman por pieza y no salen de la caja.</div>
            </div>
        </div>`;
}

// ── Histórico ───────────────────────────────────────────────────────────────
function meses(lista) {
    document.querySelector('#tablaMeses tbody').innerHTML = lista.length
        ? lista.map((m) => `
            <tr role="button" data-mes="${m.mes}">
                <td>${esc(mayus(etiquetaMes(m.mes)))}</td>
                <td class="text-end" style="color:#1d5fa8">${Q(m.ventas)}</td>
                <td class="text-end" style="color:#b42318">${Q(m.costo)}</td>
                <td class="text-end" style="color:#6b3fa0">${Q(m.operativos)}</td>
                <td class="text-end fw-bold ${signo(m.neta)}">${Q(m.neta)}</td>
                <td class="text-end">${m.margen === null ? '—' : `${num(m.margen)}%`}</td>
            </tr>`).join('')
        : vacio(6, 'Aún no hay ventas entregadas.');
}

function flujo(lista) {
    document.querySelector('#tablaFlujo tbody').innerHTML = lista.length
        ? lista.map((m) => `
            <tr>
                <td>${esc(mayus(etiquetaMes(m.mes)))}</td>
                <td class="text-end">${Q(m.cobros)}</td>
                <td class="text-end">${Q(m.salidas)}</td>
                <td class="text-end fw-bold ${signo(m.acumulado)}">${Q(m.acumulado)}</td>
            </tr>`).join('')
        : vacio(4, 'Aún no hay movimientos.');
}

$('mes').addEventListener('change', cargar);
$('tablaMeses').addEventListener('click', (e) => {
    const f = e.target.closest('[data-mes]');
    if (!f) return;
    $('mes').value = f.dataset.mes;
    cargar();
    window.scrollTo({ top: 0, behavior: 'smooth' });
});

$('mes').value = mesActual();
cargar();
