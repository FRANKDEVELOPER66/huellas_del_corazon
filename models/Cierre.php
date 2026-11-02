<?php

namespace Model;

// Cierre de período. Un cierre dice: "hasta esta fecha todo está revisado contra el banco".
// Mientras esté vigente, nada con fecha igual o anterior se puede registrar, editar ni borrar.
// Solo se puede reabrir el ÚLTIMO cierre (con un motivo), así siempre queda una línea de tiempo ordenada.
class Cierre
{
    // ¿Ya existe la tabla? (si todavía no corriste sql/cierre_periodo.sql, el sistema sigue funcionando sin cierres)
    public static function hayTabla(): bool
    {
        static $hay = null;
        if ($hay === null) {
            $hay = count(ActiveRecord::fetchArray("SHOW TABLES LIKE 'cierres_periodo'")) > 0;
        }
        return $hay;
    }

    public static function fechaValida($valor): bool
    {
        $d = trim((string) $valor);
        $f = \DateTime::createFromFormat('Y-m-d', $d);
        return $f && $f->format('Y-m-d') === $d;
    }

    // Cierres vigentes (no reabiertos), del más antiguo al más reciente
    public static function vigentes(): array
    {
        if (!self::hayTabla()) {
            return [];
        }
        return ActiveRecord::fetchArray(
            'SELECT id, desde, hasta, saldo_banco, saldo_sistema, diferencia, no_en_banco, sin_explicar, nota, detalle, creado_en
             FROM cierres_periodo WHERE reabierto_en IS NULL ORDER BY hasta, id'
        );
    }

    public static function ultimo(): ?array
    {
        $v = self::vigentes();
        return $v ? $v[count($v) - 1] : null;
    }

    // Última fecha cerrada (YYYY-MM-DD) o null
    public static function ultimaFecha(): ?string
    {
        $u = self::ultimo();
        return $u ? (string) $u['hasta'] : null;
    }

    // Mensaje de error si la fecha cae en un período cerrado; null si se puede
    public static function bloqueo($fecha): ?string
    {
        if (!self::fechaValida($fecha)) {
            return null;
        }
        $hasta = self::ultimaFecha();
        if ($hasta === null || $fecha > $hasta) {
            return null;
        }
        return 'El período hasta el ' . self::dmy($hasta) . ' está cerrado y conciliado: no se puede registrar ni cambiar nada con fecha del '
            . self::dmy($fecha) . '. Si hay un error, reabre el último cierre en Gastos > Cierre de período.';
    }

    public static function dmy(string $f): string
    {
        return date('d/m/Y', strtotime($f));
    }

    // Lo que la caja de la app tiene a esa fecha (cobros + aportes − retiros − devoluciones − gastos, con fecha ≤ hasta)
    public static function saldoSistemaAl(string $hasta): float
    {
        $t = ActiveRecord::fetchArray(
            "SELECT (SELECT COALESCE(SUM(monto), 0) FROM pagos WHERE fecha <= ?)                              AS cobros,
                    (SELECT COALESCE(SUM(monto), 0) FROM aportes WHERE tipo = 'aporte' AND fecha <= ?)         AS aportes,
                    (SELECT COALESCE(SUM(monto), 0) FROM aportes WHERE tipo = 'retiro' AND fecha <= ?)         AS retiros,
                    (SELECT COALESCE(SUM(monto), 0) FROM aportes WHERE tipo = 'devolucion' AND fecha <= ?)     AS devoluciones,
                    (SELECT COALESCE(SUM(monto), 0) FROM gastos WHERE fecha <= ?)                              AS gastos",
            [$hasta, $hasta, $hasta, $hasta, $hasta]
        )[0];

        return round((float) $t['cobros'] + (float) $t['aportes'] - (float) $t['retiros'] - (float) $t['devoluciones'] - (float) $t['gastos'], 2);
    }

