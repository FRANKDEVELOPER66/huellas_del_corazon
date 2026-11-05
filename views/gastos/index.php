<?php
$base = !empty($_ENV['APP_NAME']) ? '/' . $_ENV['APP_NAME'] : '';
include __DIR__ . '/../partials/hc-estilos.php';
?>

<div class="hc-header">
    <div class="icon-wrap"><i class="bi bi-receipt"></i></div>
    <div>
        <h1>Gastos</h1>
        <p>Solo dinero: lo que <b>sale</b> del negocio (gastos, pintora, envíos…), con su comprobante y de dónde salió. El filamento se maneja en <a href="<?= $base ?>/inventario" style="color:#f6c33b"><b>Inventario</b></a>.</p>
    </div>
    <div class="hc-acciones">
        <input type="month" id="mes" class="form-control" style="width:auto">
        <button class="btn btn-hc" id="btnNuevoGasto"><i class="bi bi-plus-lg me-1"></i>Nuevo gasto</button>
    </div>
</div>

<?php include __DIR__ . '/../partials/gastos-estilos.php'; ?>

<div id="appGastos" data-base="<?= $base ?>" data-vista="gastos">
    <div id="franjaInv" class="mb-3 p-2 px-3" style="border:1px solid #0b7285;border-left:6px solid #0b7285;border-radius:10px;background:#e8f6f8"></div>
    <div class="hc-card mb-3">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2">
            <h2 class="mb-0"><i class="bi bi-safe2 me-2"></i>Caja del negocio</h2>
            <div class="d-flex gap-2">
                <button class="btn btn-sm btn-hc" id="btnAporte"><i class="bi bi-plus-circle me-1"></i>Poner dinero</button>
                <button class="btn btn-sm btn-outline-dark" id="btnRetiro"><i class="bi bi-dash-circle me-1"></i>Sacar dinero</button>
                <button class="btn btn-sm btn-outline-success" id="btnCapital" disabled title="Se activa cuando el negocio valga varias veces tu inversión"><i class="bi bi-piggy-bank me-1"></i>Devolver capital</button>
                <button class="btn btn-sm btn-outline-primary" id="btnConciliar"><i class="bi bi-lock me-1"></i>Cerrar período</button>
            </div>
        </div>
        <div id="cajaKpis"></div>

        <div id="conciliar" class="d-none mt-3 p-3" style="border:2px solid #1d5fa8;border-radius:14px;background:#f5f9ff">
            <h3 class="h5 mb-1"><i class="bi bi-lock me-2"></i>Cerrar período · conciliar con el banco</h3>
            <div class="hc-ayuda mb-2">Elige hasta qué fecha cierras (fin de semana, de quincena o de mes), compara la caja de la app con el saldo real de tu banco en esa fecha y sube la captura. Al cerrar, <b>todo lo que tenga fecha hasta ese día queda bloqueado</b> y el mes se marca como <b>conciliado</b> en Resultados y balance.</div>
            <div id="cEstado" class="mb-3"></div>
            <div class="row g-2 mb-3">
                <div class="col-md-4"><label class="form-label mb-1" for="cHasta">Cerrar hasta (inclusive)</label>
                    <input type="date" id="cHasta" class="form-control"></div>
                <div class="col-md-4"><label class="form-label mb-1" for="cSaldo">Saldo de tu banco en esa fecha (Q)</label>
                    <input type="number" step="any" id="cSaldo" class="form-control" placeholder="Ej. 440.00"></div>
                <div class="col-md-4"><label class="form-label mb-1" for="cArchivo">Captura o PDF del banco (obligatoria)</label>
                    <input type="file" id="cArchivo" class="form-control" accept="image/*,application/pdf"></div>
            </div>
            <div id="cAviso"></div>
            <div class="d-flex justify-content-between align-items-center mb-1">
                <b>Movimientos del período que pasan por el banco</b>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="cTodos">Marcar todos</button>
            </div>
            <div class="hc-ayuda mb-1">Marca los que ya ves en tu estado de cuenta. Deja <b>sin marcar</b> los que anotaste pero todavía no pasan por el banco: se seguirán mostrando en el próximo cierre.</div>
            <div class="table-responsive mb-3" style="max-height:320px;overflow:auto">
                <table class="table table-sm align-middle mb-0 hc-tabla-nw" id="tablaConc">
                    <thead><tr><th class="text-center">Está en mi banco</th><th>Fecha</th><th>Tipo</th><th>Detalle</th><th class="text-end">Monto</th></tr></thead>
                    <tbody></tbody>
                </table>
            </div>
            <div id="cResultado"></div>
            <div id="cNotaWrap" class="d-none mt-2">
                <label class="form-label mb-1" for="cNota">Nota: qué crees que pasó con lo que no cuadra (obligatoria)</label>
                <input id="cNota" class="form-control" maxlength="500" placeholder="Ej. Faltan 2 envíos de Q40 pagados con la tarjeta; los registro hoy">
            </div>
            <div class="mt-3 d-flex gap-2">
                <button class="btn btn-hc" id="btnGuardarConc"><i class="bi bi-lock-fill me-1"></i>Cerrar y bloquear período</button>
            </div>
            <h4 class="h6 mt-4 mb-2">Cierres anteriores</h4>
            <div id="cHistorial"></div>
        </div>
        <h3 class="h5 mt-4 mb-1"><i class="bi bi-piggy-bank me-2" style="color:#087f5b"></i>Tu dinero en el negocio <span class="hc-ayuda fw-normal">lo que tú pones y sacas</span></h3>
        <div class="hc-ayuda">No son gastos: es dinero <b>tuyo</b> que entra (“Pusiste”) o sale (“Sacaste”) de la caja. Una compra pagada “De mi bolsillo” aparece aquí como “Pusiste” y en <b>Gastos</b> como lo que se compró: es la misma compra vista de dos lados, no se cuenta doble.</div>
        <div class="table-responsive mt-2">
            <table class="table table-sm align-middle mb-0 hc-tabla-nw" id="tablaAportes">
                <thead><tr><th>Fecha</th><th>Movimiento</th><th>Detalle</th><th class="text-end">Monto</th><th></th></tr></thead>
                <tbody></tbody>
            </table>
        </div>
    </div>

    <div class="hc-card mb-3" id="pagos-pendientes" style="scroll-margin-top:90px">
        <h2><i class="bi bi-palette me-2"></i>Pagos pendientes de las piezas <span class="hc-ayuda fw-normal">pintora · envíos · otros insumos</span></h2>
        <div id="pintora"></div>
    </div>

    <div class="hc-card">
        <h2 class="mb-2"><i class="bi bi-receipt me-2"></i>Gastos <span class="hc-ayuda fw-normal">lo que sale del negocio en el período</span></h2>
        <div class="hc-kpis" id="kpis"></div>
        <div class="mb-3" id="porCategoria"></div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 hc-tabla-nw" id="tablaGastos">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Categoría</th>
                        <th>Descripción</th>
                        <th>Venta</th>
                        <th>Pagado con</th>
                        <th class="text-end">Monto</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
        <p class="hc-ayuda mt-2 mb-0">Los gastos no se borran: se editan y queda guardado cómo estaban antes. Los pagos de una pieza (pintora, envío, otros) se registran en “Pagos pendientes”; el filamento, en Inventario (aquí solo ves lo que costó).</p>
    </div>
</div>

<script src="<?= asset('build/js/gastos/index.js') ?>" type="module"></script>
