<?php
$base = !empty($_ENV['APP_NAME']) ? '/' . $_ENV['APP_NAME'] : '';
include __DIR__ . '/../partials/hc-estilos.php';
?>

<div class="hc-header">
    <div class="icon-wrap"><i class="bi bi-box-seam-fill"></i></div>
    <div>
        <h1>Empaques</h1>
        <p>Cajas, bolsas y otros empaques con su costo. Al cotizar eliges cuál lleva cada pieza.</p>
    </div>
    <div class="hc-acciones">
        <button class="btn btn-hc" id="btnNuevo"><i class="bi bi-plus-lg me-1"></i>Nuevo empaque</button>
    </div>
</div>

<div id="appEmpaques" data-base="<?= $base ?>">
    <div class="hc-card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 hc-tabla-nw" id="tablaEmpaques">
                <thead>
                    <tr><th>Empaque</th><th class="text-end">Costo</th><th>Estado</th><th></th></tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
        <p class="hc-ayuda mt-2 mb-0">Cambiar el costo de un empaque no afecta cotizaciones ya guardadas: cada una conserva el costo que tenía. El empaque estándar (el que se usa si no eliges otro) se cambia en Costos y margen.</p>
    </div>
</div>

<script src="<?= asset('build/js/empaques/index.js') ?>" type="module"></script>
