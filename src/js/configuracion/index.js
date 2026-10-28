import { $, num, toast, crearApi, error, fecha, Q } from '../comun.js';

const { get, post } = crearApi($('appConfig').dataset.base || '');

const CAMPOS = [
    ['margen_pct', 'Margen de ganancia (%)', 'Sobre el precio de venta: 40% = de cada Q100, Q40 son ganancia.'],
    ['precio_minimo', 'Precio mínimo por pieza (Q)', 'Ninguna pieza sale más barata que esto.'],
    ['redondeo', 'Redondear el precio a múltiplos de (Q)', 'Con 5, un precio de Q652 sube a Q655.'],
    ['merma_pct', 'Merma de filamento (%)', 'Extra por soportes, purgas y fallos.'],
    ['empaque', 'Empaque estándar (Q)', 'Se usa si al cotizar no eliges otro empaque'],
    ['tarifa_kwh', 'Tarifa de luz (Q por kWh)', 'La de tu recibo de Energuate.'],
    ['consumo_watts', 'Consumo de la impresora (W)', 'Promedio mientras imprime.'],
    ['desgaste_hora', 'Desgaste de la máquina (Q por hora)', 'Boquillas, placas, mantenimiento.'],
    ['impuesto_pct', 'Impuesto incluido en el precio (%)', '0 si no facturas.'],
];

$('campos').innerHTML = CAMPOS.map(([id, etiqueta, ayuda]) => `
    <div class="col-md-6">
        <label class="form-label mb-1" for="${id}">${etiqueta}</label>
        <input type="number" step="any" min="0" id="${id}" class="form-control">
        <div class="hc-ayuda">${ayuda}</div>
    </div>`).join('');

async function cargar() {
    const r = await get('configuracion/vigente');
    if (r.codigo !== 1) return error(r);
    CAMPOS.forEach(([id]) => ($(id).value = num(r.datos[id])));
    $('vigenteDesde').textContent = r.datos.creada_en ? `Vigente desde el ${fecha(r.datos.creada_en)}` : '';
}

$('btnGuardar').addEventListener('click', async () => {
    const datos = {};
    CAMPOS.forEach(([id]) => (datos[id] = $(id).value));

    const r = await post('configuracion/guardar', datos);
    if (r.codigo !== 1) return error(r);
    toast.fire({ icon: 'success', title: r.mensaje });
    cargar();
});

async function cargarReserva() {
    const r = await get('configuracion/reserva');
    if (r.codigo !== 1) return;
    $('reservaMeta').value = num(r.datos.meta);
    const c = r.datos.caja;
    $('reservaEstado').innerHTML = `Hoy la reserva tiene <b>${Q(c.reserva)}</b> de <b>${Q(c.reserva_meta)}</b>. Ganancia que puedes retirar: <b>${Q(c.ganancia_retirable)}</b>.`;
}

$('btnReserva').addEventListener('click', async () => {
    const r = await post('configuracion/reserva', { meta: $('reservaMeta').value });
    if (r.codigo !== 1) return error(r);
    toast.fire({ icon: 'success', title: r.mensaje });
    cargarReserva();
});

cargar();
cargarReserva();