    // Movimientos que pasan por la cuenta del banco, hasta una fecha. Quedan fuera los gastos "de mi bolsillo"
    // y su aporte ligado (no pasaron por la cuenta del negocio).
    private static function movimientosHasta(string $hasta): array
    {
        return ActiveRecord::fetchArray(
            "SELECT * FROM (
                SELECT 'cobro' AS tipo, CONCAT('pg', p.id) AS ref, p.fecha, p.monto AS monto,
                       IF(p.monto < 0, CONCAT('Devolución al cliente (cancelación): ', v.descripcion), CONCAT('Cobro de ', p.tipo, ': ', v.descripcion)) AS descripcion
                FROM pagos p JOIN ventas v ON v.id = p.venta_id
                WHERE p.fecha <= ?
                UNION ALL
                SELECT a.tipo, CONCAT('ap', a.id), a.fecha, IF(a.tipo = 'aporte', a.monto, -a.monto), a.descripcion
                FROM aportes a
                WHERE a.gasto_id IS NULL AND a.fecha <= ?
                UNION ALL
                SELECT 'gasto', CONCAT('ga', g.id), g.fecha, -g.monto, CONCAT(cg.nombre, ': ', g.descripcion)
                FROM gastos g JOIN categorias_gasto cg ON cg.id = g.categoria_id
                WHERE g.origen = 'caja' AND g.fecha <= ?
             ) m
             ORDER BY fecha DESC, ref DESC",
            [$hasta, $hasta, $hasta]
        );
    }

    // Referencias que en el cierre anterior quedaron marcadas como "aún no pasa por el banco"
    public static function arrastre(): array
    {
        $u = self::ultimo();
        if (!$u || !$u['detalle']) {
            return [];
        }
        $d = json_decode((string) $u['detalle'], true);
        $refs = [];
        foreach (is_array($d) ? $d : [] as $x) {
            if (!empty($x['ref'])) {
                $refs[] = (string) $x['ref'];
            }
        }
        return $refs;
    }

    // Movimientos del período a revisar: los que caen entre desde y hasta, más los que seguían sin verse en el banco
    public static function movimientos(string $desde, string $hasta): array
    {
        $arrastre = self::arrastre();
        $salida = [];
        foreach (self::movimientosHasta($hasta) as $m) {
            $arrastrado = in_array($m['ref'], $arrastre, true);
            if ($m['fecha'] >= $desde || $arrastrado) {
                $m['monto'] = round((float) $m['monto'], 2);
                $m['arrastrado'] = ($m['fecha'] < $desde && $arrastrado) ? 1 : 0;
                $salida[] = $m;
            }
        }
        return $salida;
    }

    // Primer día del período que se va a cerrar: el día siguiente al último cierre,
    // o la fecha del primer movimiento registrado si es el primer cierre
    public static function desde(): string
    {
        $hasta = self::ultimaFecha();
        if ($hasta !== null) {
            return date('Y-m-d', strtotime($hasta . ' +1 day'));
        }
        $r = ActiveRecord::fetchArray(
            "SELECT MIN(f) AS f FROM (
                SELECT MIN(fecha) AS f FROM pagos UNION ALL SELECT MIN(fecha) FROM aportes UNION ALL SELECT MIN(fecha) FROM gastos
             ) t"
        );
        return !empty($r[0]['f']) ? (string) $r[0]['f'] : date('Y-m-d');
    }

    // Estado de un mes (YYYY-MM): abierto, parcial (cerrado hasta un día de ese mes) o cerrado.
    // "conciliado" = el cierre que lo cubre cuadró con el banco (nada sin explicar).
    public static function estadoMes(string $mes): array
    {
        $ini = $mes . '-01';
        $fin = date('Y-m-t', strtotime($ini));
        $vig = self::vigentes();
        $max = $vig ? (string) $vig[count($vig) - 1]['hasta'] : null;

        if ($max === null || $max < $ini) {
            return ['estado' => 'abierto', 'conciliado' => false, 'hasta' => null, 'sin_explicar' => 0, 'cierre_id' => null];
        }

        if ($max >= $fin) {
            // El cierre que cubre el último día del mes
            foreach ($vig as $c) {
                if ($c['hasta'] >= $fin) {
                    $sin = round((float) $c['sin_explicar'], 2);
                    return ['estado' => 'cerrado', 'conciliado' => abs($sin) < 0.005, 'hasta' => $c['hasta'], 'sin_explicar' => $sin, 'cierre_id' => (int) $c['id']];
                }
            }
        }

        // Cerrado solo hasta un día de este mes: conciliado si todos los cierres de este mes cuadraron
        $sin = 0.0;
        foreach ($vig as $c) {
            if ($c['hasta'] >= $ini && $c['hasta'] <= $fin) {
                $sin += abs((float) $c['sin_explicar']);
            }
        }
        return ['estado' => 'parcial', 'conciliado' => $sin < 0.005, 'hasta' => $max, 'sin_explicar' => round($sin, 2), 'cierre_id' => (int) $vig[count($vig) - 1]['id']];
    }
}
