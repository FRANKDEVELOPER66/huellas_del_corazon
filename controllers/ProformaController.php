<?php

namespace Controllers;

use MVC\Router;
use Model\ActiveRecord;
use Model\Cotizacion;

// Proforma (cotización formal para el cliente). NO es una factura. Nunca muestra costos ni ganancia.
// Es una hoja lista para imprimir: el navegador la guarda como PDF (Imprimir > Guardar como PDF).
class ProformaController
{
    use RespuestaApi;

    // Textos que se pueden cambiar en Configuración. Los valores por defecto son de ejemplo.
    private const CAMPOS = [
        'negocio'     => ['Huellas del Corazón', 80],
        'lema'        => ['Figuras personalizadas impresas en 3D y pintadas a mano', 120],
        'contacto'    => ['', 160],
        'incluye'     => ['Impresión 3D y empaque', 200],
        'entrega'     => ['15 días hábiles (de lunes a viernes) a partir de recibir el anticipo', 160],
        'pago'        => ['Depósito o transferencia bancaria. Los datos de la cuenta se envían por mensaje.', 255],
        'condiciones' => ['Pieza personalizada: una vez iniciada la impresión no se aceptan cambios.', 255],
        'cancelacion' => ['Si el cliente cancela después de pagar el anticipo, se le devuelve el 50% del anticipo; el otro 50% cubre el trabajo y el material ya realizados.', 255],
        'validez'     => ['8', 3],
        'anticipo'    => ['50', 3],
    ];

    private static function ajustes(): array
    {
        $r = [];
        foreach (self::CAMPOS as $k => [$defecto]) {
            $r[$k] = $defecto;
        }
        foreach (ActiveRecord::fetchArray("SELECT clave, valor FROM ajustes WHERE clave LIKE 'proforma\\_%'") as $f) {
            $k = substr((string) $f['clave'], 9);
            if (isset(self::CAMPOS[$k])) {
                $r[$k] = (string) $f['valor'];
            }
        }
        return $r;
    }

    public static function datosAPI(Router $router): void
    {
        getHeadersApi();
        self::responder(1, '', ['datos' => self::ajustes()]);
    }

    public static function guardarAPI(Router $router): void
    {
        getHeadersApi();

        $nuevo = [];
        foreach (self::CAMPOS as $k => [, $max]) {
            $v = trim((string) ($_POST[$k] ?? ''));
            if (mb_strlen($v) > $max) {
                self::responder(0, "El campo «$k» es muy largo (máximo $max letras)");
            }
            $nuevo[$k] = $v;
        }
        if ($nuevo['negocio'] === '') {
            self::responder(0, 'Escribe el nombre de tu negocio');
        }
        if (!ctype_digit($nuevo['validez']) || (int) $nuevo['validez'] < 1 || (int) $nuevo['validez'] > 365) {
            self::responder(0, 'La validez va en días (de 1 a 365)');
        }
        if (!is_numeric($nuevo['anticipo']) || (float) $nuevo['anticipo'] < 0 || (float) $nuevo['anticipo'] > 100) {
            self::responder(0, 'El anticipo va en porcentaje (de 0 a 100)');
        }

        $st = ActiveRecord::getDB()->prepare('INSERT INTO ajustes (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)');
        foreach ($nuevo as $k => $v) {
            $st->execute(['proforma_' . $k, $v]);
        }
        self::responder(1, 'Datos de la proforma guardados');
    }

    private static function q(float $n): string
    {
        return 'Q' . number_format($n, 2);
    }

