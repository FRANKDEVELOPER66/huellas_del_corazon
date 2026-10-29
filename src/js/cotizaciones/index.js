import Swal from 'sweetalert2';

const raiz = document.getElementById('appCotizar');
const BASE = raiz.dataset.base || '';
const ORO = '#c99a14';

const $ = (id) => document.getElementById(id);
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const Q = (n) => 'Q' + Number(n || 0).toLocaleString('es-GT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const Q0 = (n) => 'Q' + Number(n || 0).toLocaleString('es-GT', { maximumFractionDigits: 0 });
const toast = Swal.mixin({ toast: true, position: 'top-end', showConfirmButton: false, timer: 2500, timerProgressBar: true });
const errorServidor = { codigo: 0, mensaje: 'No se pudo comunicar con el servidor' };

const PLACEHOLDER_RESULTADO = '<p class="hc-ayuda mb-0">Agrega los gramos y el tiempo de cada parte para ver el precio.</p>';

let materiales = [];
let pintores = [];
let clientes = [];
let turno = 0;
let temporizador = null;

// ── API ─────────────────────────────────────────────────────────────────────
// Lee la respuesta como texto: si PHP devuelve un error o aviso en HTML en lugar de JSON,
// se muestra ese error en pantalla (y completo en la consola) en vez de un mensaje genérico.
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

async function get(ruta, params = {}) {
    try {
        const url = new URL(`${BASE}/API/${ruta}`, window.location.origin);
        Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v));
        return await leer(await fetch(url));
    } catch (e) {
        return errorServidor;
    }
}

async function post(ruta, datos = {}) {
    try {
        const fd = new FormData();
        Object.entries(datos).forEach(([k, v]) => {
            if (v !== null && v !== undefined) fd.append(k, v);
        });
        return await leer(await fetch(`${BASE}/API/${ruta}`, { method: 'POST', body: fd }));
    } catch (e) {
        return errorServidor;
    }
}

// ── Catálogos ───────────────────────────────────────────────────────────────
const opciones = (lista, vacio = null) =>
    (vacio ? `<option value="">${vacio}</option>` : '') +
    lista.map((x) => `<option value="${x.id}">${esc(x.nombre)}</option>`).join('');

function llenarClientes(seleccionar = null) {
    const actual = seleccionar ?? $('clienteId').value;
    $('clienteId').innerHTML = opciones(clientes, 'Sin cliente');
    $('clienteId').value = String(actual);
}

function llenarPintores() {
    const actual = $('pintorId').value;
    $('pintorId').innerHTML = opciones(pintores, 'Sin pintor');
    $('pintorId').value = actual;
}

let empaques = [];

function llenarEmpaques() {
    const actual = document.querySelector('input[name="empaque"]:checked')?.value ?? '';
    const op = (valor, texto) => `
        <label class="empaque-op"><input type="radio" name="empaque" value="${valor}"><span>${texto}</span></label>`;
    $('empaqueOpciones').innerHTML =
        op('', 'Estándar')
        + empaques.map((e) => op(e.id, `${esc(e.nombre)} · ${Q0(e.costo)}`)).join('')
        + op('otro', 'Otro monto');
    const hay = document.querySelector(`input[name="empaque"][value="${actual}"]`);
    (hay || document.querySelector('input[name="empaque"][value=""]')).checked = true;
    actualizarEmpaqueOtro();
}

function actualizarEmpaqueOtro() {
    $('empaqueOtroBox').classList.toggle('d-none', document.querySelector('input[name="empaque"]:checked')?.value !== 'otro');
}

async function cargarCatalogos() {
    const [m, p, c, e] = await Promise.all([
        get('materiales/listar', { activos: 1 }),
        get('pintores/listar', { activos: 1 }),
        get('clientes/listar'),
        get('empaques/listar', { activos: 1 }),
    ]);
    materiales = m.datos || [];
    pintores = p.datos || [];
    clientes = c.datos || [];
    empaques = e.datos || [];
    llenarClientes();
    llenarEmpaques();
    llenarPintores();
    if (!materiales.length) {
        toast.fire({ icon: 'warning', title: 'Registra un material en Catálogos > Materiales' });
    }
}

