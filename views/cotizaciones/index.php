<?php
$base = !empty($_ENV['APP_NAME']) ? '/' . $_ENV['APP_NAME'] : '';
?>

<style>
    .hc-header {
        background: linear-gradient(135deg, #111, #2b2b2b);
        border-left: 4px solid var(--hc-dorado);
        border-radius: 14px;
        padding: 1.25rem 1.75rem;
        margin-bottom: 1.25rem;
        display: flex;
        align-items: center;
        gap: 1.1rem;
        color: #fff;
    }

    .hc-header .icon-wrap {
        background: rgba(246, 195, 59, .15);
        border: 1px solid rgba(246, 195, 59, .35);
        border-radius: 12px;
        width: 52px;
        height: 52px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
        color: var(--hc-dorado);
        flex-shrink: 0;
    }

    .hc-header h1 {
        font-size: 1.5rem;
        font-weight: 700;
        margin: 0;
    }

    .hc-header p {
        font-size: .85rem;
        color: #b8b8b8;
        margin: .15rem 0 0;
    }

    .hc-card {
        background: #fff;
        border: 1px solid var(--hc-borde);
        border-top: 4px solid var(--hc-dorado);
        border-radius: 14px;
        padding: 1.1rem 1.25rem;
    }

    .hc-card h2 {
        font-size: 1.05rem;
        font-weight: 700;
        margin: 0 0 .75rem;
    }

    .hc-sticky {
        position: sticky;
        top: 1rem;
    }

    /* Barra fija con el total: aparece cuando el Resultado queda fuera de la pantalla (al llenar las partes abajo) */
    .barra-total {
        position: fixed;
        left: 0; right: 0; bottom: 0;
        z-index: 1030;
        background: #1c1c1c;
        color: #fff;
        padding: 1rem 1.5rem;
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: center;
        gap: .6rem 3rem;
        box-shadow: 0 -4px 14px rgba(0,0,0,.25);
    }
    .barra-total.d-none { display: none; }
    .barra-total small { display: block; opacity: .75; font-size: .85rem; text-transform: uppercase; letter-spacing: .06em; }
    .barra-total b { font-size: 1.9rem; line-height: 1.15; }
    .barra-total .bt-total b { font-size: 2.6rem; color: #f6c33b; }
    .barra-total .bt-gan b { color: #6fd08c; }

    .empaque-op {
        cursor: pointer;
        user-select: none;
    }

    .empaque-op input {
        position: absolute;
        opacity: 0;
        pointer-events: none;
    }

    .empaque-op span {
        display: inline-block;
        padding: .35rem .8rem;
        border: 1px solid var(--hc-borde);
        border-radius: 999px;
        font-size: .85rem;
        background: #fff;
    }

    .empaque-op input:checked + span {
        background: #111;
        color: var(--hc-dorado);
        border-color: #111;
        font-weight: 600;
    }

    .empaque-op input:focus-visible + span {
        outline: 2px solid var(--hc-dorado);
    }

    .hc-ayuda {
        font-size: .8rem;
        color: #777;
    }

    .parte-fila {
        display: grid;
        grid-template-columns: .9fr .9fr 1.9fr .7fr .6fr .6fr auto;
        gap: .4rem;
        margin-bottom: .4rem;
    }

    @media (max-width: 767px) {
        .parte-fila {
            grid-template-columns: 1fr 1fr;
            padding-bottom: .6rem;
            border-bottom: 1px dashed var(--hc-borde);
        }
    }

    .hc-resumen {
        font-size: .85rem;
        color: #555;
        margin-bottom: .5rem;
    }

    .hc-tabla {
        width: 100%;
        font-size: .9rem;
    }

    .hc-tabla td {
        padding: .25rem 0;
        border-bottom: 1px solid var(--hc-gris);
    }

    .hc-tabla td:last-child {
        text-align: right;
    }

    .hc-tabla .total td {
        font-weight: 700;
        border-bottom: 0;
        padding-top: .45rem;
    }

    .hc-precio-label {
        margin-top: .8rem;
        font-size: .8rem;
        text-transform: uppercase;
        letter-spacing: .5px;
        color: #777;
    }

    .hc-precio {
        font-size: 2.4rem;
        font-weight: 800;
        color: var(--hc-negro);
        line-height: 1.1;
    }

    .hc-chips {
        display: flex;
        flex-wrap: wrap;
        gap: .4rem;
        margin: .6rem 0 1rem;
    }

    .hc-chip {
        border: 1px solid var(--hc-borde);
        background: var(--hc-gris);
        border-radius: 999px;
        padding: .15rem .7rem;
        font-size: .8rem;
    }

    .btn-hc {
        background: var(--hc-dorado);
        border: 1px solid var(--hc-dorado-osc);
        color: #111;
        font-weight: 700;
    }

    .btn-hc:hover {
        background: var(--hc-dorado-osc);
        color: #111;
    }

    #tablaCotizaciones td,
    #tablaCotizaciones th {
        white-space: nowrap;
        vertical-align: middle;
    }

    /* Los 3 datos clave: costo de fabricación, ganancia y total */
    .hc-trio {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr;
        gap: 1rem;
        margin: .6rem 0 1rem;
        background: #1c1c1c;
        border-radius: 12px;
        padding: 1.1rem 1rem;
        box-shadow: 0 4px 12px rgba(0,0,0,.18);
    }

    .hc-dato {
        text-align: center;
        background: none;
        border: 0;
    }

    .hc-dato span {
        display: block;
        font-size: .8rem;
        text-transform: uppercase;
        letter-spacing: .06em;
        color: rgba(255,255,255,.75);
    }

    .hc-dato b {
        font-size: 1.7rem;
        line-height: 1.2;
        color: #fff;
    }

    /* El total a cobrar, el dato principal */
    .hc-dato:last-child b {
        font-size: 2.2rem;
        color: #f6c33b;
    }

    .hc-dato.hc-ganancia b {
        font-size: 1.9rem;
        color: #6fd08c;
    }

    .hc-dato.hc-ganancia.neg b {
        color: #ff7a70;
    }

    @media (max-width: 575px) {
        .hc-trio { gap: .4rem; padding: .8rem .5rem; }
        .hc-dato span { font-size: .62rem; }
        .hc-dato b, .hc-dato.hc-ganancia b { font-size: 1.2rem; }
        .hc-dato:last-child b { font-size: 1.5rem; }
        .barra-total { gap: .4rem 1.2rem; padding: .7rem 1rem; }
        .barra-total b { font-size: 1.3rem; }
        .barra-total .bt-total b { font-size: 1.8rem; }
    }
</style>

<div class="hc-header">
    <div class="icon-wrap"><i class="bi bi-calculator-fill"></i></div>
    <div>
        <h1>Cotizador</h1>
        <p>Calcula el precio de una figura y regístrala como venta</p>
    </div>
</div>

<div id="appCotizar" data-base="<?= $base ?>">
    <div class="row g-3">
        <!-- ── Datos de la pieza ───────────────────────────────────────────── -->
        <div class="col-lg-7">
            <div class="hc-card mb-3">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <h2 class="mb-0"><i class="bi bi-layers me-2"></i>Partes de la impresión</h2>
                    <button type="button" class="btn btn-sm btn-outline-dark" id="btnAgregarParte"><i class="bi bi-plus-lg"></i> Agregar parte</button>
                </div>
                <p class="hc-ayuda">Una fila por cada placa que imprimes, con los gramos y el tiempo que marca Bambu Studio.</p>
                <div class="parte-fila d-none d-md-grid hc-ayuda fw-bold">
                    <span>Nombre</span><span>Material</span><span>Color</span><span>Gramos</span><span>Horas</span><span>Min</span><span></span>
                </div>
                <div id="partes"></div>
            </div>

            <div class="hc-card">
                <h2><i class="bi bi-tag me-2"></i>Datos de la pieza</h2>
                <div class="row g-2">
                    <div class="col-md-8">
                        <label class="form-label mb-1" for="nombrePieza">Mascota / pieza</label>
                        <input type="text" id="nombrePieza" class="form-control" placeholder="Ej. Panchito, pug">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label mb-1" for="altoCm">Alto (cm)</label>
                        <input type="number" id="altoCm" class="form-control" min="0" step="any" placeholder="Ej. 20">
                    </div>
                    <div class="col-md-8">
                        <label class="form-label mb-1" for="clienteId">Cliente</label>
                        <div class="input-group">
                            <select id="clienteId" class="form-select"></select>
                            <button type="button" class="btn btn-outline-dark" id="btnNuevoCliente" title="Nuevo cliente"><i class="bi bi-person-plus-fill"></i></button>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label mb-1" for="pintorId">Pintor</label>
                        <select id="pintorId" class="form-select"></select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label mb-1" for="costoPintura">Pintura (Q)</label>
                        <input type="number" id="costoPintura" class="form-control" min="0" step="any" placeholder="Monto que cobra">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label mb-1" for="envioPintora">Envío a la pintora (Q)</label>
                        <input type="number" id="envioPintora" class="form-control" min="0" step="any" value="0">
                        <small class="hc-ayuda">De tu casa a donde pinta</small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label mb-1" for="envioCliente">Envío al cliente (Q)</label>
                        <input type="number" id="envioCliente" class="form-control" min="0" step="any" value="0">
                        <small class="hc-ayuda">De la pintora al cliente. Total envíos: <b id="envioTotal">Q0.00</b></small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label mb-1" for="otrosInsumos">Otros insumos (Q)</label>
                        <input type="number" id="otrosInsumos" class="form-control" min="0" step="any" value="0">
                    </div>
                </div>
                <p class="hc-ayuda mt-2 mb-0">Si eliges pintor y escribes el alto, la pintura se llena sola con su tarifa. Si escribes un monto, ese manda.</p>

                <div class="mt-3">
                    <label class="form-label mb-1">Empaque de esta pieza</label>
                    <div id="empaqueOpciones" class="d-flex flex-wrap gap-2"></div>
                    <div id="empaqueOtroBox" class="mt-2 d-none" style="max-width: 200px;">
                        <input type="number" id="empaqueCosto" class="form-control" min="0" step="any" placeholder="Monto en Q">
                    </div>
                    <p class="hc-ayuda mt-1 mb-0">Una figura pequeña no necesita la misma caja que un set de varias piezas. Los empaques se administran en Catálogos &gt; Empaques.</p>
                </div>
            </div>
        </div>

        <!-- ── Resultado ───────────────────────────────────────────────────── -->
        <div class="col-lg-5">
            <div class="hc-card hc-sticky">
                <h2><i class="bi bi-cash-stack me-2"></i>Resultado</h2>
                <div id="resultado"></div>
                <div class="mt-3">
                    <label class="form-label mb-1" for="margenPct">Margen % <span class="hc-ayuda">(vacío = el de la configuración)</span></label>
                    <input type="number" id="margenPct" class="form-control" min="0" max="99" step="any" placeholder="Ej. 40">
                </div>
                <div class="d-grid gap-2 mt-3">
                    <button type="button" class="btn btn-hc" id="btnGuardar"><i class="bi bi-save me-1"></i>Guardar cotización</button>
                    <button type="button" class="btn btn-outline-secondary" id="btnLimpiar">Limpiar</button>
                </div>
            </div>
        </div>
    </div>

    <div id="barraTotal" class="barra-total d-none" aria-live="polite"></div>

    <!-- ── Historial ──────────────────────────────────────────────────────── -->
    <div class="hc-card mt-3">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
            <h2 class="mb-0"><i class="bi bi-clock-history me-2"></i>Cotizaciones guardadas</h2>
            <div class="d-flex align-items-center gap-2">
                <span id="buscaCotN" class="hc-ayuda"></span>
                <div class="input-group" style="width:340px;max-width:100%">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input id="buscaCot" type="search" class="form-control" placeholder="Buscar pieza, cliente o pintor" autocomplete="off">
                </div>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="tablaCotizaciones">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Pieza</th>
                        <th>Cliente</th>
                        <th>Pintor</th>
                        <th class="text-end">Costo de fabricación</th>
                        <th class="text-end">Ganancia</th>
                        <th class="text-end">Total</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>

<script src="<?= asset('build/js/cotizaciones/index.js') ?>" type="module"></script>
