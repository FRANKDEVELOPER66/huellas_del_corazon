<?php

namespace Model;

// Consultas de solo lectura sobre las vistas del dashboard. Devuelven arrays (no objetos).
class Reporte
{
    // Cuadro real por venta: estimado vs real. $mes con formato 'YYYY-MM' (opcional)
    // ─────────────────────────────────────────────────────────────────────────
    // COSTOS DIRECTOS DE CADA PIEZA, POR CONCEPTO (pintora, envío y otros insumos)
    //  · Sin saldar: el costo es el MAYOR entre lo acordado en la cotización y lo ya pagado,
    //    y lo que falta por pagar es acordado − pagado.
    //  · Saldado (cierres_costo): el costo es solo lo realmente pagado y ya no falta nada.
    // Usan los alias: v (ventas), q (cotizaciones) y gp, cp, ce, co de joinsCostos().
    // ─────────────────────────────────────────────────────────────────────────
    private const COSTO_DIRECTO = "(CASE WHEN cp.id IS NOT NULL THEN COALESCE(gp.pintura, 0) ELSE GREATEST(COALESCE(q.costo_pintura, 0), COALESCE(gp.pintura, 0)) END
        + CASE WHEN ce.id IS NOT NULL THEN COALESCE(gp.envio, 0) ELSE GREATEST(COALESCE(q.costo_envio, 0), COALESCE(gp.envio, 0)) END
        + CASE WHEN co.id IS NOT NULL THEN COALESCE(gp.otros, 0) ELSE GREATEST(COALESCE(q.otros_insumos, 0), COALESCE(gp.otros, 0)) END)";

    private const PENDIENTE_DIRECTO = "(CASE WHEN cp.id IS NOT NULL THEN 0 ELSE GREATEST(COALESCE(q.costo_pintura, 0) - COALESCE(gp.pintura, 0), 0) END
        + CASE WHEN ce.id IS NOT NULL THEN 0 ELSE GREATEST(COALESCE(q.costo_envio, 0) - COALESCE(gp.envio, 0), 0) END
        + CASE WHEN co.id IS NOT NULL THEN 0 ELSE GREATEST(COALESCE(q.otros_insumos, 0) - COALESCE(gp.otros, 0), 0) END)";

    // JOINs que traen lo pagado por concepto (gp) y si cada concepto está saldado (cp, ce, co).
    // Con $conFecha = true agrega un "?" (fecha de corte) que debe ir primero en los parámetros.
    public static function joinsCostos(bool $conFecha = false): string
    {
        $f = $conFecha ? ' AND g.fecha <= ?' : '';
        return "LEFT JOIN (SELECT g.venta_id,
                        SUM(CASE WHEN cg.concepto = 'pintura' THEN g.monto ELSE 0 END) AS pintura,
                        SUM(CASE WHEN cg.concepto = 'envio' THEN g.monto ELSE 0 END) AS envio,
                        SUM(CASE WHEN cg.concepto = 'pintura' OR cg.concepto = 'envio' THEN 0 ELSE g.monto END) AS otros
                 FROM gastos g JOIN categorias_gasto cg ON cg.id = g.categoria_id
                 WHERE g.venta_id IS NOT NULL$f
                 GROUP BY g.venta_id) gp ON gp.venta_id = v.id
             LEFT JOIN cierres_costo cp ON cp.venta_id = v.id AND cp.concepto = 'pintura'
             LEFT JOIN cierres_costo ce ON ce.venta_id = v.id AND ce.concepto = 'envio'
             LEFT JOIN cierres_costo co ON co.venta_id = v.id AND co.concepto = 'otros'";
    }

    // Filamento perdido en impresiones fallidas de cada pieza (por pedido). Se suma al costo de material de esa pieza.
    // Usa el alias fa (y v, de ventas). Si todavía no existe la tabla de consumos, no suma nada.
    private static function joinFallas(): string
    {
        // cambio = lo que costó de más (o de menos, si es negativo) cambiar los colores de la cotización antes de imprimir (Pedidos > filamento)
        $falla = Inventario::hayConsumos()
            ? "SELECT pedido_id, SUM(costo) AS falla, 0 AS cambio FROM carrete_consumos WHERE tipo = 'falla' GROUP BY pedido_id"
            : "SELECT 0 AS pedido_id, 0 AS falla, 0 AS cambio FROM DUAL WHERE 1 = 0";
        $cambio = Inventario::hayPlanReal()
            ? " UNION ALL SELECT pedido_id, 0, sobrecosto FROM pedido_filamento_cambio WHERE sobrecosto <> 0"
            : '';
        return "LEFT JOIN (SELECT pedido_id, SUM(falla) AS falla, SUM(cambio) AS cambio FROM ($falla$cambio) u GROUP BY pedido_id) fa ON fa.pedido_id = v.pedido_id";
    }

    // Una venta con su cuadro: estimado (cotización) contra real. Se arma aquí, no en una vista de MySQL.
    private static function sqlVentas(): string
    {
        return "SELECT t.*, ROUND(t.ganancia_real / NULLIF(t.ingreso_neto, 0) * 100, 1) AS margen_real_pct
                FROM (
                  SELECT v.id, v.fecha_venta, c.nombre AS cliente, v.descripcion, v.precio_venta,
                         CASE WHEN p.estado = 'cancelado' THEN COALESCE(cn.retenido, 0) ELSE v.ingreso_neto END AS ingreso_neto,
                         COALESCE(pg.pagado, 0) AS pagado,
                         CASE WHEN p.estado = 'cancelado' THEN 0 ELSE v.precio_venta - COALESCE(pg.pagado, 0) END AS saldo,
                         p.estado AS estado_pedido, cn.motivo AS motivo_cancelacion, cn.fecha AS fecha_cancelacion, cn.devuelto AS devuelto_cancelacion,
                         q.costo_total AS costo_estimado,
                         COALESCE(gp.pintura, 0) + COALESCE(gp.envio, 0) + COALESCE(gp.otros, 0) AS gastos_directos_reales,
                         (" . self::PENDIENTE_DIRECTO . " > 0.005) AS gastos_pendientes,
                         v.ingreso_neto - q.costo_total AS ganancia_estimada,
                         COALESCE(fa.falla, 0) AS costo_fallas,
                         COALESCE(fa.cambio, 0) AS costo_cambio,
                         CASE WHEN p.estado = 'cancelado'
                              THEN COALESCE(cn.retenido, 0) - COALESCE(cn.costo_material, 0) - (COALESCE(gp.pintura, 0) + COALESCE(gp.envio, 0) + COALESCE(gp.otros, 0))
                              ELSE v.ingreso_neto - (COALESCE(q.costo_material + q.costo_luz + q.costo_desgaste + q.costo_empaque, 0) + COALESCE(fa.falla, 0) + COALESCE(fa.cambio, 0) + " . self::COSTO_DIRECTO . ") END AS ganancia_real
                  FROM ventas v
                  JOIN clientes c ON c.id = v.cliente_id
                  LEFT JOIN pedidos p ON p.id = v.pedido_id
                  " . Cancelacion::join() . "
                  LEFT JOIN cotizaciones q ON q.id = v.cotizacion_id
                  LEFT JOIN (SELECT venta_id, SUM(monto) AS pagado FROM pagos GROUP BY venta_id) pg ON pg.venta_id = v.id
                  " . self::joinFallas() . "
                  " . self::joinsCostos() . "
                ) t";
    }

    public static function ventaPorId(int $id): ?array
    {
        $r = ActiveRecord::fetchArray(self::sqlVentas() . ' WHERE t.id = ?', [$id]);
        return $r[0] ?? null;
    }

    public static function ventas($mes = null)
    {
        if ($mes) {
            return ActiveRecord::fetchArray(
                self::sqlVentas() . " WHERE DATE_FORMAT(t.fecha_venta, '%Y-%m') = ? ORDER BY t.fecha_venta DESC, t.id DESC",
                [$mes]
            );
        }
        return ActiveRecord::fetchArray(self::sqlVentas() . ' ORDER BY t.fecha_venta DESC, t.id DESC');
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
        $canc = "(p.estado = 'cancelado')";
        return ActiveRecord::fetchArray(
            "SELECT v.id, v.descripcion,
                    CASE WHEN $canc THEN COALESCE(cn.retenido, 0) ELSE v.ingreso_neto END AS ingreso_neto,
                    CASE WHEN $canc THEN COALESCE(cn.costo_material, 0) ELSE COALESCE(q.costo_material, 0) + COALESCE(fa.falla, 0) + COALESCE(fa.cambio, 0) END AS c_material,
                    CASE WHEN $canc THEN 0 ELSE COALESCE(q.costo_luz + q.costo_desgaste, 0) END AS c_maquina,
                    CASE WHEN $canc THEN 0 ELSE COALESCE(q.costo_empaque, 0) END AS c_empaque,
                    CASE WHEN $canc THEN COALESCE(gp.pintura, 0) + COALESCE(gp.envio, 0) + COALESCE(gp.otros, 0) ELSE " . self::COSTO_DIRECTO . " END AS c_directos,
                    CASE WHEN $canc THEN 0 ELSE " . self::PENDIENTE_DIRECTO . " END AS pendiente_directos,
                    CASE WHEN $canc THEN cn.fecha ELSE COALESCE(p.entregado_en, v.fecha_venta) END AS fecha_res
             FROM ventas v
             LEFT JOIN pedidos p       ON p.id = v.pedido_id
             " . Cancelacion::join() . "
             LEFT JOIN cotizaciones q  ON q.id = v.cotizacion_id
             " . self::joinFallas() . "
             " . self::joinsCostos() . "
             WHERE v.pedido_id IS NULL OR p.estado = 'entregado' OR ($canc AND cn.id IS NOT NULL)"
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
            $estimados += (float) $f['pendiente_directos'] > 0.005 ? 1 : 0;
            $detalle[] = [
                'id'          => (int) $f['id'],
                'descripcion' => $f['descripcion'],
                'fecha'       => $f['fecha_res'],
                'precio'      => round((float) $f['ingreso_neto'], 2),
                'costo'       => round($c, 2),
                'ganancia'    => round((float) $f['ingreso_neto'] - $c, 2),
                'estimado'    => (float) $f['pendiente_directos'] > 0.005 ? 1 : 0,
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
        $cancelEf = "(p.estado = 'cancelado' AND cn.fecha <= '$fin')"; // cancelado y ya cancelado al cierre del mes
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
             " . Cancelacion::join() . "
             JOIN (SELECT venta_id, SUM(monto) AS pagado FROM pagos WHERE fecha <= ? GROUP BY venta_id) pg ON pg.venta_id = v.id
             WHERE v.fecha_venta <= ? AND NOT $entregada AND NOT $cancelEf",
            [$fin, $fin, $fin]
        ));

        // Costo de piezas ya entregadas que todavía no pagas (pintora, envíos y otros, salvo lo saldado): lo debes
        $porPagarProv = $num(ActiveRecord::fetchArray(
            "SELECT COALESCE(SUM(" . self::PENDIENTE_DIRECTO . "), 0) AS total
             FROM ventas v
             LEFT JOIN pedidos p ON p.id = v.pedido_id
             LEFT JOIN cotizaciones q ON q.id = v.cotizacion_id
             " . self::joinsCostos(true) . "
             WHERE " . $entregada,
            [$fin, $fin]
        ));

        // Filamento: compras y inventario inicial (compras_material) + compras viejas registradas solo como gasto
        $compras = $num(ActiveRecord::fetchArray(
            "SELECT (SELECT COALESCE(SUM(costo_total), 0) FROM compras_material WHERE fecha <= ?)
                  + (SELECT COALESCE(SUM(g.monto), 0)
                     FROM gastos g
                     JOIN categorias_gasto cg ON cg.id = g.categoria_id
                     LEFT JOIN compras_material cm ON cm.gasto_id = g.id
                     WHERE cg.tipo = 'inventario' AND cm.id IS NULL AND g.fecha <= ?) AS total",
            [$fin, $fin]
        ));

        $usado = $num(ActiveRecord::fetchArray(
            "SELECT COALESCE(SUM(CASE WHEN $cancelEf THEN COALESCE(cn.costo_material, 0) ELSE q.costo_material + COALESCE(fa.falla, 0) + COALESCE(fa.cambio, 0) END), 0) AS total
             FROM ventas v JOIN cotizaciones q ON q.id = v.cotizacion_id
             LEFT JOIN pedidos p ON p.id = v.pedido_id
             " . Cancelacion::join() . "
             " . self::joinFallas() . "
             WHERE v.fecha_venta <= ?",
            [$fin]
        ));

        $enProceso = $num(ActiveRecord::fetchArray(
            "SELECT COALESCE(SUM(COALESCE(q.costo_material, 0) + COALESCE(fa.falla, 0) + COALESCE(fa.cambio, 0) + COALESCE(gd.total, 0)), 0) AS total
             FROM ventas v
             LEFT JOIN pedidos p ON p.id = v.pedido_id
             " . Cancelacion::join() . "
             LEFT JOIN cotizaciones q ON q.id = v.cotizacion_id
             " . self::joinFallas() . "
             LEFT JOIN (SELECT venta_id, SUM(monto) AS total FROM gastos WHERE venta_id IS NOT NULL AND fecha <= ? GROUP BY venta_id) gd ON gd.venta_id = v.id
             WHERE v.fecha_venta <= ? AND NOT $entregada AND NOT $cancelEf",
            [$fin, $fin, $fin]
        ));

        $aportes = $num(ActiveRecord::fetchArray("SELECT COALESCE(SUM(monto), 0) AS total FROM aportes WHERE tipo = 'aporte' AND fecha <= ?", [$fin]));
        $retiros = $num(ActiveRecord::fetchArray("SELECT COALESCE(SUM(monto), 0) AS total FROM aportes WHERE tipo = 'retiro' AND fecha <= ?", [$fin]));
        $devoluciones = $num(ActiveRecord::fetchArray("SELECT COALESCE(SUM(monto), 0) AS total FROM aportes WHERE tipo = 'devolucion' AND fecha <= ?", [$fin]));

        // Correcciones de peso de los carretes (pesados en báscula, carrete roto o perdido): suben o bajan el inventario
        $ajustesInv = Inventario::hayCarretes()
            ? $num(ActiveRecord::fetchArray(
                'SELECT COALESCE(SUM(a.delta_g * k.costo / k.peso_inicial_g), 0) AS total
                 FROM carrete_ajustes a JOIN carretes k ON k.id = a.carrete_id
                 WHERE DATE(a.creado_en) <= ?',
                [$fin]
            ))
            : 0.0;

        $efectivo = round($cobros + $aportes - $retiros - $devoluciones - $gastos, 2);
        $inventario = round($compras - $usado + $ajustesInv, 2);
        $activos = round($efectivo + $porCobrar + $inventario + $enProceso, 2);

        return [
            'al'            => $fin,
            'cobros'        => $cobros,
            'aportes'       => $aportes,
            'retiros'       => $retiros,
            'devoluciones'  => $devoluciones,
            'pagos'         => $gastos,
            'efectivo'      => $efectivo,
            'por_cobrar'    => $porCobrar,
            'compras_inv'   => $compras,
            'material_usado' => $usado,
            'ajustes_inv'   => $ajustesInv,
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
    //  caja       = cobrado + aportes − retiros − devoluciones de capital − pagado
    //  por_pagar  = lo que todavía debes pagar de las piezas vendidas (pintora, envíos y otros de la cotización
    //               que aún no registras como gasto real), estén entregadas o no
    //  disponible = caja − por_pagar: lo que puedes gastar hoy (por ejemplo en filamento) sin quedarte corto
    public static function caja(): array
    {
        $t = ActiveRecord::fetchArray(
            "SELECT (SELECT COALESCE(SUM(monto), 0) FROM pagos)                              AS cobros,
                    (SELECT COALESCE(SUM(monto), 0) FROM aportes WHERE tipo = 'aporte')      AS aportes,
                    (SELECT COALESCE(SUM(monto), 0) FROM aportes WHERE tipo = 'retiro')      AS retiros,
                    (SELECT COALESCE(SUM(monto), 0) FROM aportes WHERE tipo = 'devolucion')  AS devoluciones,
                    (SELECT COALESCE(SUM(monto), 0) FROM gastos)                             AS gastos"
        )[0];

        // Lo que falta pagar de las piezas vendidas (pintora, envíos y otros), salvo lo que ya saldaste
        $p = ActiveRecord::fetchArray(
            "SELECT COALESCE(SUM(" . self::PENDIENTE_DIRECTO . "), 0) AS total
             FROM ventas v
             LEFT JOIN pedidos p ON p.id = v.pedido_id
             LEFT JOIN cotizaciones q ON q.id = v.cotizacion_id
             " . self::joinsCostos() . "
             WHERE p.estado IS NULL OR p.estado <> 'cancelado'"
        );

        $cobros = round((float) $t['cobros'], 2);
        $aportes = round((float) $t['aportes'], 2);
        $retiros = round((float) $t['retiros'], 2);
        $devoluciones = round((float) $t['devoluciones'], 2);
        $gastos = round((float) $t['gastos'], 2);
        $caja = round($cobros + $aportes - $retiros - $devoluciones - $gastos, 2);
        $porPagar = round((float) $p[0]['total'], 2);
        $disponible = round($caja - $porPagar, 2);

        // Ganancia acumulada = lo ganado con piezas entregadas − gastos de operación − lo que ya retiraste.
        // (La devolución de capital NO es ganancia: no entra aquí.)
        $ganancia = 0.0;
        $noEfectivo = 0.0; // material, luz, desgaste y empaque de lo entregado: no salen a nadie, hay que reponerlos
        foreach (self::ventasEntregadas() as $f) {
            $ganancia += (float) $f['ingreso_neto'] - ((float) $f['c_material'] + (float) $f['c_maquina'] + (float) $f['c_empaque'] + (float) $f['c_directos']);
            $noEfectivo += (float) $f['c_material'] + (float) $f['c_maquina'] + (float) $f['c_empaque'];
        }
        $ops = ActiveRecord::fetchArray(
            "SELECT COALESCE(SUM(g.monto), 0) AS total
             FROM gastos g JOIN categorias_gasto cg ON cg.id = g.categoria_id
             WHERE g.venta_id IS NULL AND cg.tipo = 'operativo'"
        );
        $gananciaAcumulada = round($ganancia - (float) $ops[0]['total'] - $retiros, 2);

        // Material por reponer = lo que costó fabricar lo entregado − lo que ya gastaste en comprar filamento
        $comprasFil = ActiveRecord::fetchArray(
            "SELECT (SELECT COALESCE(SUM(costo_total), 0) FROM compras_material WHERE origen <> 'inicial')
                  + (SELECT COALESCE(SUM(g.monto), 0)
                     FROM gastos g
                     JOIN categorias_gasto cg ON cg.id = g.categoria_id
                     LEFT JOIN compras_material cm ON cm.gasto_id = g.id
                     WHERE cg.tipo = 'inventario' AND cm.id IS NULL) AS total"
        );
        $reponer = round(max($noEfectivo - (float) $comprasFil[0]['total'], 0), 2);

        // El dinero disponible se reparte llenando en este orden:
        //   1) material por reponer  2) reserva de repuestos y equipo  3) ganancia que puedes retirar
        // Lo que sobre (por ejemplo tu capital) se queda junto a "para reponer": tampoco es ganancia.
        $meta = self::reservaMeta();
        $d = max($disponible, 0);
        $g = max($gananciaAcumulada, 0);
        $paraReponer = round(min($d, $reponer), 2);
        $resto = $d - $paraReponer;
        $reserva = round(min($resto, $meta, $g), 2);
        $retirable = round(max(min($resto - $reserva, $g - $reserva), 0), 2);
        $excedente = round($d - $paraReponer - $reserva - $retirable, 2); // capital y colchón sin asignar

        // Capital: lo que has puesto (aportes + filamento que ya tenías) y cuándo se puede empezar a devolver
        $ini = ActiveRecord::fetchArray("SELECT COALESCE(SUM(costo_total), 0) AS total FROM compras_material WHERE origen = 'inicial'");
        $inversion = round($aportes + (float) $ini[0]['total'], 2);
        $capitalNeto = round(max($inversion - $devoluciones, 0), 2);
        $multiplo = self::capitalMultiplo();
        $patrimonio = (float) self::balanceAl(date('Y-m'))['patrimonio'];
        $capitalMeta = round($inversion * $multiplo, 2);
        $capitalSano = $inversion > 0 && $patrimonio + 0.005 >= $capitalMeta;
        $devolvible = $capitalSano ? round(max(min($excedente, $capitalNeto), 0), 2) : 0.0;

        return [
            'cobros'             => $cobros,
            'aportes'            => $aportes,
            'retiros'            => $retiros,
            'devoluciones'       => $devoluciones,
            'gastos'             => $gastos,
            'caja'               => $caja,
            'por_pagar'          => $porPagar,
            'disponible'         => $disponible,
            'ganancia_acumulada' => $gananciaAcumulada,
            'ganancia_retirable' => $retirable,
            'reserva_meta'       => $meta,
            'reserva'            => $reserva,
            // todo lo que no es reserva ni ganancia: material por reponer + capital en el negocio
            'para_reponer'       => round($paraReponer + $excedente, 2),
            'reponer_pendiente'  => $reponer,
            'material_cubierto'  => $paraReponer,
            'capital_en_negocio' => $excedente,
            'inversion'          => $inversion,
            'capital_neto'       => $capitalNeto,
            'capital_multiplo'   => $multiplo,
            'capital_meta'       => $capitalMeta,
            'patrimonio'         => round($patrimonio, 2),
            'capital_sano'       => $capitalSano,
            'capital_devolvible' => $devolvible,
        ];
    }

    // Cuántas veces la inversión debe valer el negocio para poder devolver capital (por defecto 3)
    public static function capitalMultiplo(): float
    {
        try {
            $f = ActiveRecord::fetchArray("SELECT valor FROM ajustes WHERE clave = 'capital_multiplo'");
            return $f ? max((float) $f[0]['valor'], 1.0) : 3.0;
        } catch (\Throwable $e) {
            return 3.0;
        }
    }

    // Meta de reserva para repuestos y equipo (Q). Si todavía no existe la tabla de ajustes, no hay reserva.
    public static function reservaMeta(): float
    {
        try {
            $f = ActiveRecord::fetchArray("SELECT valor FROM ajustes WHERE clave = 'reserva_repuestos'");
            return $f ? max((float) $f[0]['valor'], 0.0) : 0.0;
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    // Pagos pendientes de cada pieza, concepto por concepto (pintora, envío, otros insumos):
    // lo acordado en la cotización contra lo ya pagado, sin contar lo que ya está saldado.
    public static function pendientesPiezas(): array
    {
        $filas = ActiveRecord::fetchArray(
            "SELECT v.id AS venta_id, v.descripcion, cl.nombre AS cliente, pt.id AS pintor_id, pt.nombre AS pintor, p.estado AS etapa,
                    COALESCE(q.costo_pintura, 0) AS ac_pintura, COALESCE(q.costo_envio, 0) AS ac_envio, COALESCE(q.otros_insumos, 0) AS ac_otros,
                    q.envio_pintora AS env_pintora, q.envio_cliente AS env_cliente,
                    COALESCE(gp.pintura, 0) AS pg_pintura, COALESCE(gp.envio, 0) AS pg_envio, COALESCE(gp.otros, 0) AS pg_otros,
                    (cp.id IS NOT NULL) AS ce_pintura, (ce.id IS NOT NULL) AS ce_envio, (co.id IS NOT NULL) AS ce_otros
             FROM ventas v
             LEFT JOIN pedidos p ON p.id = v.pedido_id
             LEFT JOIN clientes cl ON cl.id = COALESCE(v.cliente_id, p.cliente_id)
             LEFT JOIN cotizaciones q ON q.id = v.cotizacion_id
             LEFT JOIN pintores pt ON pt.id = q.pintor_id
             " . self::joinsCostos() . "
             WHERE p.estado IS NULL OR p.estado <> 'cancelado'
             ORDER BY v.fecha_venta DESC, v.id DESC"
        );

        $pendientes = [];
        $totalPendiente = 0.0;
        $totalPagado = 0.0;
        $porConcepto = ['pintura' => 0.0, 'envio' => 0.0, 'otros' => 0.0];

        foreach ($filas as $f) {
            foreach (['pintura', 'envio', 'otros'] as $c) {
                $acordado = (float) $f['ac_' . $c];
                $pagado = (float) $f['pg_' . $c];
                $totalPagado += $pagado;
                if ((int) $f['ce_' . $c] === 1) {
                    continue; // saldado
                }
                $pendiente = max($acordado - $pagado, 0);
                if ($pendiente > 0.005) {
                    $totalPendiente += $pendiente;
                    $porConcepto[$c] += $pendiente;
                    $pendientes[] = [
                        'venta_id'    => (int) $f['venta_id'],
                        'descripcion' => $f['descripcion'],
                        'cliente'     => $f['cliente'],
                        'concepto'    => $c,
                        'pintor_id'   => $f['pintor_id'] ? (int) $f['pintor_id'] : null,
                        'pintor'      => $f['pintor'],
                        'etapa'       => $f['etapa'],
                        'acordado'    => round($acordado, 2),
                        'pagado'      => round($pagado, 2),
                        'pendiente'   => round($pendiente, 2),
                        // Envío en dos tramos (Cobán → pintora, pintora → cliente), si la cotización los separó
                        'tramos'      => ($c === 'envio' && ($f['env_pintora'] !== null || $f['env_cliente'] !== null))
                            ? ['pintora' => round((float) $f['env_pintora'], 2), 'cliente' => round((float) $f['env_cliente'], 2)]
                            : null,
                    ];
                }
            }
        }

        return [
            'pendientes'      => $pendientes,
            'total_pendiente' => round($totalPendiente, 2),
            'total_pagado'    => round($totalPagado, 2),
            'por_concepto'    => array_map(fn($x) => round($x, 2), $porConcepto),
        ];
    }
}
