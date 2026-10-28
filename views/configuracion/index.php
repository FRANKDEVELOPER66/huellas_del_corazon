<?php
$base = !empty($_ENV['APP_NAME']) ? '/' . $_ENV['APP_NAME'] : '';
include __DIR__ . '/../partials/hc-estilos.php';
?>

<div class="hc-header">
    <div class="icon-wrap"><i class="bi bi-sliders"></i></div>
    <div>
        <h1>Costos y margen</h1>
        <p>Los números con los que se calcula el precio de cada pieza</p>
    </div>
</div>

<div id="appConfig" data-base="<?= $base ?>">
    <div class="row justify-content-center">
        <div class="col-lg-8 col-xl-6">
            <div class="hc-card">
                <div class="hc-ayuda mb-3" id="vigenteDesde"></div>
                <div class="row g-3" id="campos"></div>
                <div class="d-grid mt-4">
                    <button class="btn btn-hc" id="btnGuardar"><i class="bi bi-save me-1"></i>Guardar cambios</button>
                </div>
                <p class="hc-ayuda mt-3 mb-0">Los cambios aplican a las cotizaciones nuevas. Las que ya guardaste conservan los costos con los que se calcularon.</p>
            </div>
        </div>
    </div>
</div>

<script src="<?= asset('build/js/configuracion/index.js') ?>" type="module"></script>