// ── Partes ──────────────────────────────────────────────────────────────────
function agregarParte() {
    const n = document.querySelectorAll('.parte-fila[data-parte]').length + 1;
    const fila = document.createElement('div');
    fila.className = 'parte-fila';
    fila.dataset.parte = '1';
    fila.innerHTML = `
        <input type="text" class="form-control p-nombre" placeholder="Parte ${n}">
        <select class="form-select p-material">${opciones(materiales)}</select>
        <input type="number" class="form-control p-gramos" min="0" step="any" placeholder="Gramos">
        <input type="number" class="form-control p-horas" min="0" step="1" placeholder="Horas">
        <input type="number" class="form-control p-minutos" min="0" step="any" placeholder="Min">
        <button type="button" class="btn btn-outline-danger p-quitar" title="Quitar"><i class="bi bi-x-lg"></i></button>`;
    $('partes').appendChild(fila);
}

function leerPartes() {
    return [...document.querySelectorAll('.parte-fila[data-parte]')]
        .map((f) => ({
            nombre: f.querySelector('.p-nombre').value.trim(),
            material_id: f.querySelector('.p-material').value,
            gramos: parseFloat(f.querySelector('.p-gramos').value) || 0,
            minutos: (parseFloat(f.querySelector('.p-horas').value) || 0) * 60 + (parseFloat(f.querySelector('.p-minutos').value) || 0),
        }))
        .filter((p) => p.gramos > 0);
}

function datosEmpaque() {
    const v = document.querySelector('input[name="empaque"]:checked')?.value ?? '';
    if (v === 'otro') return { empaque_costo: $('empaqueCosto').value || 0 };
    return v ? { empaque_id: v } : {};
}

function actualizarEnvioTotal() {
    const t = (parseFloat($('envioPintora').value) || 0) + (parseFloat($('envioCliente').value) || 0);
    $('envioTotal').textContent = Q(t);
}

// "Envíos (Q40 a la pintora + Q40 al cliente)" cuando la cotización separó los tramos
function envioEtiqueta(d) {
    if (d.envio_pintora === null || d.envio_pintora === undefined) return 'Envíos';
    return `Envíos <small class="text-muted">(${Q(d.envio_pintora)} a la pintora + ${Q(d.envio_cliente)} al cliente)</small>`;
}

function datosFormulario() {
    return {
        partes: JSON.stringify(leerPartes()),
        nombre_pieza: $('nombrePieza').value.trim(),
        cliente_id: $('clienteId').value,
        alto_cm: $('altoCm').value,
        pintor_id: $('pintorId').value,
        costo_pintura: $('costoPintura').value,
        envio_pintora: $('envioPintora').value || 0,
        envio_cliente: $('envioCliente').value || 0,
        otros_insumos: $('otrosInsumos').value || 0,
        margen_pct: $('margenPct').value,
        ...datosEmpaque(),
    };
}

// ── Cálculo en vivo ─────────────────────────────────────────────────────────
function programarCalculo() {
    clearTimeout(temporizador);
    temporizador = setTimeout(calcular, 350);
}

async function calcular() {
    const miTurno = ++turno;
    if (!leerPartes().length) {
        $('resultado').innerHTML = PLACEHOLDER_RESULTADO;
        return;
    }

    const r = await post('cotizaciones/calcular', datosFormulario());
    if (miTurno !== turno) return; // llegó una respuesta más nueva

    if (r.codigo !== 1) {
        $('resultado').innerHTML = `<div class="alert alert-warning mb-0">${esc(r.mensaje)}</div>`;
        return;
    }
    pintar(r);
}

const num = (n) => Number(n || 0);
const tiempo = (min) => `${Math.floor(min / 60)} h ${min % 60} min`;

// Los 3 datos clave, siempre iguales en el resultado, el detalle y la venta
const trio = (costo, ganancia, total) => `
    <div class="hc-trio">
        <div class="hc-dato"><span>Costo de fabricación</span><b>${Q(costo)}</b></div>
        <div class="hc-dato hc-ganancia ${num(ganancia) < 0 ? 'neg' : ''}"><span>Tu ganancia</span><b>${Q(ganancia)}</b></div>
        <div class="hc-dato"><span>Total a cobrar</span><b>${Q0(total)}</b></div>
    </div>`;

const filasCostos = (d) => [
    ['Filamento (con merma)', d.costo_material],
    ['Luz', d.costo_luz],
    ['Desgaste de la máquina', d.costo_desgaste],
    [d.empaque_nombre ? `Empaque (${esc(d.empaque_nombre)})` : 'Empaque', d.costo_empaque],
    ['Pintura', d.costo_pintura],
    [envioEtiqueta(d), d.costo_envio],
    ['Otros insumos', d.otros_insumos],
].map(([t, v]) => `<tr><td>${t}</td><td>${Q(v)}</td></tr>`).join('')
    + `<tr class="total"><td>Costo de fabricación</td><td>${Q(d.costo_total)}</td></tr>`;

