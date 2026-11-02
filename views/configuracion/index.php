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
                <label class="form-label mb-1 mt-3" for="capitalMultiplo">Devolver capital cuando el negocio valga (veces tu inversión)</label>
                <input type="number" step="any" min="1" id="capitalMultiplo" class="form-control mb-1">
                <div class="hc-ayuda mb-2">Con 3, el botón “Devolver capital” se activa cuando el patrimonio del negocio sea 3 veces lo que has puesto. Se guarda con el mismo botón.</div>
                <div id="reservaEstado" class="hc-ayuda"></div>
            </div>
        </div>

        <div class="col-lg-8 col-xl-6 mt-3">
            <div class="hc-card" style="border-left:5px solid #d6336c">
                <h2 class="h5 mb-1"><i class="bi bi-exclamation-triangle-fill me-2" style="color:#d6336c"></i>Aviso de poco filamento</h2>
                <div class="hc-ayuda mb-3">Cuando a un color le queden menos gramos que esto (sumando todos sus carretes), la app te avisa al mandar a imprimir y lo muestra en Pedidos y en el inventario.</div>
                <label class="form-label mb-1" for="alertaG">Avisar cuando queden menos de (gramos)</label>
                <div class="input-group">
                    <input type="number" step="1" min="0" id="alertaG" class="form-control">
                    <button class="btn btn-hc" id="btnAlerta"><i class="bi bi-save me-1"></i>Guardar aviso</button>
                </div>
            </div>
        </div>

        <div class="col-lg-8 col-xl-6 mt-3">
            <div class="hc-card" style="border-left:5px solid #b8860b">
                <h2 class="h5 mb-1"><i class="bi bi-file-earmark-pdf me-2" style="color:#b8860b"></i>Proforma para tus clientes</h2>
                <div class="hc-ayuda mb-3">Estos datos salen en la proforma (el documento que le mandas al cliente desde Cotizar, botón <i class="bi bi-file-earmark-pdf"></i>). No es una factura y nunca muestra tus costos ni tu ganancia.</div>
                <div class="row g-2">
                    <div class="col-md-6"><label class="form-label mb-1" for="pfNegocio">Nombre del negocio</label><input id="pfNegocio" class="form-control" maxlength="80"></div>
                    <div class="col-md-6"><label class="form-label mb-1" for="pfContacto">Contacto (teléfono / WhatsApp / correo)</label><input id="pfContacto" class="form-control" maxlength="160" placeholder="WhatsApp 5555-5555 · correo@ejemplo.com"></div>
                    <div class="col-12"><label class="form-label mb-1" for="pfLema">Frase debajo del nombre</label><input id="pfLema" class="form-control" maxlength="120"></div>
                    <div class="col-12"><label class="form-label mb-1" for="pfIncluye">Qué incluye el precio</label><input id="pfIncluye" class="form-control" maxlength="200"></div>
                    <div class="col-md-4"><label class="form-label mb-1" for="pfAnticipo">Anticipo (%)</label><input id="pfAnticipo" type="number" min="0" max="100" step="any" class="form-control"></div>
                    <div class="col-md-4"><label class="form-label mb-1" for="pfValidez">Validez (días)</label><input id="pfValidez" type="number" min="1" max="365" step="1" class="form-control"></div>
                    <div class="col-md-4"><label class="form-label mb-1" for="pfEntrega">Tiempo de entrega</label><input id="pfEntrega" class="form-control" maxlength="160" placeholder="Ej. 10 a 15 días después del anticipo"></div>
                    <div class="col-12"><label class="form-label mb-1" for="pfPago">Forma de pago (cuenta para transferir)</label><input id="pfPago" class="form-control" maxlength="255" placeholder="Transferencia Banrural, cuenta 000-000000-0, a nombre de …"></div>
                    <div class="col-12"><label class="form-label mb-1" for="pfCondiciones">Condiciones</label><input id="pfCondiciones" class="form-control" maxlength="255"></div>
                </div>
                <button class="btn btn-hc mt-3" id="btnProforma"><i class="bi bi-save me-1"></i>Guardar datos de la proforma</button>
            </div>
        </div>
    </div>
</div>

<script src="<?= asset('build/js/configuracion/index.js') ?>" type="module"></script>
