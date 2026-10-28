<?php
$base = !empty($_ENV['APP_NAME']) ? '/' . $_ENV['APP_NAME'] : '';
include __DIR__ . '/../partials/hc-estilos.php';
?>

<div class="hc-header">
    <div class="icon-wrap"><i class="bi bi-people-fill"></i></div>
    <div>
        <h1>Clientes</h1>
        <p>Quién te compra y cómo contactarlo</p>
    </div>
    <div class="hc-acciones">
        <input type="text" id="buscar" class="form-control" style="width:240px" placeholder="Buscar nombre o teléfono…">
        <button class="btn btn-hc" id="btnNuevo"><i class="bi bi-person-plus-fill me-1"></i>Nuevo cliente</button>
    </div>
</div>

<div id="appClientes" data-base="<?= $base ?>">
    <div class="hc-card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 hc-tabla-nw" id="tablaClientes">
                <thead>
                    <tr><th>Nombre</th><th>Teléfono</th><th>Notas</th><th></th></tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>

<script src="<?= asset('build/js/clientes/index.js') ?>" type="module"></script>
