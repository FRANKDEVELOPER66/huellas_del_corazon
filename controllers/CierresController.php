<?php

namespace Controllers;

use MVC\Router;
use Model\ActiveRecord;
use Model\Cierre;
use Model\Reporte;

// Cierre de período = conciliar con el banco + bloquear todo lo anterior, en un solo paso.
// Funciona con cualquier fecha: semanal, quincenal o mensual. Cada cierre empieza donde terminó el anterior.
class CierresController
{
    use RespuestaApi, Comprobantes;

    private static function exigirTabla(): void
    {
        if (!Cierre::hayTabla()) {
            self::responder(0, 'Falta crear la tabla de cierres: corre sql/cierre_periodo.sql en tu base de datos');
        }
    }

    // GET: hasta (opcional). Devuelve lo que hace falta para cerrar: la caja a esa fecha y los movimientos a revisar.
    public static function prepararAPI(Router $router): void
    {
        getHeadersApi();
        self::exigirTabla();

        $ultimo = Cierre::ultimo();
        $desde = Cierre::desde();

        $hasta = trim((string) ($_GET['hasta'] ?? ''));
        if (!Cierre::fechaValida($hasta)) {
            // Sugerido: fin del mes pasado; si ese ya está cerrado, hoy
            $hasta = date('Y-m-t', strtotime('first day of last month'));
            if ($hasta < $desde) {
                $hasta = date('Y-m-d');
            }
        }

        $aviso = null;
        if ($hasta > date('Y-m-d')) {
            $aviso = 'No puedes cerrar una fecha futura';
        } elseif ($hasta < $desde) {
            $aviso = 'Ya cerraste hasta el ' . Cierre::dmy((string) $ultimo['hasta']) . '. Elige una fecha posterior';
        }

        $datos = [
            'desde'         => $desde,
            'hasta'         => $hasta,
            'aviso'         => $aviso,
            'ultimo'        => $ultimo ? ['id' => (int) $ultimo['id'], 'hasta' => $ultimo['hasta'], 'sin_explicar' => (float) $ultimo['sin_explicar']] : null,
            'saldo_sistema' => null,
            'movimientos'   => [],
            'historial'     => ActiveRecord::fetchArray(
                'SELECT id, desde, hasta, saldo_banco, saldo_sistema, no_en_banco, sin_explicar, nota, creado_en, reabierto_en, reabierto_motivo
                 FROM cierres_periodo ORDER BY id DESC LIMIT 20'
            ),
        ];

        if (!$aviso) {
            $datos['saldo_sistema'] = Cierre::saldoSistemaAl($hasta);
            $datos['movimientos'] = Cierre::movimientos($desde, $hasta);
        }

        self::responder(1, '', ['datos' => $datos]);
    }

