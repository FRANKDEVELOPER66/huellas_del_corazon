<?php
$base = !empty($_ENV['APP_NAME']) ? '/' . $_ENV['APP_NAME'] : '';
include __DIR__ . '/../partials/hc-estilos.php';
?>

<div class="hc-header">
    <div class="icon-wrap"><i class="bi bi-cash-coin"></i></div>
    <div>
        <h1>Ventas</h1>
        <p>Cuadro real de cada pieza: lo estimado contra lo que de verdad te quedó</p>
    </div>
    <div class="hc-acciones">
        <input type="month" id="mes" class="form-control" style="width:auto">
        <a href="<?= $base ?>/cotizaciones" class="btn btn-hc"><i class="bi bi-plus-lg me-1"></i>Nueva venta</a>
    </div>
</div>

<div id="appVentas" data-base="<?= $base ?>">
    <div class="hc-kpis" id="kpis"></div>
    <div class="hc-card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 hc-tabla-nw" id="tablaVentas">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Pieza</th>
                        <th>Cliente</th>
                        <th class="text-end">Precio</th>
                        <th class="text-end">Cobrado</th>
                        <th class="text-end">Saldo</th>
                        <th class="text-end">Gan. estimada</th>
                        <th class="text-end">Gan. real</th>
                        <th class="text-end">Margen</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
        <p class="hc-ayuda mt-2 mb-0">Mientras no registres los gastos de una pieza (pintora, envío), su ganancia usa los costos de la cotización y aparece marcada como “est.”. Ábrela con “Detalle” y usa “Agregar gasto” con lo que de verdad pagaste, y se vuelve real.</p>
    </div>
</div>

<script src="<?= asset('build/js/ventas/index.js') ?>" type="module"></script>