function pintar({ datos: d, alternativas }) {
    $('resultado').innerHTML = `
        <div class="hc-resumen">${d.total_gramos} g · ${tiempo(d.total_minutos)} de impresión · margen ${d.margen_pct_usado}%</div>
        ${trio(d.costo_total, d.ganancia, d.precio_sugerido)}
        <table class="hc-tabla">${filasCostos(d)}</table>
        <div class="hc-ayuda mt-3">Con otro margen, el total sería:</div>
        <div class="hc-chips">
            ${Object.entries(alternativas).map(([mg, p]) => `<span class="hc-chip">Margen ${mg}%: <b>${Q0(p)}</b></span>`).join('')}
        </div>`;

    $('costoPintura').placeholder = $('pintorId').value && $('altoCm').value ? `Auto: ${Q(d.costo_pintura)}` : 'Monto que cobra';
}

// ── Guardar y limpiar ───────────────────────────────────────────────────────
function limpiar() {
    ['nombrePieza', 'altoCm', 'costoPintura', 'margenPct'].forEach((id) => ($(id).value = ''));
    $('envioPintora').value = 0;
    $('envioCliente').value = 0;
    actualizarEnvioTotal();
    $('otrosInsumos').value = 0;
    $('empaqueCosto').value = '';
    llenarEmpaques();
    document.querySelector('input[name="empaque"][value=""]').checked = true;
    actualizarEmpaqueOtro();
    $('clienteId').value = '';
    $('pintorId').value = '';
    $('partes').innerHTML = '';
    agregarParte();
    turno++;
    $('resultado').innerHTML = PLACEHOLDER_RESULTADO;
    liberarGuardar();
}

let guardada = false;

function marcarGuardada() {
    guardada = true;
    $('btnGuardar').disabled = true;
    $('btnGuardar').innerHTML = '<i class="bi bi-check-circle-fill me-1"></i>Cotización guardada';
}

// Si cambias cualquier dato después de guardar, se vuelve a poder guardar como cotización nueva
function liberarGuardar() {
    if (!guardada) return;
    guardada = false;
    $('btnGuardar').disabled = false;
    $('btnGuardar').innerHTML = '<i class="bi bi-save me-1"></i>Guardar cotización';
}

async function guardar() {
    const datos = datosFormulario();
    if (!datos.nombre_pieza) {
        return toast.fire({ icon: 'warning', title: 'Escribe el nombre de la pieza' });
    }
    if (!leerPartes().length) {
        return toast.fire({ icon: 'warning', title: 'Agrega los gramos de al menos una parte' });
    }

    const r = await post('cotizaciones/crear', datos);
    if (r.codigo !== 1) {
        return Swal.fire({ icon: 'error', title: 'No se pudo guardar', text: r.mensaje, confirmButtonColor: ORO });
    }

    await cargarHistorial();
    marcarGuardada();

    const { isConfirmed } = await Swal.fire({
        icon: 'success',
        title: 'Cotización guardada',
        html: `Total: <b>${Q0(r.datos.precio_sugerido)}</b> · Ganancia: <b class="text-success">${Q(r.datos.ganancia)}</b>`,
        showCancelButton: true,
        confirmButtonText: 'Registrar venta',
        cancelButtonText: 'Cerrar',
        confirmButtonColor: ORO,
    });
    if (isConfirmed) abrirVenta(r.id);
}

// ── Clientes ────────────────────────────────────────────────────────────────
async function nuevoCliente() {
    const { value: form } = await Swal.fire({
        titleText: 'Nuevo cliente',
        html: '<input id="cNombre" class="swal2-input" placeholder="Nombre"><input id="cTelefono" class="swal2-input" placeholder="Teléfono (opcional)">',
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonText: 'Guardar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: ORO,
        preConfirm: () => {
            const p = Swal.getPopup();
            const nombre = p.querySelector('#cNombre').value.trim();
            if (!nombre) {
                Swal.showValidationMessage('Escribe el nombre del cliente');
                return false;
            }
            return { nombre, telefono: p.querySelector('#cTelefono').value.trim() };
        },
    });
    if (!form) return;

    const r = await post('clientes/crear', form);
    if (r.codigo !== 1) {
        return Swal.fire({ icon: 'error', title: r.mensaje, confirmButtonColor: ORO });
    }
    clientes = (await get('clientes/listar')).datos || [];
    llenarClientes(r.id);
    toast.fire({ icon: 'success', title: 'Cliente registrado' });
}

