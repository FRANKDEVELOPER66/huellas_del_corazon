<?php
$base = !empty($_ENV['APP_NAME']) ? '/' . $_ENV['APP_NAME'] : '';
include __DIR__ . '/../partials/hc-estilos.php';
?>

<style>
    .tablero {
        display: grid;
        grid-auto-flow: column;
        grid-auto-columns: minmax(250px, 1fr);
        gap: .8rem;
        overflow-x: auto;
        padding-bottom: .5rem;
    }

    .columna {
        background: var(--hc-gris);
        border: 1px solid var(--hc-borde);
        border-radius: 12px;
        padding: .6rem;
        min-height: 200px;
    }

    .columna h3 {
        font-size: .8rem;
        text-transform: uppercase;
        letter-spacing: .5px;
        color: #555;
        margin: 0 0 .6rem;
        display: flex;
        justify-content: space-between;
    }

    .tarjeta-deuda {
        border-left-color: #b42318;
        background: #fff7f6;
    }

    .tarjeta {
        background: #fff;
        border: 1px solid var(--hc-borde);
        border-left: 4px solid var(--hc-dorado);
        border-radius: 10px;
        padding: .6rem .7rem;
        margin-bottom: .5rem;
        font-size: .88rem;
    }

    .entregados-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: .8rem;
    }

    .tarjeta-cancelada { margin-bottom: 0; border-left-color: #b42318; }

    .tarjeta-entregada {
        border-left-color: #2f9e44;
        margin-bottom: 0;
    }

    .linea-tiempo {
        list-style: none;
        margin: .5rem 0 0;
        padding: 0 0 0 .2rem;
        border-left: 2px solid #cfe9d6;
    }

    .linea-tiempo li {
        position: relative;
        padding: 0 0 .35rem .9rem;
        font-size: .82rem;
    }

    .linea-tiempo li::before {
        content: '';
        position: absolute;
        left: -6px;
        top: .3rem;
        width: 10px;
        height: 10px;
        border-radius: 50%;
        background: #2f9e44;
        border: 2px solid #fff;
        box-shadow: 0 0 0 1px #2f9e44;
    }

    .linea-tiempo li.vacio::before { background: #fff; box-shadow: 0 0 0 1px #adb5bd; }
    .linea-tiempo li.vacio { color: #868e96; }
</style>

<div class="hc-header">
    <div class="icon-wrap"><i class="bi bi-box-seam-fill"></i></div>
    <div>
        <h1>Pedidos</h1>
        <p>De la venta a la entrega: en qué etapa va cada figura y cuánto falta por cobrar</p>
    </div>
    <div class="hc-acciones">
        <button class="btn btn-hc" id="btnNuevo"><i class="bi bi-plus-lg me-1"></i>Nuevo pedido</button>
    </div>
</div>

<div id="appPedidos" data-base="<?= $base ?>">
    <div id="alertasFil"></div>
    <div class="tablero" id="tablero"></div>
    <p class="hc-ayuda mt-2 mb-4">Cada venta crea su pedido en Solicitado. Avanza con el botón y confirma: no se puede regresar. Al final, “Finalizar venta” verifica que esté pagado completo y deja el pedido en Entregado, que ya no cambia.</p>

    <div class="hc-header mt-4" id="cabEntregados">
        <div class="icon-wrap"><i class="bi bi-check2-circle"></i></div>
        <div>
            <h1>Entregados <span id="nEntregados" style="color:#f6c33b"></span></h1>
            <p>Ventas finalizadas: cuándo se dio el anticipo, cuándo se pagó completo y cuándo se entregó</p>
        </div>
    </div>
    <div class="entregados-grid" id="entregados"></div>

    <div class="hc-header mt-4" id="cabCancelados" style="display:none">
        <div class="icon-wrap" style="color:#ff8a8a;background:rgba(255,100,100,.12);border-color:rgba(255,100,100,.35)"><i class="bi bi-x-circle"></i></div>
        <div>
            <h1>Cancelados <span id="nCancelados" style="color:#ff8a8a"></span></h1>
            <p>Pedidos cancelados: cuándo se cancelaron, el motivo y cuánto se devolvió o se retuvo</p>
        </div>
    </div>
    <div class="entregados-grid" id="cancelados"></div>
</div>

<script src="<?= asset('build/js/pedidos/index.js') ?>" type="module"></script>
