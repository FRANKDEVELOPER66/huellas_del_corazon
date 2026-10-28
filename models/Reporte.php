<?php

namespace Model;

// Consultas de solo lectura sobre las vistas del dashboard. Devuelven arrays (no objetos).
class Reporte
{
    // Cuadro real por venta: estimado vs real. $mes con formato 'YYYY-MM' (opcional)
    public static function ventas($mes = null)
    {
        if ($mes) {
            return ActiveRecord::fetchArray(
                "SELECT * FROM v_ventas WHERE DATE_FORMAT(fecha_venta, '%Y-%m') = ? ORDER BY fecha_venta DESC, id DESC",
                [$mes]
            );
        }
        return ActiveRecord::fetchArray('SELECT * FROM v_ventas ORDER BY fecha_venta DESC, id DESC');
    }

    public static function balanceMensual()
    {
        return ActiveRecord::fetchArray('SELECT * FROM v_balance_mensual ORDER BY mes DESC');
    }

    public static function balanceDelMes($mes)
    {
        $r = ActiveRecord::fetchArray('SELECT * FROM v_balance_mensual WHERE mes = ?', [$mes]);
        return $r ? $r[0] : null;
    }

    public static function flujoCajaMensual()
    {
        return ActiveRecord::fetchArray('SELECT * FROM v_flujo_caja_mensual ORDER BY mes DESC');
    }