// ── Historial ───────────────────────────────────────────────────────────────
async function cargarHistorial() {
    const r = await get('cotizaciones/listar');
    const filas = r.datos || [];
    const cuerpo = document.querySelector('#tablaCotizaciones tbody');

    if (!filas.length) {
        cuerpo.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-3">Aún no hay cotizaciones.</td></tr>';
        return;
    }

    cuerpo.innerHTML = filas.map((c) => {
        const vendida = Number(c.ventas) > 0;
        return `
        <tr>
            <td>${new Date(String(c.creada_en).replace(' ', 'T')).toLocaleDateString('es-GT')}</td>
            <td><b>${esc(c.nombre_pieza)}</b>${c.alto_cm ? ` <span class="text-muted">· ${Number(c.alto_cm)} cm</span>` : ''}</td>
            <td>${esc(c.cliente || '—')}</td>
            <td>${esc(c.pintor || '—')}</td>
            <td class="text-end">${Q(c.costo_total)}</td>
            <td class="text-end fw-bold ${Number(c.ganancia) < 0 ? 'text-danger' : 'text-success'}">${Q(c.ganancia)}</td>
            <td class="text-end"><b>${Q0(c.precio_sugerido)}</b></td>
            <td class="text-end text-nowrap">
                <button class="btn btn-sm btn-outline-dark" data-ver="${c.id}" title="Ver el cuadre"><i class="bi bi-eye"></i></button>
                ${vendida
                    ? '<span class="hc-chip mx-1"><i class="bi bi-check-circle-fill text-success"></i> Vendida</span>'
                    : `<button class="btn btn-sm btn-hc" data-vender="${c.id}"><i class="bi bi-cash-coin"></i> Vender</button>`}
                <button class="btn btn-sm btn-outline-danger" data-eliminar="${c.id}" ${vendida ? 'disabled title="Tiene una venta registrada"' : 'title="Eliminar"'}><i class="bi bi-trash"></i></button>
            </td>
        </tr>`;
    }).join('');
}

async function eliminar(id) {
    const { isConfirmed } = await Swal.fire({
        icon: 'question',
        title: '¿Eliminar cotización?',
        showCancelButton: true,
        confirmButtonText: 'Eliminar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#b42318',
    });
    if (!isConfirmed) return;

    const r = await post('cotizaciones/eliminar', { id });
    toast.fire({ icon: r.codigo === 1 ? 'success' : 'error', title: r.mensaje });
    if (r.codigo === 1) cargarHistorial();
}

// ── Cuadre de una cotización guardada ───────────────────────────────────────
async function verCuadre(id) {
    const r = await get('cotizaciones/detalle', { id });
    if (r.codigo !== 1) {
        return Swal.fire({ icon: 'error', title: r.mensaje, confirmButtonColor: ORO });
    }

    const c = r.datos.cotizacion;
    const imp = num(r.datos.impuesto_pct);
    const ganancia = num(c.precio_sugerido) / (1 + imp / 100) - num(c.costo_total);
    const partes = r.datos.partes.map((p) =>
        `<tr><td>${esc(p.nombre)}</td><td>${esc(p.material)}</td><td class="text-end">${num(p.gramos)} g</td><td class="text-end">${tiempo(num(p.minutos))}</td></tr>`
    ).join('');

    await Swal.fire({
        titleText: c.nombre_pieza,
        width: 560,
        html: `
            <div class="text-start">
                <div class="hc-resumen">${num(c.total_gramos)} g · ${tiempo(num(c.total_minutos))} de impresión · margen ${num(c.margen_pct_usado)}%</div>
                ${trio(c.costo_total, ganancia, c.precio_sugerido)}
                <table class="hc-tabla">${filasCostos(c)}</table>
                <div class="hc-ayuda mt-3 mb-1">Partes</div>
                <table class="hc-tabla small">${partes}</table>
            </div>`,
        confirmButtonText: 'Cerrar',
        confirmButtonColor: ORO,
    });
}

