<?php

namespace Model;

// Inventario de filamento, en kg y en dinero. Solo lee la base: no guarda nada.
//
//  Entradas = compras_material (compras de filamento + inventario inicial)
//  Salidas  = los gramos de cada pieza (con la merma de su cotización), desde que su pedido pasa a
//             "imprimiendo". Los pedidos solicitados o cancelados todavía no gastan filamento.
//  Disponible = entradas − salidas
//  Valor      = disponible × costo promedio por kg de lo que has comprado
class Inventario
{
    // Debajo de esto el material aparece como "bajo"
    public const MINIMO_KG = 1.0;

    // Salidas por material: kg que ya se usan o se usaron en piezas en producción o entregadas
    private static function sqlSalidas(): string
    {
        return "SELECT cp.material_id, SUM(cp.gramos * (1 + COALESCE(cfg.merma_pct, 0) / 100)) / 1000 AS kg
                FROM ventas v
                JOIN pedidos p ON p.id = v.pedido_id
                JOIN cotizaciones q ON q.id = v.cotizacion_id
                JOIN configuraciones cfg ON cfg.id = q.configuracion_id
                JOIN cotizacion_partes cp ON cp.cotizacion_id = q.id
                WHERE p.estado NOT IN ('solicitado', 'cancelado')
                GROUP BY cp.material_id";
    }

    // Una fila por material con movimiento (o activo): comprado, usado, disponible, costo promedio y valor
    public static function resumen(): array
    {
        $filas = ActiveRecord::fetchArray(
            "SELECT m.id, m.nombre, m.costo_kg, m.activo,
                    COALESCE(e.kg, 0) AS comprado_kg, COALESCE(e.costo, 0) AS comprado_q, COALESCE(u.kg, 0) AS usado_kg
             FROM materiales m
             LEFT JOIN (SELECT material_id, SUM(kg) AS kg, SUM(costo_total) AS costo FROM compras_material GROUP BY material_id) e ON e.material_id = m.id
             LEFT JOIN (" . self::sqlSalidas() . ") u ON u.material_id = m.id
             WHERE m.activo = 1 OR e.kg IS NOT NULL OR u.kg IS NOT NULL
             ORDER BY m.nombre"
        );

        $salida = [];
        foreach ($filas as $f) {
            $comprado = (float) $f['comprado_kg'];
            $usado = (float) $f['usado_kg'];
            $disponible = round($comprado - $usado, 3);
            $promedio = $comprado > 0 ? (float) $f['comprado_q'] / $comprado : (float) $f['costo_kg'];
            $estado = $disponible <= 0.0005 ? 'sin' : ($disponible < self::MINIMO_KG ? 'bajo' : 'ok');

            $salida[] = [
                'id'            => (int) $f['id'],
                'nombre'        => $f['nombre'],
                'activo'        => (int) $f['activo'],
                'costo_kg'      => round((float) $f['costo_kg'], 2),
                'comprado_kg'   => round($comprado, 3),
                'usado_kg'      => round($usado, 3),
                'disponible_kg' => $disponible,
                'costo_prom_kg' => round($promedio, 2),
                'valor'         => round(max($disponible, 0) * $promedio, 2),
                'estado'        => $estado,
            ];
        }
        return $salida;
    }

    // kg disponibles de un material
    public static function disponible(int $materialId): float
    {
        foreach (self::resumen() as $m) {
            if ($m['id'] === $materialId) {
                return (float) $m['disponible_kg'];
            }
        }
        return 0.0;
    }

    // Lo que le falta de filamento a un pedido para empezar a imprimir (vacío = alcanza).
    // Cada elemento: material_id, nombre, necesita_kg, disponible_kg
    public static function faltantes(int $pedidoId): array
    {
        $necesita = ActiveRecord::fetchArray(
            "SELECT cp.material_id, m.nombre, SUM(cp.gramos * (1 + COALESCE(cfg.merma_pct, 0) / 100)) / 1000 AS kg
             FROM ventas v
             JOIN cotizaciones q ON q.id = v.cotizacion_id
             JOIN configuraciones cfg ON cfg.id = q.configuracion_id
             JOIN cotizacion_partes cp ON cp.cotizacion_id = q.id
             JOIN materiales m ON m.id = cp.material_id
             WHERE v.pedido_id = ?
             GROUP BY cp.material_id, m.nombre",
            [$pedidoId]
        );
        if (!$necesita) {
            return [];
        }

        $stock = [];
        foreach (self::resumen() as $m) {
            $stock[$m['id']] = (float) $m['disponible_kg'];
        }

        $faltan = [];
        foreach ($necesita as $n) {
            $hay = $stock[(int) $n['material_id']] ?? 0.0;
            if ((float) $n['kg'] > $hay + 0.0005) {
                $faltan[] = [
                    'material_id'   => (int) $n['material_id'],
                    'nombre'        => $n['nombre'],
                    'necesita_kg'   => round((float) $n['kg'], 3),
                    'disponible_kg' => round(max($hay, 0), 3),
                ];
            }
        }
        return $faltan;
    }
}
