<?php
$base = !empty($_ENV['APP_NAME']) ? '/' . $_ENV['APP_NAME'] : '';
include __DIR__ . '/../partials/hc-estilos.php';
?>

<style>
    .rec-item { display: flex; gap: .9rem; align-items: flex-start; padding: .85rem 1rem; border: 1px solid var(--hc-borde); border-left: 6px solid var(--rc, #adb5bd); border-radius: 12px; background: #fff; margin-bottom: .6rem; }
    .rec-item.hecho { opacity: .6; background: #f7f7f5; }
    .rec-item.hecho .rec-texto { text-decoration: line-through; }
    .rec-check { width: 34px; height: 34px; border-radius: 50%; border: 2px solid var(--rc, #adb5bd); background: #fff; color: transparent; font-size: 1.1rem; line-height: 1; flex: 0 0 34px; display: flex; align-items: center; justify-content: center; transition: .15s; }
    .rec-check:hover { background: var(--rc, #adb5bd); color: #fff; }
    .rec-item.hecho .rec-check { background: #2f9e44; border-color: #2f9e44; color: #fff; }
    .rec-texto { font-weight: 700; font-size: 1.05rem; }
    .rec-meta { display: flex; flex-wrap: wrap; gap: .35rem; margin-top: .35rem; }
    .rec-chip { font-size: .78rem; border-radius: 999px; padding: .1rem .6rem; background: #f0efe9; color: #333; white-space: nowrap; }
    .rec-chip.urg { background: #fde4ea; color: #b02a50; font-weight: 700; }
    .rec-chip.venc { background: #d6336c; color: #fff; font-weight: 700; }
    .rec-chip.hoy { background: #ffe8a3; color: #7a5b00; font-weight: 700; }
    .rec-ya { margin-top: .5rem; padding: .35rem .6rem; border-radius: 8px; background: #e6f6ea; border: 1px solid #a5d8b3; font-size: .85rem; }
    .rec-filtros .btn { border-radius: 999px; }
    .rec-filtros .btn.activo { background: #111; color: #f6c33b; border-color: #111; }
    .rec-rapido { display: flex; gap: .5rem; flex-wrap: wrap; }
    .rec-rapido input[type=text] { flex: 1 1 280px; }
</style>

<div class="hc-header">
    <div class="icon-wrap"><i class="bi bi-journal-check"></i></div>
    <div>
        <h1>Recordatorios de trabajo</h1>
        <p>Lo que tienes que hacer para sacar adelante tus trabajos: comprar filamento, arreglar un STL, llamar a un cliente…</p>
    </div>
    <div class="hc-acciones">
        <button class="btn btn-hc" id="btnNuevo"><i class="bi bi-plus-lg me-1"></i>Nuevo recordatorio</button>
    </div>
</div>

<div id="appRecordatorios" data-base="<?= $base ?>">
    <div class="hc-card mb-3">
        <form id="formRapido" class="rec-rapido" autocomplete="off">
            <input type="text" id="rTexto" class="form-control form-control-lg" maxlength="255" placeholder="Escribe un recordatorio y presiona Enter… Ej. Comprar filamento gris para los Pokémon">
            <select id="rTipo" class="form-select form-select-lg" style="max-width:220px"></select>
            <button class="btn btn-hc btn-lg" type="submit"><i class="bi bi-plus-lg me-1"></i>Agregar</button>
        </form>
        <div class="hc-ayuda mt-2">Para agregar la fecha límite, el cliente, la cotización o el color del filamento, usa <b>Nuevo recordatorio</b> o el lápiz de cada uno.</div>
    </div>

    <div class="hc-card">
        <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
            <div class="rec-filtros d-flex flex-wrap gap-2" id="filtrosEstado">
                <button class="btn btn-sm btn-outline-dark activo" data-estado="pendiente">Pendientes <span id="nPend"></span></button>
                <button class="btn btn-sm btn-outline-dark" data-estado="hecho">Hechos</button>
            </div>
            <input type="search" id="rBusca" class="form-control" style="max-width:280px" placeholder="Buscar…">
        </div>
        <div class="rec-filtros d-flex flex-wrap gap-2 mb-3" id="filtrosTipo"></div>
        <div id="lista"></div>
    </div>
</div>

<script src="<?= asset('build/js/recordatorios/index.js') ?>" type="module"></script>