    // POST: hasta, saldo_banco, no_vistos (JSON con las refs que NO ves en el banco), nota, comprobante (captura del banco)
    // El servidor vuelve a calcular todo; no confía en las sumas del navegador.
    public static function cerrarAPI(Router $router): void
    {
        getHeadersApi();
        self::exigirTabla();

        $hasta = trim((string) ($_POST['hasta'] ?? ''));
        if (!Cierre::fechaValida($hasta)) {
            self::responder(0, 'Elige hasta qué fecha vas a cerrar');
        }
        if ($hasta > date('Y-m-d')) {
            self::responder(0, 'No puedes cerrar una fecha futura');
        }
        $desde = Cierre::desde();
        if ($hasta < $desde) {
            self::responder(0, 'Ya cerraste hasta el ' . Cierre::dmy((string) Cierre::ultimaFecha()) . '. Elige una fecha posterior');
        }

        if (!isset($_POST['saldo_banco']) || !is_numeric($_POST['saldo_banco'])) {
            self::responder(0, 'Escribe el saldo que muestra tu banco en esa fecha');
        }
        $saldoBanco = round((float) $_POST['saldo_banco'], 2);

        // Captura del banco: obligatoria. La misma captura no sirve para dos cierres.
        if (empty($_FILES['comprobante']['name'])) {
            self::responder(0, 'Sube la captura (o el PDF) de tu banco donde se ve el saldo de esa fecha');
        }
        $hash = (is_uploaded_file($_FILES['comprobante']['tmp_name'] ?? '') || is_file($_FILES['comprobante']['tmp_name'] ?? ''))
            ? hash_file('sha256', $_FILES['comprobante']['tmp_name']) : '';
        if ($hash === '') {
            self::responder(0, 'No se pudo leer la captura del banco');
        }
        $repetida = ActiveRecord::fetchArray('SELECT hasta FROM cierres_periodo WHERE evidencia_hash = ? AND reabierto_en IS NULL LIMIT 1', [$hash]);
        if ($repetida) {
            self::responder(0, 'Esa misma captura ya se usó en el cierre hasta el ' . Cierre::dmy((string) $repetida[0]['hasta']) . '. Sube la del saldo de esta fecha');
        }

        $noVistos = json_decode($_POST['no_vistos'] ?? '[]', true);
        $noVistos = is_array($noVistos) ? array_map('strval', $noVistos) : [];

        $saldoSistema = Cierre::saldoSistemaAl($hasta);
        $noEnBanco = 0.0;
        $detalle = [];
        foreach (Cierre::movimientos($desde, $hasta) as $m) {
            if (in_array($m['ref'], $noVistos, true)) {
                $noEnBanco += (float) $m['monto'];
                $detalle[] = ['ref' => $m['ref'], 'fecha' => $m['fecha'], 'descripcion' => $m['descripcion'], 'monto' => round((float) $m['monto'], 2)];
            }
        }
        $noEnBanco = round($noEnBanco, 2);

        $diferencia = round($saldoBanco - $saldoSistema, 2);
        // Lo que el banco debería mostrar si lo único que falta por pasar es lo que marcaste como "no lo veo"
        $sinExplicar = round($saldoBanco - ($saldoSistema - $noEnBanco), 2);

        $nota = trim((string) ($_POST['nota'] ?? ''));
        if (abs($sinExplicar) > 0.005 && mb_strlen($nota) < 5) {
            self::responder(0, 'Quedan Q' . number_format(abs($sinExplicar), 2) . ' sin explicar. Escribe en la nota qué crees que pasó (mínimo 5 letras) o corrige los registros antes de cerrar');
        }

        $archivo = self::guardarComprobante();
        try {
            ActiveRecord::getDB()
                ->prepare('INSERT INTO cierres_periodo (desde, hasta, saldo_banco, saldo_sistema, diferencia, no_en_banco, sin_explicar, nota, detalle, evidencia, evidencia_hash)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$desde, $hasta, $saldoBanco, $saldoSistema, $diferencia, $noEnBanco, $sinExplicar,
                    mb_substr($nota, 0, 500) ?: null, json_encode($detalle, JSON_UNESCAPED_UNICODE), $archivo, $hash]);
        } catch (\Throwable $e) {
            self::borrarComprobante($archivo);
            self::responder(0, 'No se pudo guardar el cierre');
        }

        $cuadra = abs($sinExplicar) <= 0.005;
        self::responder(
            1,
            ($cuadra ? 'Período cerrado y conciliado' : 'Período cerrado con una diferencia sin explicar de Q' . number_format($sinExplicar, 2))
                . ': desde ahora nada hasta el ' . Cierre::dmy($hasta) . ' se puede cambiar',
            ['sin_explicar' => $sinExplicar]
        );
    }

    // POST: id (debe ser el último cierre vigente), motivo. El cierre queda en el historial como reabierto.
    public static function reabrirAPI(Router $router): void
    {
        getHeadersApi();
        self::exigirTabla();

        $ultimo = Cierre::ultimo();
        if (!$ultimo || (int) $ultimo['id'] !== (int) ($_POST['id'] ?? 0)) {
            self::responder(0, 'Solo se puede reabrir el último cierre. Reabre primero el más reciente');
        }
        $motivo = trim((string) ($_POST['motivo'] ?? ''));
        if (mb_strlen($motivo) < 5) {
            self::responder(0, 'Escribe por qué reabres este período (mínimo 5 letras)');
        }

        ActiveRecord::getDB()
            ->prepare('UPDATE cierres_periodo SET reabierto_en = NOW(), reabierto_motivo = ? WHERE id = ? AND reabierto_en IS NULL')
            ->execute([mb_substr($motivo, 0, 300), (int) $ultimo['id']]);

        self::responder(1, 'Período reabierto: ya puedes corregir lo que tenga fecha desde el ' . Cierre::dmy((string) $ultimo['desde']));
    }

    // Muestra la captura del banco de un cierre (los archivos no se abren directo por URL)
    public static function evidenciaAPI(Router $router): void
    {
        $f = Cierre::hayTabla() ? ActiveRecord::fetchArray('SELECT id, evidencia FROM cierres_periodo WHERE id = ?', [(int) ($_GET['id'] ?? 0)]) : [];
        $ruta = $f ? self::carpetaComprobantes(false) . '/' . basename((string) $f[0]['evidencia']) : '';

        if (!$ruta || !is_file($ruta)) {
            http_response_code(404);
            exit('Captura no encontrada');
        }

        header('Content-Type: ' . (new \finfo(FILEINFO_MIME_TYPE))->file($ruta));
        header('Content-Length: ' . filesize($ruta));
        header('Content-Disposition: inline; filename="cierre-' . (int) $f[0]['id'] . '.' . pathinfo($ruta, PATHINFO_EXTENSION) . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($ruta);
        exit;
    }
}
