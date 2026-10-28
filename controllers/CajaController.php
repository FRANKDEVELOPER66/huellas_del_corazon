<?php

namespace Controllers;

use MVC\Router;
use Model\ActiveRecord;
use Model\Aporte;
use Model\Reporte;

class CajaController
{
    use RespuestaApi, ReglasGasto;

    // Caja de hoy y los últimos aportes y retiros
    public static function resumenAPI(Router $router): void
    {
        getHeadersApi();

        self::responder(1, '', ['datos' => [
            'caja'        => Reporte::caja(),
            'movimientos' => ActiveRecord::fetchArray('SELECT * FROM aportes ORDER BY fecha DESC, id DESC LIMIT 20')
        ]]);
    }

    // Registrar dinero que PONES en el negocio (aporte) o que SACAS (retiro)
    public static function crearAPI(Router $router): void
    {
        getHeadersApi();

        $aporte = new Aporte([
            'fecha'       => trim($_POST['fecha'] ?? date('Y-m-d')),
            'tipo'        => trim($_POST['tipo'] ?? 'aporte'),
            'monto'       => (float)($_POST['monto'] ?? 0),
            'descripcion' => trim($_POST['descripcion'] ?? '')
        ]);

        self::errores($aporte->validar());

        // Solo se puede sacar GANANCIA. El resto de la caja es para reponer material y pagar lo pendiente.
        if ($aporte->tipo === 'retiro') {
            $retirable = (float) Reporte::caja()['ganancia_retirable'];
            if ($aporte->monto > $retirable + 0.005) {
                self::responder(0, 'No puedes sacar más que tu ganancia: hoy son Q' . number_format($retirable, 2) . '. Lo demás de la caja es la reserva para repuestos, el dinero para reponer material y lo que debes a la pintora y los envíos.');
            }
        }

        $resultado = $aporte->crear();
        self::responder(
            $resultado['resultado'] !== false ? 1 : 0,
            $resultado['resultado'] !== false ? ($aporte->tipo === 'aporte' ? 'Aporte registrado' : 'Retiro registrado') : 'Error al registrar',
            ['id' => $resultado['id'] ?? null]
        );
    }

    public static function eliminarAPI(Router $router): void
    {
        getHeadersApi();

        $aporte = Aporte::find((int)($_POST['id'] ?? 0));
        if (!$aporte) {
            self::responder(0, 'Movimiento no encontrado');
        }

        if ($aporte->gasto_id) {
            self::responder(0, 'Este aporte viene de un gasto que pagaste de tu bolsillo. Edita ese gasto en vez de borrar el aporte');
        }

        $aporte->eliminar();
        self::responder(1, 'Movimiento eliminado');
    }

    // ── Conciliación con el banco ───────────────────────────────────────────────

