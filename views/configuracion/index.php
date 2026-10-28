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
        <div class="col-lg-8 col-xl-6 mt-3">
            <div class="hc-card" style="border-left:5px solid #6d4c41">
                <h2 class="h5 mb-1"><i class="bi bi-tools me-2" style="color:#6d4c41"></i>Reserva para repuestos y equipo</h2>
                <div class="hc-ayuda mb-3">Dinero de tu ganancia que se queda en el negocio para cuando algo falle o quieras ampliar la impresora (repuestos, AMS, herramientas). No lo puedes retirar hasta completar esta meta. Si compras algo, sale de la reserva y las próximas ganancias la vuelven a llenar.</div>
                <label class="form-label mb-1" for="reservaMeta">Meta de la reserva (Q)</label>
                <div class="input-group mb-2">
                    <input type="number" step="any" min="0" id="reservaMeta" class="form-control">
                    <button class="btn btn-hc" id="btnReserva"><i class="bi bi-save me-1"></i>Guardar meta</button>
                </div>
                <div id="reservaEstado" class="hc-ayuda"></div>
            </div>
        </div>
    </div>
</div>

<script src="<?= asset('build/js/configuracion/index.js') ?>" type="module"></script>