    public static function gastosPorCategoria($mes)
    {
        return ActiveRecord::fetchArray(
            'SELECT * FROM v_gastos_por_categoria WHERE mes = ? ORDER BY total DESC',
            [$mes]
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ESTADO DE RESULTADOS y BALANCE
    //  · Una venta cuenta en el mes en que se ENTREGA (entregado_en).
    //  · Costo de ventas = lo que costó fabricar esas piezas (filamento, luz y máquina, empaque,
    //    pintora/envíos/otros: reales si ya los registraste, si no los de la cotización).
    //  · Gastos de operación = gastos generales (sin venta) de categorías tipo "operativo".
    //  · Las compras de filamento (tipo "inventario") no son gasto del mes: van al inventario.
    // ─────────────────────────────────────────────────────────────────────────

    // Todas las ventas entregadas (y las antiguas sin pedido), con sus costos por componente
    private static function ventasEntregadas(): array
    {
        return ActiveRecord::fetchArray(
            "SELECT v.id, v.descripcion, v.ingreso_neto,
                    COALESCE(q.costo_material, 0)                  AS c_material,
                    COALESCE(q.costo_luz + q.costo_desgaste, 0)    AS c_maquina,
                    COALESCE(q.costo_empaque, 0)                   AS c_empaque,
                    COALESCE(gd.total, q.costo_pintura + q.costo_envio + q.otros_insumos, 0) AS c_directos,
                    (gd.total IS NULL AND COALESCE(q.costo_pintura + q.costo_envio + q.otros_insumos, 0) > 0) AS directos_estimados,
                    COALESCE(p.entregado_en, v.fecha_venta)        AS fecha_res
             FROM ventas v
             LEFT JOIN pedidos p       ON p.id = v.pedido_id
             LEFT JOIN cotizaciones q  ON q.id = v.cotizacion_id
             LEFT JOIN (SELECT venta_id, SUM(monto) AS total FROM gastos WHERE venta_id IS NOT NULL GROUP BY venta_id) gd
                    ON gd.venta_id = v.id
             WHERE v.pedido_id IS NULL OR p.estado = 'entregado'"
        );
    }

    public static function estadoResultados(string $mes): array
    {
        $ini = $mes . '-01';
        $fin = date('Y-m-t', strtotime($ini));

        $costo = ['material' => 0.0, 'maquina' => 0.0, 'empaque' => 0.0, 'directos' => 0.0];
        $ventas = 0.0;
        $estimados = 0;
        $detalle = [];

        foreach (self::ventasEntregadas() as $f) {
            if (substr((string) $f['fecha_res'], 0, 7) !== $mes) {
                continue;
            }
            $c = (float) $f['c_material'] + (float) $f['c_maquina'] + (float) $f['c_empaque'] + (float) $f['c_directos'];
            $costo['material'] += (float) $f['c_material'];
            $costo['maquina']  += (float) $f['c_maquina'];
            $costo['empaque']  += (float) $f['c_empaque'];
            $costo['directos'] += (float) $f['c_directos'];
            $ventas += (float) $f['ingreso_neto'];
            $estimados += (int) $f['directos_estimados'];
            $detalle[] = [
                'id'          => (int) $f['id'],
                'descripcion' => $f['descripcion'],
                'fecha'       => $f['fecha_res'],
                'precio'      => round((float) $f['ingreso_neto'], 2),
                'costo'       => round($c, 2),
                'ganancia'    => round((float) $f['ingreso_neto'] - $c, 2),
                'estimado'    => (int) $f['directos_estimados'],
            ];
        }

        $costo['total'] = $costo['material'] + $costo['maquina'] + $costo['empaque'] + $costo['directos'];
        $costo['estimados'] = $estimados;
        foreach (['material', 'maquina', 'empaque', 'directos', 'total'] as $k) {
            $costo[$k] = round($costo[$k], 2);
        }

        $bruta = round($ventas - $costo['total'], 2);

        $operativos = ActiveRecord::fetchArray(
            "SELECT cg.nombre AS categoria, SUM(g.monto) AS total
             FROM gastos g JOIN categorias_gasto cg ON cg.id = g.categoria_id
             WHERE g.venta_id IS NULL AND cg.tipo = 'operativo' AND g.fecha BETWEEN ? AND ?
             GROUP BY cg.nombre ORDER BY total DESC",
            [$ini, $fin]
        );
        $totalOperativos = 0.0;
        foreach ($operativos as &$o) {
            $o['total'] = round((float) $o['total'], 2);
            $totalOperativos += $o['total'];
        }
        unset($o);

        $compras = ActiveRecord::fetchArray(
            "SELECT COALESCE(SUM(g.monto), 0) AS total
             FROM gastos g JOIN categorias_gasto cg ON cg.id = g.categoria_id
             WHERE cg.tipo = 'inventario' AND g.fecha BETWEEN ? AND ?",
            [$ini, $fin]
        );

        $neta = round($bruta - $totalOperativos, 2);

        return [
            'mes'             => $mes,
            'piezas'          => count($detalle),
            'ventas'          => round($ventas, 2),
            'costo'           => $costo,
            'utilidad_bruta'  => $bruta,
            'margen_bruto'    => $ventas > 0 ? round($bruta / $ventas * 100, 1) : null,
            'operativos'      => $operativos,
            'total_operativos' => round($totalOperativos, 2),
            'utilidad_neta'   => $neta,
            'margen_neto'     => $ventas > 0 ? round($neta / $ventas * 100, 1) : null,
            'material'        => [
                'comprado' => round((float) $compras[0]['total'], 2),
                'usado'    => $costo['material'],
            ],
            'piezas_detalle'  => $detalle,
        ];
    }

    // Lo que hay en producción ahora (vendido, todavía sin entregar)
    public static function enProduccion(): array
    {
        $r = ActiveRecord::fetchArray(
            "SELECT COUNT(*) AS piezas, COALESCE(SUM(v.ingreso_neto), 0) AS valor, COALESCE(SUM(pg.pagado), 0) AS anticipos
             FROM ventas v
             JOIN pedidos p ON p.id = v.pedido_id
             LEFT JOIN (SELECT venta_id, SUM(monto) AS pagado FROM pagos GROUP BY venta_id) pg ON pg.venta_id = v.id
             WHERE p.estado NOT IN ('entregado', 'cancelado')"
        );
        return [
            'piezas'    => (int) $r[0]['piezas'],
            'valor'     => round((float) $r[0]['valor'], 2),
            'anticipos' => round((float) $r[0]['anticipos'], 2),
        ];
    }

    // Resultados de todos los meses (para la tabla histórica)
    public static function resultadosPorMes(): array
    {
        $meses = [];
        foreach (self::ventasEntregadas() as $f) {
            $m = substr((string) $f['fecha_res'], 0, 7);
            $meses[$m] ??= ['mes' => $m, 'ventas' => 0.0, 'costo' => 0.0, 'operativos' => 0.0];
            $meses[$m]['ventas'] += (float) $f['ingreso_neto'];
            $meses[$m]['costo'] += (float) $f['c_material'] + (float) $f['c_maquina'] + (float) $f['c_empaque'] + (float) $f['c_directos'];
        }

        $ops = ActiveRecord::fetchArray(
            "SELECT DATE_FORMAT(g.fecha, '%Y-%m') AS mes, SUM(g.monto) AS total
             FROM gastos g JOIN categorias_gasto cg ON cg.id = g.categoria_id
             WHERE g.venta_id IS NULL AND cg.tipo = 'operativo'
             GROUP BY DATE_FORMAT(g.fecha, '%Y-%m')"
        );
        foreach ($ops as $o) {
            $meses[$o['mes']] ??= ['mes' => $o['mes'], 'ventas' => 0.0, 'costo' => 0.0, 'operativos' => 0.0];
            $meses[$o['mes']]['operativos'] += (float) $o['total'];
        }

        krsort($meses);
        $filas = [];
        foreach ($meses as $m) {
            $bruta = $m['ventas'] - $m['costo'];
            $neta = $bruta - $m['operativos'];
            $filas[] = [
                'mes'        => $m['mes'],
                'ventas'     => round($m['ventas'], 2),
                'costo'      => round($m['costo'], 2),
                'bruta'      => round($bruta, 2),
                'operativos' => round($m['operativos'], 2),
                'neta'       => round($neta, 2),
                'margen'     => $m['ventas'] > 0 ? round($neta / $m['ventas'] * 100, 1) : null,
            ];
        }
        return $filas;
    }

    // Balance al cierre del mes: qué tienes y qué debes
    public static function balanceAl(string $mes): array
    {
        $fin = date('Y-m-t', strtotime($mes . '-01'));
        $num = fn(array $r, string $k = 'total'): float => round((float) ($r[0][$k] ?? 0), 2);

        $cobros = $num(ActiveRecord::fetchArray('SELECT COALESCE(SUM(monto), 0) AS total FROM pagos WHERE fecha <= ?', [$fin]));
        $gastos = $num(ActiveRecord::fetchArray('SELECT COALESCE(SUM(monto), 0) AS total FROM gastos WHERE fecha <= ?', [$fin]));

        // “Entregada al cierre”: pedido entregado (o venta antigua sin pedido) con fecha de entrega <= fin
        $entregada = "((v.pedido_id IS NULL OR p.estado = 'entregado') AND COALESCE(p.entregado_en, v.fecha_venta) <= ?)";

        $porCobrar = $num(ActiveRecord::fetchArray(
            "SELECT COALESCE(SUM(GREATEST(v.precio_venta - COALESCE(pg.pagado, 0), 0)), 0) AS total
             FROM ventas v
             LEFT JOIN pedidos p ON p.id = v.pedido_id
             LEFT JOIN (SELECT venta_id, SUM(monto) AS pagado FROM pagos WHERE fecha <= ? GROUP BY venta_id) pg ON pg.venta_id = v.id
             WHERE $entregada",
            [$fin, $fin]
        ));

        $anticipos = $num(ActiveRecord::fetchArray(
            "SELECT COALESCE(SUM(pg.pagado), 0) AS total
             FROM ventas v
             LEFT JOIN pedidos p ON p.id = v.pedido_id
             JOIN (SELECT venta_id, SUM(monto) AS pagado FROM pagos WHERE fecha <= ? GROUP BY venta_id) pg ON pg.venta_id = v.id
             WHERE v.fecha_venta <= ? AND NOT $entregada",
            [$fin, $fin, $fin]
        ));

        // Costo de piezas ya entregadas que todavía no pagas (pintora, envíos y otros): lo debes
        $porPagarProv = $num(ActiveRecord::fetchArray(
            "SELECT COALESCE(SUM(GREATEST(COALESCE(q.costo_pintura + q.costo_envio + q.otros_insumos, 0) - COALESCE(gd.total, 0), 0)), 0) AS total
             FROM ventas v
             LEFT JOIN pedidos p ON p.id = v.pedido_id
             LEFT JOIN cotizaciones q ON q.id = v.cotizacion_id
             LEFT JOIN (SELECT venta_id, SUM(monto) AS total FROM gastos WHERE venta_id IS NOT NULL AND fecha <= ? GROUP BY venta_id) gd ON gd.venta_id = v.id
             WHERE $entregada",
            [$fin, $fin]
        ));

        $compras = $num(ActiveRecord::fetchArray(
            "SELECT COALESCE(SUM(g.monto), 0) AS total
             FROM gastos g JOIN categorias_gasto cg ON cg.id = g.categoria_id
             WHERE cg.tipo = 'inventario' AND g.fecha <= ?",
            [$fin]
        ));

        $usado = $num(ActiveRecord::fetchArray(
            'SELECT COALESCE(SUM(q.costo_material), 0) AS total
             FROM ventas v JOIN cotizaciones q ON q.id = v.cotizacion_id
             WHERE v.fecha_venta <= ?',
            [$fin]
        ));

        $enProceso = $num(ActiveRecord::fetchArray(
            "SELECT COALESCE(SUM(COALESCE(q.costo_material, 0) + COALESCE(gd.total, 0)), 0) AS total
             FROM ventas v
             LEFT JOIN pedidos p ON p.id = v.pedido_id
             LEFT JOIN cotizaciones q ON q.id = v.cotizacion_id
             LEFT JOIN (SELECT venta_id, SUM(monto) AS total FROM gastos WHERE venta_id IS NOT NULL AND fecha <= ? GROUP BY venta_id) gd ON gd.venta_id = v.id
             WHERE v.fecha_venta <= ? AND NOT $entregada",
            [$fin, $fin, $fin]
        ));

        $aportes = $num(ActiveRecord::fetchArray("SELECT COALESCE(SUM(monto), 0) AS total FROM aportes WHERE tipo = 'aporte' AND fecha <= ?", [$fin]));
        $retiros = $num(ActiveRecord::fetchArray("SELECT COALESCE(SUM(monto), 0) AS total FROM aportes WHERE tipo = 'retiro' AND fecha <= ?", [$fin]));

        $efectivo = round($cobros + $aportes - $retiros - $gastos, 2);
        $inventario = round($compras - $usado, 2);
        $activos = round($efectivo + $porCobrar + $inventario + $enProceso, 2);

        return [
            'al'            => $fin,
            'cobros'        => $cobros,
            'aportes'       => $aportes,
            'retiros'       => $retiros,
            'pagos'         => $gastos,
            'efectivo'      => $efectivo,
            'por_cobrar'    => $porCobrar,
            'compras_inv'   => $compras,
            'material_usado' => $usado,
            'inventario'    => $inventario,
            'en_proceso'    => $enProceso,
            'activos'       => $activos,
            'anticipos'     => $anticipos,
            'por_pagar'     => $porPagarProv,
            'pasivos'       => round($anticipos + $porPagarProv, 2),
            'patrimonio'    => round($activos - $anticipos - $porPagarProv, 2),
        ];
    }

    // Caja del negocio al día de hoy
    //  caja       = cobrado + aportes − retiros − pagado
    //  por_pagar  = lo que todavía debes pagar de las piezas vendidas (pintora, envíos y otros de la cotización
    //               que aún no registras como gasto real), estén entregadas o no
    //  disponible = caja − por_pagar: lo que puedes gastar hoy (por ejemplo en filamento) sin quedarte corto
    public static function caja(): array
    {
        $t = ActiveRecord::fetchArray(
            "SELECT (SELECT COALESCE(SUM(monto), 0) FROM pagos)                              AS cobros,
                    (SELECT COALESCE(SUM(monto), 0) FROM aportes WHERE tipo = 'aporte')      AS aportes,
                    (SELECT COALESCE(SUM(monto), 0) FROM aportes WHERE tipo = 'retiro')      AS retiros,
                    (SELECT COALESCE(SUM(monto), 0) FROM gastos)                             AS gastos"
        )[0];

        $p = ActiveRecord::fetchArray(
            "SELECT COALESCE(SUM(GREATEST(COALESCE(q.costo_pintura + q.costo_envio + q.otros_insumos, 0) - COALESCE(gd.total, 0), 0)), 0) AS total
             FROM ventas v
             LEFT JOIN pedidos p ON p.id = v.pedido_id
             LEFT JOIN cotizaciones q ON q.id = v.cotizacion_id
             LEFT JOIN (SELECT venta_id, SUM(monto) AS total FROM gastos WHERE venta_id IS NOT NULL GROUP BY venta_id) gd ON gd.venta_id = v.id
             WHERE p.estado IS NULL OR p.estado <> 'cancelado'"
        );

        $cobros = round((float) $t['cobros'], 2);
        $aportes = round((float) $t['aportes'], 2);
        $retiros = round((float) $t['retiros'], 2);
        $gastos = round((float) $t['gastos'], 2);
        $caja = round($cobros + $aportes - $retiros - $gastos, 2);
        $porPagar = round((float) $p[0]['total'], 2);

        return [
            'cobros'     => $cobros,
            'aportes'    => $aportes,
            'retiros'    => $retiros,
            'gastos'     => $gastos,
            'caja'       => $caja,
            'por_pagar'  => $porPagar,
            'disponible' => round($caja - $porPagar, 2),
        ];
    }

    // Control de pagos a la pintora, pieza por pieza: lo acordado en la cotización contra lo que ya le pagaste
    public static function pintora(): array
    {
        $filas = ActiveRecord::fetchArray(
            "SELECT v.id AS venta_id, v.descripcion, pt.id AS pintor_id, pt.nombre AS pintor,
                    p.estado AS etapa, q.costo_pintura AS acordado, COALESCE(gp.total, 0) AS pagado
             FROM ventas v
             JOIN cotizaciones q ON q.id = v.cotizacion_id
             LEFT JOIN pedidos p ON p.id = v.pedido_id
             LEFT JOIN pintores pt ON pt.id = q.pintor_id
             LEFT JOIN (SELECT g.venta_id, SUM(g.monto) AS total
                        FROM gastos g JOIN categorias_gasto cg ON cg.id = g.categoria_id
                        WHERE g.venta_id IS NOT NULL AND cg.nombre LIKE 'Pintura%'
                        GROUP BY g.venta_id) gp ON gp.venta_id = v.id
             WHERE q.costo_pintura > 0 AND (p.estado IS NULL OR p.estado <> 'cancelado')
             ORDER BY v.fecha_venta DESC, v.id DESC"
        );

        $pendientes = [];
        $totalPendiente = 0.0;
        $totalPagado = 0.0;
        foreach ($filas as $f) {
            $acordado = (float) $f['acordado'];
            $pagado = (float) $f['pagado'];
            $totalPagado += $pagado;
            $pendiente = max($acordado - $pagado, 0);
            if ($pendiente > 0.005) {
                $totalPendiente += $pendiente;
                $pendientes[] = [
                    'venta_id'    => (int) $f['venta_id'],
                    'descripcion' => $f['descripcion'],
                    'pintor_id'   => $f['pintor_id'] ? (int) $f['pintor_id'] : null,
                    'pintor'      => $f['pintor'],
                    'etapa'       => $f['etapa'],
                    'acordado'    => round($acordado, 2),
                    'pagado'      => round($pagado, 2),
                    'pendiente'   => round($pendiente, 2),
                ];
            }
        }

        return [
            'pendientes'      => $pendientes,
            'total_pendiente' => round($totalPendiente, 2),
            'total_pagado'    => round($totalPagado, 2),
        ];
    }
}
