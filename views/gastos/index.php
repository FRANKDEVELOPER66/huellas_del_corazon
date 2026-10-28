<?php
$base = !empty($_ENV['APP_NAME']) ? '/' . $_ENV['APP_NAME'] : '';
include __DIR__ . '/../partials/hc-estilos.php';
?>

<div class="hc-header">
    <div class="icon-wrap"><i class="bi bi-receipt"></i></div>
    <div>
        <h1>Gastos</h1>
        <p>Todo lo que sale: filamento, luz, repuestos, pintora, envíos…</p>
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
    .caja-box.azul { background: #e8f0fb; border-color: #1d5fa8; } .caja-box.azul b { color: #1d5fa8; }
    .caja-box.ambar { background: #fff4e0; border-color: #b26a00; } .caja-box.ambar b { color: #b26a00; }
    .caja-box.verde { background: #e8f6ee; border-color: #117a43; } .caja-box.verde b { color: #117a43; font-size: 2rem; }
    .caja-box.rojo { background: #fdecea; border-color: #b42318; } .caja-box.rojo b { color: #b42318; font-size: 2rem; }
</style>

<div id="appGastos" data-base="<?= $base ?>">
    <div class="hc-card mb-3">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2">
            <h2 class="mb-0"><i class="bi bi-safe2 me-2"></i>Caja del negocio</h2>
            <div class="d-flex gap-2">
                <button class="btn btn-sm btn-hc" id="btnAporte"><i class="bi bi-plus-circle me-1"></i>Poner dinero</button>
                <button class="btn btn-sm btn-outline-dark" id="btnRetiro"><i class="bi bi-dash-circle me-1"></i>Sacar dinero</button>
            </div>
        </div>
        <div id="cajaKpis"></div>
        <div class="table-responsive mt-3">
            <table class="table table-sm align-middle mb-0 hc-tabla-nw" id="tablaAportes">
                <thead><tr><th>Fecha</th><th>Movimiento</th><th>Detalle</th><th class="text-end">Monto</th><th></th></tr></thead>
                <tbody></tbody>
            </table>
        </div>
    </div>

    <div class="hc-card mb-3">
        <h2><i class="bi bi-palette me-2"></i>Pagos a la pintora</h2>
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
                        <th class="text-end">Monto</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
        <p class="hc-ayuda mt-2 mb-0">Los gastos de una pieza (pintora, envío) se agregan desde el detalle de la venta, así quedan ligados a ella.</p>
    </div>
</div>

<script src="<?= asset('build/js/gastos/index.js') ?>" type="module"></script>
