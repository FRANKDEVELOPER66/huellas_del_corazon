<?php /* Estilos compartidos de las pantallas del cotizador. Se incluye desde cada vista con include. */ ?>
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
        flex-wrap: wrap;
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

    .hc-header .hc-acciones {
        margin-left: auto;
        display: flex;
        gap: .6rem;
        align-items: center;
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

    .hc-inactivo {
        opacity: .55;
        border-top-color: #999;
    }

    .hc-ayuda {
        font-size: .8rem;
        color: #777;
    }

    .hc-vacio {
        grid-column: 1 / -1;
        text-align: center;
        color: #888;
        padding: 2rem;
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

    .hc-chip {
        display: inline-block;
        border: 1px solid var(--hc-borde);
        background: var(--hc-gris);
        border-radius: 999px;
        padding: .15rem .7rem;
        font-size: .8rem;
    }

    .hc-kpis {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
        gap: .8rem;
        margin-bottom: 1rem;
    }

    .hc-kpi {
        background: #fff;
        border: 1px solid var(--hc-borde);
        border-left: 4px solid var(--hc-dorado);
        border-radius: 12px;
        padding: .8rem 1rem;
    }

    .hc-kpi .t {
        font-size: .75rem;
        text-transform: uppercase;
        letter-spacing: .5px;
        color: #777;
    }

    .hc-kpi .v {
        font-size: 1.6rem;
        font-weight: 800;
        line-height: 1.2;
    }

    .pos {
        color: #117a43;
    }

    .neg {
        color: #b42318;
    }

    .hc-barra-wrap {
        background: var(--hc-gris);
        border-radius: 6px;
        height: 10px;
        min-width: 90px;
    }

    .hc-barra {
        height: 10px;
        border-radius: 6px;
        background: var(--hc-dorado);
    }

    .hc-barra.neg {
        background: #b42318;
    }

    .hc-tabla-nw td,
    .hc-tabla-nw th {
        white-space: nowrap;
        vertical-align: middle;
    }
</style>
