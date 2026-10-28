<?php
$base = !empty($_ENV['APP_NAME']) ? '/' . $_ENV['APP_NAME'] : '';
include __DIR__ . '/../partials/hc-estilos.php';
?>

<div class="hc-header">
    <div class="icon-wrap"><i class="bi bi-receipt"></i></div>
    <div>
        <h1>Gastos</h1>
        <p>Todo lo que sale, con su comprobante y de dónde salió el dinero: filamento, repuestos, pintora, envíos…</p>
    </div>
    <div class="hc-acciones">
        <input type="month" id="mes" class="form-control" style="width:auto">
        <button class="btn btn-hc" id="btnNuevoGasto"><i class="bi bi-plus-lg me-1"></i>Nuevo gasto</button>
    </div>
</div>

<style>
    .caja-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: .7rem; }
    .caja-box { border-radius: 12px; padding: .7rem .95rem; border-left: 5px solid #888; background: #f4f4f1; }
    .caja-box span { display: block; font-size: .72rem; text-transform: uppercase; letter-spacing: .4px; color: #555; }
    .caja-box b { display: block; font-size: 1.7rem; line-height: 1.2; }
    .caja-box small { color: #666; font-size: .76rem; }
    .caja-box.k { background: color-mix(in srgb, var(--c) 9%, #fff); border-color: var(--c); }
    .caja-box.k b { color: var(--c); font-size: 1.9rem; }
    .caja-box.k span { color: var(--c); font-weight: 600; }
    .caja-box.k span i { font-size: 1.05rem; margin-right: .4rem; }
    .caja-box.azul { background: #e8f0fb; border-color: #1d5fa8; } .caja-box.azul b { color: #1d5fa8; }
    .caja-box.ambar { background: #fff4e0; border-color: #b26a00; } .caja-box.ambar b { color: #b26a00; }
    .caja-box.verde { background: #e8f6ee; border-color: #117a43; } .caja-box.verde b { color: #117a43; font-size: 2rem; }
    .caja-box.rojo { background: #fdecea; border-color: #b42318; } .caja-box.rojo b { color: #b42318; font-size: 2rem; }
    .est { display: inline-block; padding: .12rem .6rem; border-radius: 999px; font-size: .75rem; font-weight: 600; }
    .est-ok { background: #e8f6ee; color: #117a43; }
    .est-bajo { background: #fff4e0; color: #b26a00; }
    .est-sin { background: #fdecea; color: #b42318; }
    .txt-ok { color: #117a43; } .txt-bajo { color: #b26a00; } .txt-sin { color: #b42318; }
    .pieza-card { border: 1px solid var(--pc); border-left: 6px solid var(--pc); border-radius: 12px; margin-bottom: .75rem; background: #fff; overflow: hidden; }
    .pieza-head { background: color-mix(in srgb, var(--pc) 12%, #fff); padding: .6rem .9rem; display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; }
    .pieza-head small { display: block; font-size: .68rem; letter-spacing: .5px; color: #666; }
    .pieza-head b { font-size: 1.2rem; color: var(--pc); text-transform: uppercase; }
    .pieza-linea { display: flex; gap: .75rem; align-items: center; padding: .55rem .9rem; border-top: 1px solid #eee; flex-wrap: wrap; }
    .cpt { padding: .15rem .6rem; border-radius: 999px; font-size: .75rem; font-weight: 700; min-width: 96px; text-align: center; }
    .cpt-pintura { background: #efe7fb; color: #5b2fb0; }
    .cpt-envio { background: #e3effd; color: #1d5fa8; }
    .cpt-otros { background: #fff1d6; color: #9a5b00; }
    .pieza-falta { color: #b26a00; font-size: 1.1rem; min-width: 90px; text-align: right; }
</style>

<div id="appGastos" data-base="<?= $base ?>">
    <div class="hc-card mb-3">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2">
            <h2 class="mb-0"><i class="bi bi-safe2 me-2"></i>Caja del negocio</h2>
            <div class="d-flex gap-2">
                <button class="btn btn-sm btn-hc" id="btnAporte"><i class="bi bi-plus-circle me-1"></i>Poner dinero</button>
                <button class="btn btn-sm btn-outline-dark" id="btnRetiro"><i class="bi bi-dash-circle me-1"></i>Sacar dinero</button>
                <button class="btn btn-sm btn-outline-primary" id="btnConciliar"><i class="bi bi-bank me-1"></i>Conciliar con el banco</button>
            </div>
        </div>
        <div id="cajaKpis"></div>

        <div id="conciliar" class="d-none mt-3 p-3" style="border:2px solid #1d5fa8;border-radius:14px;background:#f5f9ff">
            <h3 class="h5 mb-1"><i class="bi bi-bank me-2"></i>Conciliar con el banco</h3>
            <div class="hc-ayuda mb-3">Compara la caja de la app con el saldo real de tu cuenta. Marca los movimientos que ya ves en tu estado de cuenta y deja <b>sin marcar</b> los que anotaste en la app pero todavía no pasan por el banco. Lo que quede sin explicar es dinero que se movió en el banco sin registrarlo aquí.</div>
            <div class="row g-2 mb-3">
                <div class="col-md-4"><label class="form-label mb-1" for="cSaldo">Saldo que ves en tu banco (Q)</label>
                    <input type="number" step="any" id="cSaldo" class="form-control" placeholder="Ej. 440.00"></div>
                <div class="col-md-4"><label class="form-label mb-1" for="cFecha">Fecha del saldo</label>
                    <input type="date" id="cFecha" class="form-control"></div>
                <div class="col-md-4"><label class="form-label mb-1" for="cDesde">Revisar movimientos desde</label>
                    <input type="date" id="cDesde" class="form-control"></div>
            </div>
            <div class="d-flex justify-content-between align-items-center mb-1">
                <b>Movimientos de la caja que pasan por el banco</b>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="cTodos">Marcar todos</button>
            </div>
            <div class="table-responsive mb-3" style="max-height:320px;overflow:auto">
                <table class="table table-sm align-middle mb-0 hc-tabla-nw" id="tablaConc">
                    <thead><tr><th class="text-center">Está en mi banco</th><th>Fecha</th><th>Tipo</th><th>Detalle</th><th class="text-end">Monto</th></tr></thead>
                    <tbody></tbody>
                </table>
            </div>
            <div id="cResultado"></div>
            <div id="cNotaWrap" class="d-none mt-2">
                <label class="form-label mb-1" for="cNota">Nota: qué crees que pasó con lo que no cuadra (obligatoria)</label>
                <input id="cNota" class="form-control" maxlength="500" placeholder="Ej. Faltan 2 envíos de Q40 pagados con la tarjeta; los registro hoy">
            </div>
            <div class="mt-3 d-flex gap-2">
                <button class="btn btn-hc" id="btnGuardarConc"><i class="bi bi-check2-circle me-1"></i>Guardar conciliación</button>
            </div>
            <h4 class="h6 mt-4 mb-2">Conciliaciones anteriores</h4>
            <div id="cHistorial"></div>
        </div>
        <div class="table-responsive mt-3">
            <table class="table table-sm align-middle mb-0 hc-tabla-nw" id="tablaAportes">
                <thead><tr><th>Fecha</th><th>Movimiento</th><th>Detalle</th><th class="text-end">Monto</th><th></th></tr></thead>
                <tbody></tbody>
            </table>
        </div>
    </div>

    <div class="hc-card mb-3">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2">
            <h2 class="mb-0"><i class="bi bi-box-seam me-2"></i>Inventario de filamento <span class="hc-ayuda fw-normal">en kg y en dinero</span></h2>
            <div class="d-flex gap-2">
                <button class="btn btn-sm btn-hc" id="btnComprar"><i class="bi bi-cart-plus me-1"></i>Comprar filamento</button>
                <button class="btn btn-sm btn-outline-dark" id="btnInicial" title="Filamento que ya tenías antes de usar la app"><i class="bi bi-box-arrow-in-down me-1"></i>Ya tenía filamento</button>
            </div>
        </div>
        <div id="inventario"></div>
    </div>

    <div class="hc-card mb-3">
        <h2><i class="bi bi-palette me-2"></i>Pagos pendientes de las piezas <span class="hc-ayuda fw-normal">pintora · envíos · otros insumos</span></h2>
        <div id="pintora"></div>
    </div>

    <div class="hc-kpis" id="kpis"></div>
    <div class="hc-card">
        <div class="mb-3" id="porCategoria"></div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 hc-tabla-nw" id="tablaGastos">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Categoría</th>
                        <th>Descripción</th>
                        <th>Venta</th>
                        <th>Pagado con</th>
                        <th class="text-end">Monto</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
        <p class="hc-ayuda mt-2 mb-0">Los gastos no se borran: se editan y queda guardado cómo estaban antes. Los pagos de una pieza (pintora, envío, otros) se registran en “Pagos pendientes”; el filamento, en el inventario.</p>
    </div>
</div>

<script src="<?= asset('build/js/gastos/index.js') ?>" type="module"></script>
