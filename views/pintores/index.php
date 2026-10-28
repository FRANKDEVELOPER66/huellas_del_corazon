<?php
$base = !empty($_ENV['APP_NAME']) ? '/' . $_ENV['APP_NAME'] : '';
include __DIR__ . '/../partials/hc-estilos.php';
?>

<div class="hc-header">
    <div class="icon-wrap"><i class="bi bi-palette-fill"></i></div>
    <div>
        <h1>Pintores y tarifas</h1>
        <p>Lo que cobra cada pintor según el alto de la pieza. Al cotizar, la pintura se llena sola con estas tarifas.</p>
    </div>
    <div class="hc-acciones">
        <button class="btn btn-hc" id="btnNuevoPintor"><i class="bi bi-plus-lg me-1"></i>Nuevo pintor</button>
    </div>
</div>

<div id="appPintores" data-base="<?= $base ?>">
    <div class="row g-3" id="pintoresGrid"></div>
</div>

<script src="<?= asset('build/js/pintores/index.js') ?>" type="module"></script>