// ── Registrar venta desde una cotización ────────────────────────────────────
async function abrirVenta(id) {
    const detalle = await get('cotizaciones/detalle', { id });
    if (detalle.codigo !== 1) {
        return Swal.fire({ icon: 'error', title: detalle.mensaje, confirmButtonColor: ORO });
    }

    const c = detalle.datos.cotizacion;
    const imp = num(detalle.datos.impuesto_pct);
    const costo = num(c.costo_total);
    const gananciaDe = (precio) => precio / (1 + imp / 100) - costo;
    const pideCliente = !c.cliente_id;
    const hoy = new Date().toLocaleDateString('en-CA'); // YYYY-MM-DD

    const { value: form } = await Swal.fire({
        titleText: `Registrar venta: ${c.nombre_pieza}`,
        width: 560,
        html: `
            <div class="text-start">
                <table class="hc-tabla mb-2">${filasCostos(c)}</table>
                <div id="vTrio">${trio(costo, gananciaDe(num(c.precio_sugerido)), num(c.precio_sugerido))}</div>
                <label class="form-label mb-1">Precio al que vendiste (Q)</label>
                <input id="vPrecio" type="number" min="0" step="any" class="form-control mb-2" value="${Number(c.precio_sugerido)}">
                <label class="form-label mb-1">Anticipo recibido (Q)</label>
                <input id="vAnticipo" type="number" min="0" step="any" class="form-control mb-2" value="0">
                <label class="form-label mb-1">Fecha de la venta</label>
                <input id="vFecha" type="date" class="form-control ${pideCliente ? 'mb-2' : ''}" value="${hoy}">
                ${pideCliente ? `<label class="form-label mb-1">Cliente</label><select id="vCliente" class="form-select">${opciones(clientes, 'Elige un cliente')}</select>` : ''}
            </div>`,
        didOpen: (pop) => {
            // Al cambiar el precio, la ganancia se recalcula en vivo
            pop.querySelector('#vPrecio').addEventListener('input', (e) => {
                const precio = parseFloat(e.target.value) || 0;
                pop.querySelector('#vTrio').innerHTML = trio(costo, gananciaDe(precio), precio);
            });
        },
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonText: 'Registrar venta',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: ORO,
        preConfirm: () => {
            const p = Swal.getPopup();
            const invalido = (msg) => {
                Swal.showValidationMessage(msg);
                return false;
            };
            const precio = parseFloat(p.querySelector('#vPrecio').value);
            const anticipo = parseFloat(p.querySelector('#vAnticipo').value) || 0;
            const cliente = pideCliente ? p.querySelector('#vCliente').value : c.cliente_id;

            if (!(precio > 0)) return invalido('Escribe el precio de venta');
            if (anticipo < 0 || anticipo > precio) return invalido('El anticipo no puede ser mayor al precio');
            if (!cliente) return invalido('Elige el cliente');

            return { precio_venta: precio, anticipo, fecha_venta: p.querySelector('#vFecha').value, cliente_id: cliente };
        },
    });
    if (!form) return;

    const r = await post('ventas/crear', { cotizacion_id: id, ...form });
    if (r.codigo !== 1) {
        return Swal.fire({ icon: 'error', title: 'No se pudo registrar', text: r.mensaje, confirmButtonColor: ORO });
    }
    toast.fire({
        icon: 'success',
        title: 'Venta registrada: el pedido quedó en Solicitado. Pásalo a Imprimiendo en Pedidos cuando empieces',
        timer: 5000,
    });
    cargarHistorial(); // el botón pasa a “Vendida”
}

// ── Eventos ─────────────────────────────────────────────────────────────────
raiz.addEventListener('input', (e) => {
    if (e.target.name === 'empaque') actualizarEmpaqueOtro();
    actualizarEnvioTotal();
    liberarGuardar();
    programarCalculo();
});
$('btnAgregarParte').addEventListener('click', agregarParte);
$('btnGuardar').addEventListener('click', guardar);
$('btnLimpiar').addEventListener('click', limpiar);
$('btnNuevoCliente').addEventListener('click', nuevoCliente);

$('partes').addEventListener('click', (e) => {
    const quitar = e.target.closest('.p-quitar');
    if (!quitar) return;
    if (document.querySelectorAll('.parte-fila[data-parte]').length > 1) {
        quitar.closest('.parte-fila').remove();
        programarCalculo();
    }
});

$('tablaCotizaciones').addEventListener('click', (e) => {
    const vender = e.target.closest('[data-vender]');
    const borrar = e.target.closest('[data-eliminar]');
    const ver = e.target.closest('[data-ver]');
    if (ver) verCuadre(ver.dataset.ver);
    if (vender) abrirVenta(vender.dataset.vender);
    if (borrar) eliminar(borrar.dataset.eliminar);
});

// ── Inicio ──────────────────────────────────────────────────────────────────
(async () => {
    $('resultado').innerHTML = PLACEHOLDER_RESULTADO;
    await cargarCatalogos();
    agregarParte();
    cargarHistorial();
})();
