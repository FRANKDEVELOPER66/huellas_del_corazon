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
    <div class="tablero" id="tablero"></div>
    <p class="hc-ayuda mt-2">Cada venta crea su pedido en Solicitado. Avanza con el botón y confirma: no se puede regresar. Al final, “Finalizar venta” verifica que esté pagado completo y deja el pedido en Entregado, que ya no cambia.</p>
</div>

<script src="<?= asset('build/js/pedidos/index.js') ?>" type="module"></script>
