<?php
$base = !empty($_ENV['APP_NAME']) ? '/' . $_ENV['APP_NAME'] : '';
include __DIR__ . '/../partials/hc-estilos.php';
?>

<style>
    /* Colores con significado: azul = ventas · rojo = costo · verde = ganancia · morado = gastos de operación · ámbar = material nuevo */
    .hc-kpi.azul { border-left-color: #1d5fa8; }
    .hc-kpi.rojo { border-left-color: #b42318; }
    .hc-kpi.verde { border-left-color: #117a43; }
    .hc-kpi.morado { border-left-color: #6b3fa0; }
    .hc-kpi.ambar { border-left-color: #b26a00; }

    .est-fila {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 1rem;
        padding: .6rem .85rem;
        border-radius: 10px;
        margin-bottom: .35rem;
    }
    .est-fila .tit { font-weight: 700; }
    .est-fila .exp { display: block; font-size: .78rem; font-weight: 400; color: #555; }
    .est-fila .monto { font-weight: 800; font-size: 1.1rem; white-space: nowrap; }
    .est-azul { background: #e8f0fb; border-left: 5px solid #1d5fa8; }
    .est-azul .monto { color: #1d5fa8; }
    .est-rojo { background: #fdecea; border-left: 5px solid #b42318; }
    .est-rojo .monto { color: #b42318; }
    .est-verde { background: #e8f6ee; border-left: 5px solid #117a43; }
    .est-verde .monto { color: #117a43; }
    .est-morado { background: #f1eafa; border-left: 5px solid #6b3fa0; }
    .est-morado .monto { color: #6b3fa0; }
    .est-ambar { background: #fff4e0; border-left: 5px solid #b26a00; }
    .est-ambar .monto { color: #b26a00; }
    .est-gris { background: #f4f4f1; border-left: 5px solid #888; }
    .est-total .monto { font-size: 1.6rem; }
    .est-sub {
        display: flex;
        justify-content: space-between;
        padding: .12rem .85rem .12rem 1.7rem;
        font-size: .88rem;
        color: #444;
    }

    .apilada { display: flex; height: 30px; border-radius: 8px; overflow: hidden; background: #eee; }
    .apilada div { display: flex; align-items: center; justify-content: center; color: #fff; font-size: .75rem; font-weight: 700; min-width: 0; overflow: hidden; }
    .leyenda { display: flex; flex-wrap: wrap; gap: .5rem 1rem; font-size: .8rem; margin-top: .4rem; }
    .leyenda i { display: inline-block; width: 11px; height: 11px; border-radius: 3px; margin-right: .3rem; }
</style>

<div class="hc-header">
    <div class="icon-wrap"><i class="bi bi-graph-up-arrow"></i></div>
    <div>
        <h1>Resultados y balance</h1>
        <p>Cuánto ganas de verdad, en qué se va el dinero y qué tienes en el negocio</p>
    </div>
    <div class="hc-acciones">
        <input type="month" id="mes" class="form-control" style="width:auto">
    </div>
</div>

<div id="appBalance" data-base="<?= $base ?>">
    <h5 class="mb-2" id="tituloMes"></h5>
    <div class="hc-kpis" id="kpis"></div>

    <div class="row g-3 mb-3">
        <div class="col-xl-7">
            <div class="hc-card h-100">
                <h2><i class="bi bi-receipt-cutoff me-2"></i>Estado de resultados</h2>
                <p class="hc-ayuda">Una venta cuenta en el mes en que la <b>entregas</b>. Así se ve cuánto ganaste con lo que realmente terminaste.</p>
                <div id="resultados"></div>
            </div>
        </div>
        <div class="col-xl-5">
            <div class="hc-card mb-3">
                <h2><i class="bi bi-pie-chart me-2"></i>De cada Q100 que vendes</h2>
                <div id="apilada"></div>
            </div>
            <div class="hc-card mb-3">
                <h2><i class="bi bi-boxes me-2"></i>Material nuevo (filamento)</h2>
                <div id="material"></div>
            </div>
            <div class="hc-card">
                <h2><i class="bi bi-hourglass-split me-2"></i>En producción ahora</h2>
                <div id="produccion"></div>
            </div>
        </div>
    </div>

    <div class="hc-card mb-3">
        <h2><i class="bi bi-box-seam me-2"></i>Piezas entregadas este mes</h2>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0 hc-tabla-nw" id="tablaPiezas">
                <thead><tr><th>Entrega</th><th>Pieza</th><th class="text-end">Vendida en</th><th class="text-end">Costo de fabricación</th><th class="text-end">Ganancia</th></tr></thead>
                <tbody></tbody>
            </table>
        </div>
    </div>

    <div class="hc-card mb-3">
        <h2><i class="bi bi-bank me-2"></i>Balance al cierre del mes</h2>
        <div id="balance"></div>
    </div>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="hc-card h-100">
                <h2><i class="bi bi-calendar3 me-2"></i>Resultados mes a mes</h2>
                <p class="hc-ayuda">Toca un mes para verlo arriba.</p>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0 hc-tabla-nw" id="tablaMeses">
                        <thead><tr><th>Mes</th><th class="text-end">Ventas</th><th class="text-end">Costo</th><th class="text-end">Gastos op.</th><th class="text-end">Utilidad neta</th><th class="text-end">Margen</th></tr></thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="hc-card h-100">
                <h2><i class="bi bi-wallet2 me-2"></i>Flujo de caja</h2>
                <p class="hc-ayuda">Dinero que de verdad entró (cobros) y salió (pagos), con el acumulado.</p>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0 hc-tabla-nw" id="tablaFlujo">
                        <thead><tr><th>Mes</th><th class="text-end">Cobros</th><th class="text-end">Pagos</th><th class="text-end">Acumulado</th></tr></thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="<?= asset('build/js/dashboard/index.js') ?>" type="module"></script>
