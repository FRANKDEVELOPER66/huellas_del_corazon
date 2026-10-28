<?php
$base = !empty($_ENV['APP_NAME']) ? '/' . $_ENV['APP_NAME'] : '';
include __DIR__ . '/../partials/hc-estilos.php';
?>

<div class="hc-header">
    <div class="icon-wrap"><i class="bi bi-layers-fill"></i></div>
    <div>
        <h1>Materiales</h1>
        <p>Tus filamentos y lo que pagas por kilo. Se usan al cotizar cada parte de la pieza.</p>
    </div>
    <div class="hc-acciones">
        <button class="btn btn-hc" id="btnNuevo"><i class="bi bi-plus-lg me-1"></i>Nuevo material</button>
    </div>
</div>

<div id="appMateriales" data-base="<?= $base ?>">
    <div class="hc-card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 hc-tabla-nw" id="tablaMateriales">
                <thead>
                    <tr><th>Material</th><th class="text-end">Costo por kg</th><th>Estado</th><th></th></tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
        <p class="hc-ayuda mt-2 mb-0">Si un material ya se usó en cotizaciones no se puede eliminar: desactívalo y deja de aparecer al cotizar.</p>
    </div>
</div>

<script src="<?= asset('build/js/materiales/index.js') ?>" type="module"></script>
