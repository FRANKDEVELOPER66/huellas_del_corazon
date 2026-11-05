<?php
$base = !empty($_ENV['APP_NAME']) ? '/' . $_ENV['APP_NAME'] : '';
include __DIR__ . '/../partials/hc-estilos.php';
include __DIR__ . '/../partials/gastos-estilos.php';
?>

<div class="hc-header">
    <div class="icon-wrap"><i class="bi bi-hypnotize"></i></div>
    <div>
        <h1>Inventario de filamento</h1>
        <p>Solo filamento: cuántos kg y cuántos carretes tienes, de qué color, y qué compraste. El dinero (caja, gastos, pagos) está en <a href="<?= $base ?>/gastos" style="color:#f6c33b"><b>Gastos</b></a>.</p>
    </div>
    <div class="hc-acciones">
        <button class="btn btn-hc" id="btnComprar"><i class="bi bi-cart-plus me-1"></i>Comprar filamento</button>
        <a class="btn btn-outline-light" id="lnkMateriales" href="#" title="Agregar o editar materiales y su costo por kilo"><i class="bi bi-layers me-1"></i>Materiales</a>
        <button class="btn btn-outline-light" id="btnInicial" title="Filamento que ya tenías antes de usar la app"><i class="bi bi-box-arrow-in-down me-1"></i>Ya tenía filamento</button>
    </div>
</div>

<div id="appGastos" data-base="<?= $base ?>" data-vista="inventario">
    <div class="hc-card mb-3">
        <div id="inventario"></div>
    </div>
</div>

<script src="<?= asset('build/js/gastos/index.js') ?>" type="module"></script>