    private static function h($s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    // GET /cotizaciones/proforma?id=  → página para imprimir
    public static function documento(Router $router): void
    {
        $id = (int) ($_GET['id'] ?? 0);
        $c = $id ? Cotizacion::find($id) : null;
        if (!$c) {
            http_response_code(404);
            exit('Cotización no encontrada');
        }

        $cli = ((int) $c->cliente_id) ? ActiveRecord::fetchArray('SELECT nombre, telefono FROM clientes WHERE id = ?', [(int) $c->cliente_id]) : [];
        $cliente = $cli[0]['nombre'] ?? '';
        $telefono = $cli[0]['telefono'] ?? '';

        $a = self::ajustes();
        $precio = round((float) $c->precio_sugerido, 2);
        $anticipoPct = (float) $a['anticipo'];
        $hoy = date('d/m/Y');
        $vence = date('d/m/Y', strtotime('+' . (int) $a['validez'] . ' days'));
        $numero = 'PF-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT);
        $alto = (float) $c->alto_cm > 0 ? ' · ' . rtrim(rtrim(number_format((float) $c->alto_cm, 1), '0'), '.') . ' cm de alto' : '';
        $h = [self::class, 'h'];
        $base = !empty($_ENV['APP_NAME']) ? '/' . $_ENV['APP_NAME'] : '';
        // «días hábiles» en negrita para que no pase desapercibido
        $resaltar = fn(string $t) => preg_replace('/(d[ií]as h[aá]biles)/iu', '<b>$1</b>', self::h($t));

        header('Content-Type: text/html; charset=utf-8');
        ?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h($numero) ?> · <?= $h($a['negocio']) ?></title>
<style>
    :root { --oro: #b8860b; --tinta: #26231d; --suave: #6f695c; --linea: #e4dccb; --fondo: #f6f3ec; }
    * { box-sizing: border-box; }
    body { margin: 0; background: var(--fondo); color: var(--tinta); font: 14px/1.5 "Helvetica Neue", Arial, sans-serif; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .barra { background: #1f1d19; color: #fff; padding: .7rem 1rem; display: flex; flex-wrap: wrap; gap: .75rem 1.25rem; align-items: flex-end; justify-content: center; }
    .barra label { display: block; font-size: .7rem; text-transform: uppercase; letter-spacing: .05em; color: #cfc6b0; margin-bottom: .15rem; }
    .barra input { padding: .35rem .5rem; border-radius: 6px; border: 0; width: 150px; font-size: .9rem; }
    .barra button { background: var(--oro); color: #fff; border: 0; padding: .55rem 1.1rem; border-radius: 8px; font-weight: 700; cursor: pointer; font-size: .95rem; }
    .barra .tip { flex-basis: 100%; text-align: center; font-size: .75rem; color: #cfc6b0; }
    .hoja { background: #fff; width: 210mm; min-height: 297mm; margin: 1.2rem auto; padding: 18mm 18mm 14mm; box-shadow: 0 6px 30px rgba(0,0,0,.12); position: relative; }
    .cab { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; border-bottom: 3px solid var(--oro); padding-bottom: 1rem; }
    .marca { display: flex; gap: .8rem; align-items: center; }
    .logo-wrap { display: flex; flex-direction: column; align-items: flex-start; gap: .25rem; }
    .logo { height: 300px; width: auto; max-width: 280px; object-fit: contain; display: block; }
    .lemas p { margin: 0; color: var(--suave); font-size: .8rem; line-height: 1.35; }
    .alt { display: none; gap: .8rem; align-items: center; }
    .marca svg { width: 54px; height: 54px; flex: none; }
    .marca h1 { margin: 0; font: 700 1.55rem Georgia, "Times New Roman", serif; letter-spacing: .01em; }
    .marca p { margin: .1rem 0 0; color: var(--suave); font-size: .85rem; }
    .titulo { text-align: right; }
    .titulo b { display: block; font: 700 1.7rem Georgia, serif; letter-spacing: .22em; color: var(--oro); }
    .titulo span { display: block; color: var(--suave); font-size: .85rem; }
    .datos { display: grid; grid-template-columns: 1fr 1fr; gap: 1.2rem; margin: 1.3rem 0; }
    .caja h4 { margin: 0 0 .3rem; font-size: .7rem; text-transform: uppercase; letter-spacing: .12em; color: var(--suave); }
    .caja p { margin: 0; }
    .caja .grande { font-size: 1.05rem; font-weight: 700; }
    table { width: 100%; border-collapse: collapse; margin-top: .4rem; }
    th { text-align: left; font-size: .7rem; text-transform: uppercase; letter-spacing: .1em; color: var(--suave); border-bottom: 1px solid var(--linea); padding: .5rem .4rem; }
    th.d, td.d { text-align: right; white-space: nowrap; }
    td { padding: .9rem .4rem; border-bottom: 1px solid var(--linea); vertical-align: top; }
    td small { display: block; color: var(--suave); margin-top: .2rem; }
    .tot { margin: 1rem 0 0 auto; width: 62%; }
    .tot div { display: flex; justify-content: space-between; padding: .35rem .4rem; }
    .tot .total { background: #faf4e3; border-top: 2px solid var(--oro); font-weight: 700; font-size: 1.15rem; margin-top: .3rem; padding: .6rem .4rem; }
    .tot .saldo { color: var(--suave); }
    .condiciones { margin-top: 1.6rem; display: grid; grid-template-columns: 1fr 1fr; gap: 1rem 1.5rem; }
    .condiciones h4 { margin: 0 0 .25rem; font-size: .7rem; text-transform: uppercase; letter-spacing: .12em; color: var(--oro); }
    .condiciones p { margin: 0; font-size: .85rem; }
    .pie { position: absolute; left: 18mm; right: 18mm; bottom: 12mm; border-top: 1px solid var(--linea); padding-top: .6rem; font-size: .75rem; color: var(--suave); text-align: center; }
    .pie b { color: var(--tinta); }
    @page { size: A4; margin: 0; }
    @media print {
        body { background: #fff; }
        .barra { display: none; }
        .hoja { margin: 0; box-shadow: none; width: 210mm; height: 297mm; min-height: 0; overflow: hidden; }
    }
    @media (max-width: 800px) { .hoja { width: auto; margin: 0; padding: 1.2rem; min-height: 0; } .pie { position: static; margin-top: 1.5rem; } .datos, .condiciones { grid-template-columns: 1fr; } .tot { width: 100%; } }
</style>
</head>
<body>
<div class="barra">
    <div><label for="inCliente">Cliente</label><input id="inCliente" value="<?= $h($cliente) ?>" placeholder="Nombre del cliente"></div>
    <div><label for="inPrecio">Precio (Q)</label><input id="inPrecio" type="number" step="any" min="0" value="<?= $h($precio) ?>"></div>
    <div><label for="inAnticipo">Anticipo (%)</label><input id="inAnticipo" type="number" step="any" min="0" max="100" value="<?= $h($anticipoPct) ?>"></div>
    <button onclick="window.print()">Imprimir / Guardar PDF</button>
    <div class="tip">Para el PDF: en la ventana de impresión elige «Guardar como PDF». Cambia el cliente, el precio o el anticipo aquí y la hoja se actualiza.</div>
</div>

<div class="hoja">
    <div class="cab">
        <div class="marca">
            <div class="logo-wrap">
                <img id="logo" class="logo" src="<?= $h($base) ?>/images/FONDO_SIN.png" alt="<?= $h($a['negocio']) ?>"
                     onerror="this.style.display='none';document.getElementById('alt').style.display='flex'">
                <div class="lemas">
                    <p><?= $h($a['lema']) ?></p>
                    <?php if ($a['contacto'] !== '') : ?><p><?= $h($a['contacto']) ?></p><?php endif; ?>
                </div>
            </div>
            <div id="alt" class="alt">
            <svg viewBox="0 0 64 64" aria-hidden="true"><g fill="#b8860b"><ellipse cx="32" cy="42" rx="14" ry="11"/><ellipse cx="14" cy="28" rx="6" ry="8" transform="rotate(-18 14 28)"/><ellipse cx="25" cy="17" rx="6" ry="8.5"/><ellipse cx="39" cy="17" rx="6" ry="8.5"/><ellipse cx="50" cy="28" rx="6" ry="8" transform="rotate(18 50 28)"/></g></svg>
            <div>
                <h1><?= $h($a['negocio']) ?></h1>
                <p><?= $h($a['lema']) ?></p>
                <?php if ($a['contacto'] !== '') : ?><p><?= $h($a['contacto']) ?></p><?php endif; ?>
            </div>
            </div>
        </div>
        <div class="titulo">
            <b>PROFORMA</b>
            <span>No. <?= $h($numero) ?></span>
            <span>Fecha: <?= $h($hoy) ?></span>
            <span>Válida hasta: <b style="display:inline;font:inherit;letter-spacing:0;color:var(--tinta)"><?= $h($vence) ?></b></span>
        </div>
    </div>

    <div class="datos">
        <div class="caja">
            <h4>Para</h4>
            <p class="grande" id="outCliente"><?= $h($cliente ?: '—') ?></p>
            <?php if ($telefono) : ?><p id="outTel"><?= $h($telefono) ?></p><?php endif; ?>
        </div>
        <div class="caja">
            <h4>Referencia</h4>
            <p class="grande"><?= $h($c->nombre_pieza) ?></p>
            <p>Cotización #<?= (int) $id ?></p>
        </div>
    </div>

    <table>
        <thead><tr><th>Descripción</th><th class="d">Cant.</th><th class="d">Precio</th><th class="d">Total</th></tr></thead>
        <tbody>
            <tr>
                <td>
                    <b>Figura personalizada · <?= $h($c->nombre_pieza) ?></b><?= $h($alto) ?>
                    <small>Incluye: <?= $h($a['incluye']) ?></small>
                </td>
                <td class="d">1</td>
                <td class="d" data-precio><?= self::q($precio) ?></td>
                <td class="d" data-precio><?= self::q($precio) ?></td>
            </tr>
        </tbody>
    </table>

    <div class="tot">
        <div class="total"><span>TOTAL</span><span data-precio><?= self::q($precio) ?></span></div>
        <div><span>Anticipo para iniciar (<span id="outPct"><?= $h(rtrim(rtrim(number_format($anticipoPct, 2), '0'), '.')) ?></span>%)</span><span id="outAnticipo"><?= self::q(round($precio * $anticipoPct / 100, 2)) ?></span></div>
        <div class="saldo"><span>Saldo contra entrega</span><span id="outSaldo"><?= self::q($precio - round($precio * $anticipoPct / 100, 2)) ?></span></div>
    </div>

    <div class="condiciones">
        <div><h4>Forma de pago</h4><p><?= nl2br($h($a['pago'])) ?></p></div>
        <div><h4>Tiempo de entrega</h4><p><?= $resaltar($a['entrega']) ?></p></div>
        <div><h4>Validez</h4><p>Esta proforma es válida por <?= (int) $a['validez'] ?> días, hasta el <?= $h($vence) ?>.</p></div>
        <div><h4>Condiciones</h4><p><?= $h($a['condiciones']) ?><?php if (trim($a['cancelacion']) !== '') : ?><br><b>Cancelación:</b> <?= $h($a['cancelacion']) ?><?php endif; ?></p></div>
    </div>

    <div class="pie">Este documento es una <b>proforma</b> (cotización formal) y <b>no constituye factura</b>. · <?= $h($a['negocio']) ?></div>
</div>

<script>
(function () {
    var q = function (n) { return 'Q' + (Math.round(n * 100) / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
    var $ = function (id) { return document.getElementById(id); };
    var original = $('inCliente').value.trim();
    function pintar() {
        var precio = parseFloat($('inPrecio').value) || 0;
        var pct = Math.min(Math.max(parseFloat($('inAnticipo').value) || 0, 0), 100);
        var ant = Math.round(precio * pct) / 100;
        document.querySelectorAll('[data-precio]').forEach(function (e) { e.textContent = q(precio); });
        $('outPct').textContent = String(Math.round(pct * 100) / 100);
        $('outAnticipo').textContent = q(ant);
        $('outSaldo').textContent = q(precio - ant);
        var nombre = $('inCliente').value.trim();
        $('outCliente').textContent = nombre || '—';
        // El teléfono es del cliente de la cotización: si cambias el nombre, ya no aplica
        if ($('outTel')) $('outTel').style.display = nombre === original ? '' : 'none';
    }
    ['inPrecio', 'inAnticipo', 'inCliente'].forEach(function (id) { $(id).addEventListener('input', pintar); });
    pintar();
})();
</script>
</body>
</html>
        <?php
        exit;
    }
}
