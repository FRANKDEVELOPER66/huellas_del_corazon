import { Dropdown } from "bootstrap";
import { $, num, toast, crearApi, error, fecha } from '../comun.js';

const { get, post } = crearApi($('appConfig').dataset.base || '');

const CAMPOS = [
    ['margen_pct', 'Margen de ganancia (%)', 'Sobre el precio de venta: 40% = de cada Q100, Q40 son ganancia.'],
    ['precio_minimo', 'Precio mínimo por pieza (Q)', 'Ninguna pieza sale más barata que esto.'],
    ['redondeo', 'Redondear el precio a múltiplos de (Q)', 'Con 5, un precio de Q652 sube a Q655.'],
    ['merma_pct', 'Merma de filamento (%)', 'Extra por soportes, purgas y fallos.'],
    ['empaque', 'Empaque por pieza (Q)', 'Caja, espuma, bolsa…'],
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

cargar();