    // Movimientos de la caja que tocan la cuenta del banco, desde una fecha.
    // Quedan fuera los gastos "de mi bolsillo" y su aporte ligado: no pasaron por la cuenta del negocio.
    private static function movimientosBanco(string $desde): array
    {
        return ActiveRecord::fetchArray(
            "SELECT * FROM (
                SELECT 'cobro' AS tipo, CONCAT('pg', p.id) AS ref, p.fecha, p.monto AS monto,
                       CONCAT('Cobro de ', p.tipo, ': ', v.descripcion) AS descripcion
                FROM pagos p JOIN ventas v ON v.id = p.venta_id
                WHERE p.fecha >= ?
                UNION ALL
                SELECT a.tipo, CONCAT('ap', a.id), a.fecha, IF(a.tipo = 'aporte', a.monto, -a.monto), a.descripcion
                FROM aportes a
                WHERE a.gasto_id IS NULL AND a.fecha >= ?
                UNION ALL
                SELECT 'gasto', CONCAT('ga', g.id), g.fecha, -g.monto, CONCAT(cg.nombre, ': ', g.descripcion)
                FROM gastos g JOIN categorias_gasto cg ON cg.id = g.categoria_id
                WHERE g.origen = 'caja' AND g.fecha >= ?
             ) m
             ORDER BY fecha DESC, ref DESC",
            [$desde, $desde, $desde]
        );
    }

    private static function desdeValido($valor): string
    {
        $d = trim((string) $valor);
        $f = \DateTime::createFromFormat('Y-m-d', $d);
        if ($f && $f->format('Y-m-d') === $d) {
            return $d;
        }
        // Por defecto, desde el día 1 del mes pasado
        return date('Y-m-01', strtotime('first day of last month'));
    }

    // GET: desde (opcional). Devuelve la caja, los movimientos para marcar y las conciliaciones anteriores.
    public static function conciliacionAPI(Router $router): void
    {
        getHeadersApi();

        $desde = self::desdeValido($_GET['desde'] ?? '');
        $movs = self::movimientosBanco($desde);
        foreach ($movs as &$m) {
            $m['monto'] = round((float) $m['monto'], 2);
        }
        unset($m);

        $historial = ActiveRecord::fetchArray(
            'SELECT id, fecha, saldo_banco, saldo_caja, diferencia, no_en_banco, sin_explicar, nota
             FROM conciliaciones ORDER BY fecha DESC, id DESC LIMIT 12'
        );

        self::responder(1, '', ['datos' => [
            'desde'       => $desde,
            'caja'        => Reporte::caja(),
            'movimientos' => $movs,
            'historial'   => $historial,
        ]]);
    }

    // POST: fecha, saldo_banco, desde, no_vistos (JSON con las refs que NO ves en el banco), nota
    // El servidor vuelve a calcular todo; no confía en las sumas del navegador.
    public static function conciliarAPI(Router $router): void
    {
        getHeadersApi();

        $fecha = trim($_POST['fecha'] ?? date('Y-m-d'));
        self::fechaNoFutura($fecha);

        if (!isset($_POST['saldo_banco']) || !is_numeric($_POST['saldo_banco'])) {
            self::responder(0, 'Escribe el saldo que muestra tu banco');
        }
        $saldoBanco = round((float) $_POST['saldo_banco'], 2);

        $desde = self::desdeValido($_POST['desde'] ?? '');
        $noVistos = json_decode($_POST['no_vistos'] ?? '[]', true);
        $noVistos = is_array($noVistos) ? array_map('strval', $noVistos) : [];

        $saldoCaja = (float) Reporte::caja()['caja'];
        $noEnBanco = 0.0;
        $detalle = [];
        foreach (self::movimientosBanco($desde) as $m) {
            if (in_array($m['ref'], $noVistos, true)) {
                $noEnBanco += (float) $m['monto'];
                $detalle[] = ['fecha' => $m['fecha'], 'descripcion' => $m['descripcion'], 'monto' => round((float) $m['monto'], 2)];
            }
        }
        $noEnBanco = round($noEnBanco, 2);

        $diferencia = round($saldoBanco - $saldoCaja, 2);
        // Lo que el banco debería mostrar si lo único que falta por pasar es lo que marcaste como "no lo veo"
        $esperado = round($saldoCaja - $noEnBanco, 2);
        $sinExplicar = round($saldoBanco - $esperado, 2);

        $nota = trim($_POST['nota'] ?? '');
        if (abs($sinExplicar) > 0.005 && mb_strlen($nota) < 5) {
            self::responder(0, 'Quedan Q' . number_format(abs($sinExplicar), 2) . ' sin explicar. Escribe en la nota qué crees que pasó (mínimo 5 letras)');
        }

        ActiveRecord::getDB()
            ->prepare('INSERT INTO conciliaciones (fecha, saldo_banco, saldo_caja, diferencia, no_en_banco, sin_explicar, nota, detalle) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$fecha, $saldoBanco, $saldoCaja, $diferencia, $noEnBanco, $sinExplicar, mb_substr($nota, 0, 500) ?: null, json_encode($detalle, JSON_UNESCAPED_UNICODE)]);

        self::responder(
            1,
            abs($sinExplicar) <= 0.005 ? 'Conciliación guardada: la caja cuadra con el banco' : 'Conciliación guardada con una diferencia sin explicar de Q' . number_format($sinExplicar, 2),
            ['sin_explicar' => $sinExplicar]
        );
    }
}
