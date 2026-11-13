<?php

namespace Model;

// Inventario de filamento, en kg y en dinero. Solo lee la base: no guarda nada.
//
//  Entradas = compras_material (compras de filamento + inventario inicial)
//  Salidas  = los gramos de cada pieza (con la merma de su cotización), desde que su pedido pasa a
//             "imprimiendo". Los pedidos solicitados o cancelados todavía no gastan filamento.
//  Fallas   = gramos perdidos en impresiones fallidas (carrete_consumos, tipo 'falla')
//  Ajustes  = correcciones de peso de los carretes (carrete_ajustes), positivas o negativas
//  Disponible = entradas − salidas − fallas + ajustes
//  Valor      = disponible × costo promedio por kg de lo que has comprado
class Inventario
{
    // Debajo de esto el material aparece como "bajo"
    public const MINIMO_KG = 1.0;

    // ¿Ya existen las tablas de carretes? (si todavía no corriste sql/carretes.sql, el inventario sigue funcionando sin ellos)
    public static function hayCarretes(): bool
    {
        static $hay = null;
        if ($hay === null) {
            $hay = count(ActiveRecord::fetchArray("SHOW TABLES LIKE 'carretes'")) > 0
                && count(ActiveRecord::fetchArray("SHOW TABLES LIKE 'carrete_ajustes'")) > 0;
        }
        return $hay;
    }

    // ¿Ya existe la columna de color en las partes de la cotización? (paso 2)
    public static function hayColor(): bool
    {
        static $hay = null;
        if ($hay === null) {
            $hay = count(ActiveRecord::fetchArray("SHOW COLUMNS FROM cotizacion_partes LIKE 'color'")) > 0;
        }
        return $hay;
    }

    // ¿Existe la tabla de consumos por carrete? (paso 2)
    public static function hayConsumos(): bool
    {
        static $hay = null;
        if ($hay === null) {
            $hay = self::hayCarretes() && count(ActiveRecord::fetchArray("SHOW TABLES LIKE 'carrete_consumos'")) > 0;
        }
        return $hay;
    }

    // kg perdidos en impresiones fallidas, por material
    private static function sqlFallas(): string
    {
        return self::hayConsumos()
            ? "SELECT k.material_id, SUM(c.gramos) / 1000 AS kg
               FROM carrete_consumos c JOIN carretes k ON k.id = c.carrete_id
               WHERE c.tipo = 'falla'
               GROUP BY k.material_id"
            : "SELECT 0 AS material_id, 0 AS kg FROM DUAL WHERE 1 = 0";
    }

    // kg que suman o restan los ajustes de peso, por material
    private static function sqlAjustes(): string
    {
        return self::hayCarretes()
            ? "SELECT k.material_id, SUM(a.delta_g) / 1000 AS kg
               FROM carrete_ajustes a JOIN carretes k ON k.id = a.carrete_id
               GROUP BY k.material_id"
            : "SELECT 0 AS material_id, 0 AS kg FROM DUAL WHERE 1 = 0";
    }

    // Salidas por material: kg que ya se usan o se usaron en piezas en producción o entregadas
    private static function sqlSalidas(): string
    {
        $conPlan = self::hayPlanReal();
        // Partes de la cotización (o, si el pedido cambió de colores, los colores reales que eligió)
        $partes = $conPlan
            ? "(SELECT q.id AS cot_id, v2.pedido_id, cp.material_id, cp.gramos FROM ventas v2
                   JOIN cotizaciones q ON q.id = v2.cotizacion_id
                   JOIN cotizacion_partes cp ON cp.cotizacion_id = q.id
                   WHERE NOT EXISTS (SELECT 1 FROM pedido_filamento pf WHERE pf.pedido_id = v2.pedido_id)
                 UNION ALL
                 SELECT NULL, pf.pedido_id, pf.material_id, pf.gramos FROM pedido_filamento pf)"
            : "(SELECT q.id AS cot_id, v2.pedido_id, cp.material_id, cp.gramos FROM ventas v2
                   JOIN cotizaciones q ON q.id = v2.cotizacion_id
                   JOIN cotizacion_partes cp ON cp.cotizacion_id = q.id)";
        return "SELECT cp.material_id, SUM(cp.gramos * (1 + COALESCE(cfg.merma_pct, 0) / 100)) / 1000 AS kg
                FROM ventas v
                JOIN pedidos p ON p.id = v.pedido_id
                " . Cancelacion::join() . "
                JOIN cotizaciones q ON q.id = v.cotizacion_id
                JOIN configuraciones cfg ON cfg.id = q.configuracion_id
                JOIN $partes cp ON cp.pedido_id = v.pedido_id
                WHERE p.estado <> 'solicitado' AND (p.estado <> 'cancelado' OR cn.etapa <> 'solicitado')
                GROUP BY cp.material_id";
    }

    // Una fila por material con movimiento (o activo): comprado, usado, disponible, costo promedio y valor
    public static function resumen(): array
    {
        $filas = ActiveRecord::fetchArray(
            "SELECT m.id, m.nombre, m.costo_kg, m.activo,
                    COALESCE(e.kg, 0) AS comprado_kg, COALESCE(e.costo, 0) AS comprado_q, COALESCE(u.kg, 0) AS usado_kg,
                    COALESCE(aj.kg, 0) AS ajustes_kg, COALESCE(fa.kg, 0) AS fallas_kg
             FROM materiales m
             LEFT JOIN (SELECT material_id, SUM(kg) AS kg, SUM(costo_total) AS costo FROM compras_material GROUP BY material_id) e ON e.material_id = m.id
             LEFT JOIN (" . self::sqlSalidas() . ") u ON u.material_id = m.id
             LEFT JOIN (" . self::sqlAjustes() . ") aj ON aj.material_id = m.id
             LEFT JOIN (" . self::sqlFallas() . ") fa ON fa.material_id = m.id
             WHERE m.activo = 1 OR e.kg IS NOT NULL OR u.kg IS NOT NULL
             ORDER BY m.nombre"
        );

        // Con carretes registrados, "poco filamento" se mide con el aviso en gramos (Costos y margen), no con 1 kg fijo
        $conCarretes = [];
        $umbralKg = self::MINIMO_KG;
        if (self::hayCarretes()) {
            foreach (ActiveRecord::fetchArray('SELECT DISTINCT material_id FROM carretes') as $c) {
                $conCarretes[(int) $c['material_id']] = true;
            }
            $umbralKg = self::alertaGramos() / 1000;
        }

        $salida = [];
        foreach ($filas as $f) {
            $comprado = (float) $f['comprado_kg'];
            $usado = (float) $f['usado_kg'];
            $ajustes = (float) $f['ajustes_kg'];
            $fallas = (float) $f['fallas_kg'];
            $disponible = round($comprado - $usado - $fallas + $ajustes, 3);
            $promedio = $comprado > 0 ? (float) $f['comprado_q'] / $comprado : (float) $f['costo_kg'];
            $minimo = isset($conCarretes[(int) $f['id']]) ? $umbralKg : self::MINIMO_KG;
            $estado = $disponible <= 0.0005 ? 'sin' : ($disponible < $minimo ? 'bajo' : 'ok');

            $salida[] = [
                'id'            => (int) $f['id'],
                'nombre'        => $f['nombre'],
                'activo'        => (int) $f['activo'],
                'costo_kg'      => round((float) $f['costo_kg'], 2),
                'comprado_kg'   => round($comprado, 3),
                'usado_kg'      => round($usado, 3),
                'ajustes_kg'    => round($ajustes, 3),
                'fallas_kg'     => round($fallas, 3),
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

    // ── Por color y por carrete (paso 2) ─────────────────────────────────────

    // Avisar cuando a un color le queden menos de estos gramos
    public static function alertaGramos(): float
    {
        try {
            $f = ActiveRecord::fetchArray("SELECT valor FROM ajustes WHERE clave = 'alerta_carrete_g'");
            return $f ? max((float) $f[0]['valor'], 0.0) : 150.0;
        } catch (\Throwable $e) {
            return 150.0;
        }
    }

    // ¿Ese material ya tiene carretes registrados? (si no, se sigue controlando solo por kg del material)
    private static function materialConCarretes(int $materialId): bool
    {
        return self::hayCarretes()
            && count(ActiveRecord::fetchArray('SELECT id FROM carretes WHERE material_id = ? LIMIT 1', [$materialId])) > 0;
    }

    // Gramos con filamento por material y color (solo carretes con algo). Costo por kg ponderado por lo que queda.
    // Cada elemento: material_id, material, color, carretes, gramos, costo_kg
    public static function colores(): array
    {
        if (!self::hayCarretes()) {
            return [];
        }
        $filas = ActiveRecord::fetchArray(
            "SELECT k.material_id, m.nombre AS material, k.color, COUNT(*) AS carretes,
                    SUM(k.gramos_actuales) AS gramos,
                    SUM(k.gramos_actuales * k.costo / k.peso_inicial_g) AS valor
             FROM carretes k JOIN materiales m ON m.id = k.material_id
             WHERE k.gramos_actuales > 0.5
             GROUP BY k.material_id, m.nombre, k.color
             ORDER BY m.nombre, k.color"
        );
        foreach ($filas as &$f) {
            $g = (float) $f['gramos'];
            $f['material_id'] = (int) $f['material_id'];
            $f['carretes'] = (int) $f['carretes'];
            $f['gramos'] = round($g, 2);
            $f['costo_kg'] = $g > 0 ? round((float) $f['valor'] / $g * 1000, 2) : 0.0;
            unset($f['valor']);
        }
        unset($f);
        return $filas;
    }

    // Costo por kg para cotizar un material de un color.
    // Se cotiza con el MAYOR entre el costo de referencia del material (el que fijas en Materiales) y lo que costó el carrete real de ese color:
    //  · una oferta (carrete más barato que la referencia) no baja el precio al cliente: la diferencia es ganancia tuya;
    //  · un carrete más caro que la referencia (ASA+, o subió el precio) sube la cotización, así no pierdes dinero.
    public static function costoKg(int $materialId, ?string $color, float $costoMaterial): float
    {
        return max($costoMaterial, self::costoKgCarrete($materialId, $color, $costoMaterial));
    }

    // Lo que cuestan los carretes de ese color: los que tienes con filamento; si no hay, el último que registraste; si no, el costo del material
    public static function costoKgCarrete(int $materialId, ?string $color, float $costoMaterial): float
    {
        $color = trim((string) $color);
        if ($color === '' || !self::hayCarretes()) {
            return $costoMaterial;
        }
        foreach (self::colores() as $c) {
            if ($c['material_id'] === $materialId && mb_strtolower($c['color']) === mb_strtolower($color)) {
                return (float) $c['costo_kg'];
            }
        }
        $k = ActiveRecord::fetchArray(
            'SELECT costo / peso_inicial_g * 1000 AS costo_kg FROM carretes WHERE material_id = ? AND LOWER(color) = LOWER(?) ORDER BY id DESC LIMIT 1',
            [$materialId, $color]
        );
        return $k ? round((float) $k[0]['costo_kg'], 2) : $costoMaterial;
    }

    // ¿Existen las tablas del cambio de colores?
    public static function hayPlanReal(): bool
    {
        static $hay = null;
        if ($hay === null) {
            $hay = count(ActiveRecord::fetchArray("SHOW TABLES LIKE 'pedido_filamento'")) > 0
                && count(ActiveRecord::fetchArray("SHOW TABLES LIKE 'pedido_filamento_cambio'")) > 0;
        }
        return $hay;
    }

    // Colores reales que el usuario eligió para un pedido (gramos netos). Vacío = se usa lo de la cotización.
    public static function planReal(int $pedidoId): array
    {
        if (!self::hayPlanReal()) {
            return [];
        }
        $filas = ActiveRecord::fetchArray(
            'SELECT f.material_id, m.nombre AS material, f.color, f.gramos
             FROM pedido_filamento f JOIN materiales m ON m.id = f.material_id
             WHERE f.pedido_id = ? ORDER BY f.id',
            [$pedidoId]
        );
        foreach ($filas as &$f) {
            $f['material_id'] = (int) $f['material_id'];
            $f['gramos'] = round((float) $f['gramos'], 2);
        }
        return $filas;
    }

    // Lo que necesita imprimir un pedido, parte por parte (con la merma): material, color, gramos y la merma que va dentro
    private static function necesidades(int $pedidoId): array
    {
        $plan = self::planReal($pedidoId);
        if ($plan) {
            $m = ActiveRecord::fetchArray(
                'SELECT COALESCE(cfg.merma_pct, 0) AS merma_pct FROM ventas v
                 JOIN cotizaciones q ON q.id = v.cotizacion_id
                 JOIN configuraciones cfg ON cfg.id = q.configuracion_id WHERE v.pedido_id = ?',
                [$pedidoId]
            );
            $pct = (float) ($m[0]['merma_pct'] ?? 0);
            return array_map(fn($f) => [
                'parte_id'    => null,
                'material_id' => $f['material_id'],
                'material'    => $f['material'],
                'color'       => $f['color'],
                'gramos'      => (float) $f['gramos'] * (1 + $pct / 100),
                'merma'       => (float) $f['gramos'] * $pct / 100,
            ], $plan);
        }
        $color = self::hayColor() ? 'cp.color' : 'NULL';
        return ActiveRecord::fetchArray(
            "SELECT cp.id AS parte_id, cp.material_id, m.nombre AS material, $color AS color,
                    cp.gramos * (1 + COALESCE(cfg.merma_pct, 0) / 100) AS gramos,
                    cp.gramos * COALESCE(cfg.merma_pct, 0) / 100        AS merma
             FROM ventas v
             JOIN cotizaciones q ON q.id = v.cotizacion_id
             JOIN configuraciones cfg ON cfg.id = q.configuracion_id
             JOIN cotizacion_partes cp ON cp.cotizacion_id = q.id
             JOIN materiales m ON m.id = cp.material_id
             WHERE v.pedido_id = ?
             ORDER BY cp.id",
            [$pedidoId]
        );
    }

    // Carretes con filamento de un material (y color, si lo hay), los más gastados primero: se termina el que está empezado
    private static function carretesDisponibles(int $materialId, ?string $color): array
    {
        $color = trim((string) $color);
        return ActiveRecord::fetchArray(
            'SELECT id, color, peso_inicial_g, gramos_actuales, costo
             FROM carretes
             WHERE material_id = ? AND gramos_actuales > 0.005 AND (? = \'\' OR LOWER(color) = LOWER(?))
             ORDER BY gramos_actuales ASC, id ASC',
            [$materialId, $color, $color]
        );
    }

    // Lo que le falta de filamento a un pedido para empezar a imprimir (vacío = alcanza).
    // Cada elemento: material_id, nombre, color, necesita_g, disponible_g
    public static function faltantes(int $pedidoId): array
    {
        $necesita = [];
        foreach (self::necesidades($pedidoId) as $n) {
            $clave = $n['material_id'] . '|' . mb_strtolower(trim((string) $n['color']));
            $necesita[$clave] ??= ['material_id' => (int) $n['material_id'], 'nombre' => $n['material'], 'color' => trim((string) $n['color']), 'g' => 0.0];
            $necesita[$clave]['g'] += (float) $n['gramos'];
        }
        if (!$necesita) {
            return [];
        }

        $stockMaterial = [];
        foreach (self::resumen() as $m) {
            $stockMaterial[$m['id']] = (float) $m['disponible_kg'] * 1000;
        }

        $faltan = [];
        foreach ($necesita as $n) {
            if (self::materialConCarretes($n['material_id'])) {
                $hay = 0.0;
                foreach (self::carretesDisponibles($n['material_id'], $n['color']) as $k) {
                    $hay += (float) $k['gramos_actuales'];
                }
            } else {
                $hay = $stockMaterial[$n['material_id']] ?? 0.0;
            }
            if ($n['g'] > $hay + 0.5) {
                $faltan[] = [
                    'material_id'  => $n['material_id'],
                    'nombre'       => $n['nombre'],
                    'color'        => $n['color'],
                    'necesita_g'   => round($n['g'], 1),
                    'disponible_g' => round(max($hay, 0), 1),
                ];
            }
        }
        return $faltan;
    }

    // Saca del inventario el filamento de un pedido que empieza a imprimir: parte por parte, de los carretes
    // de su color (primero el más gastado). Se llama dentro de la transacción del cambio de etapa.
    // Devuelve los materiales y colores que se tocaron: [[material_id, color], ...]
    public static function consumirPedido(int $pedidoId): array
    {
        if (!self::hayConsumos()) {
            return [];
        }
        $ya = ActiveRecord::fetchArray("SELECT COUNT(*) AS n FROM carrete_consumos WHERE pedido_id = ? AND tipo = 'pieza'", [$pedidoId]);
        if ((int) ($ya[0]['n'] ?? 0) > 0) {
            return [];
        }

        $db = ActiveRecord::getDB();
        $tocados = [];
        foreach (self::necesidades($pedidoId) as $n) {
            $materialId = (int) $n['material_id'];
            if (!self::materialConCarretes($materialId)) {
                continue; // material sin carretes registrados: solo cuenta en los kg del material
            }
            $falta = round((float) $n['gramos'], 2);
            $total = max($falta, 0.01);
            foreach (self::carretesDisponibles($materialId, $n['color']) as $k) {
                if ($falta <= 0.004) {
                    break;
                }
                $saca = round(min($falta, (float) $k['gramos_actuales']), 2);
                $costo = round($saca * (float) $k['costo'] / (float) $k['peso_inicial_g'], 2);
                $db->prepare('UPDATE carretes SET gramos_actuales = gramos_actuales - ? WHERE id = ?')->execute([$saca, (int) $k['id']]);
                $db->prepare("INSERT INTO carrete_consumos (carrete_id, pedido_id, tipo, gramos, gramos_merma, costo) VALUES (?, ?, 'pieza', ?, ?, ?)")
                    ->execute([(int) $k['id'], $pedidoId, $saca, round((float) $n['merma'] * $saca / $total, 2), $costo]);
                $falta = round($falta - $saca, 2);
                $tocados[$materialId . '|' . mb_strtolower((string) $k['color'])] = [$materialId, (string) $k['color']];
            }
        }
        return array_values($tocados);
    }

    // Avisos de poco filamento por color. $solo = lista de [material_id, color] para revisar solo esos (o null = todos).
    //  · "por acabarse": al color le quedan menos gramos que el límite (aunque tenga varios carretes)
    //  · "agotado": no queda nada de un color que necesita un pedido que todavía no se imprime
    public static function alertasColores(?array $solo = null): array
    {
        if (!self::hayCarretes() || !self::hayColor()) {
            return [];
        }
        $limite = self::alertaGramos();

        $filas = ActiveRecord::fetchArray(
            "SELECT k.material_id, m.nombre AS material, k.color,
                    SUM(CASE WHEN k.gramos_actuales > 0.5 THEN k.gramos_actuales ELSE 0 END) AS gramos,
                    SUM(CASE WHEN k.gramos_actuales > 0.5 THEN 1 ELSE 0 END) AS carretes
             FROM carretes k JOIN materiales m ON m.id = k.material_id
             GROUP BY k.material_id, m.nombre, k.color"
        );

        // Colores que piden los pedidos que aún no se imprimen
        $piden = [];
        foreach (ActiveRecord::fetchArray(
            "SELECT cp.material_id, cp.color, p.nombre_mascota
             FROM pedidos p
             JOIN ventas v ON v.pedido_id = p.id
             JOIN cotizacion_partes cp ON cp.cotizacion_id = v.cotizacion_id
             WHERE p.estado = 'solicitado' AND cp.color IS NOT NULL AND cp.color <> ''"
        ) as $f) {
            $piden[$f['material_id'] . '|' . mb_strtolower($f['color'])][] = $f['nombre_mascota'];
        }

        $alertas = [];
        foreach ($filas as $f) {
            $clave = $f['material_id'] . '|' . mb_strtolower($f['color']);
            if ($solo !== null && !array_filter($solo, fn($s) => $s[0] . '|' . mb_strtolower((string) $s[1]) === $clave)) {
                continue;
            }
            $g = (float) $f['gramos'];
            $agotado = $g <= 0.5;
            $esperan = array_values(array_unique($piden[$clave] ?? []));
            if (!$agotado && $g >= $limite) {
                continue;
            }
            // Un color agotado solo molesta si algún pedido lo necesita (o si acabas de gastarlo)
            if ($agotado && !$esperan && $solo === null) {
                continue;
            }
            $alertas[] = [
                'material_id' => (int) $f['material_id'],
                'material'    => $f['material'],
                'color'       => $f['color'],
                'gramos'      => round($g, 1),
                'carretes'    => (int) $f['carretes'],
                'agotado'     => $agotado,
                'pedidos'     => $esperan,
                'limite_g'    => $limite,
            ];
        }
        usort($alertas, fn($a, $b) => $a['gramos'] <=> $b['gramos']);
        return $alertas;
    }

    public static function hayDevoluciones(): bool
    {
        static $hay = null;
        if ($hay === null) {
            $hay = count(ActiveRecord::fetchArray("SHOW COLUMNS FROM compras_material LIKE 'devuelta_en'")) > 0;
        }
        return $hay;
    }

    public static function hayPruebas(): bool
    {
        static $hay = null;
        if ($hay === null) {
            $hay = self::hayCarretes() && count(ActiveRecord::fetchArray("SHOW COLUMNS FROM carrete_ajustes LIKE 'tipo'")) > 0;
        }
        return $hay;
    }

    // Impresiones de prueba (filamento gastado sin vender): total y últimas
    public static function pruebas(): array
    {
        $vacio = ['total_g' => 0.0, 'total_q' => 0.0, 'cantidad' => 0, 'mes_g' => 0.0, 'mes_q' => 0.0, 'ultimas' => []];
        if (!self::hayPruebas()) {
            return $vacio;
        }
        $mes = date('Y-m');
        $t = ActiveRecord::fetchArray(
            "SELECT COUNT(*) AS n, COALESCE(SUM(-a.delta_g), 0) AS g, COALESCE(SUM(-a.delta_g * k.costo / k.peso_inicial_g), 0) AS q,
                    COALESCE(SUM(CASE WHEN DATE_FORMAT(a.creado_en, '%Y-%m') = ? THEN -a.delta_g END), 0) AS mg,
                    COALESCE(SUM(CASE WHEN DATE_FORMAT(a.creado_en, '%Y-%m') = ? THEN -a.delta_g * k.costo / k.peso_inicial_g END), 0) AS mq
             FROM carrete_ajustes a JOIN carretes k ON k.id = a.carrete_id WHERE a.tipo = 'prueba'",
            [$mes, $mes]
        )[0];
        $ult = ActiveRecord::fetchArray(
            "SELECT a.id, a.creado_en, k.color, -a.delta_g AS gramos, -a.delta_g * k.costo / k.peso_inicial_g AS costo, a.motivo
             FROM carrete_ajustes a JOIN carretes k ON k.id = a.carrete_id WHERE a.tipo = 'prueba'
             ORDER BY a.id DESC LIMIT 8"
        );
        foreach ($ult as &$u) {
            $u['gramos'] = round((float) $u['gramos'], 2);
            $u['costo'] = round((float) $u['costo'], 2);
        }
        return [
            'total_g'  => round((float) $t['g'], 2), 'total_q' => round((float) $t['q'], 2), 'cantidad' => (int) $t['n'],
            'mes_g'    => round((float) $t['mg'], 2), 'mes_q' => round((float) $t['mq'], 2), 'ultimas' => $ult,
        ];
    }

    // Merma que presupuestaste en las cotizaciones (de las piezas que ya se imprimen) contra lo que de verdad se perdió en fallas
    public static function merma(): array
    {
        $vacio = ['presupuestada_g' => 0.0, 'real_g' => 0.0, 'mes_presupuestada_g' => 0.0, 'mes_real_g' => 0.0, 'fallas' => 0, 'costo_fallas' => 0.0];
        if (!self::hayConsumos()) {
            return $vacio;
        }
        $mes = date('Y-m');
        $f = ActiveRecord::fetchArray(
            "SELECT COALESCE(SUM(CASE WHEN tipo = 'pieza' THEN gramos_merma END), 0) AS presup,
                    COALESCE(SUM(CASE WHEN tipo = 'falla' THEN gramos END), 0) AS real_g,
                    COALESCE(SUM(CASE WHEN tipo = 'pieza' AND DATE_FORMAT(creado_en, '%Y-%m') = ? THEN gramos_merma END), 0) AS mes_presup,
                    COALESCE(SUM(CASE WHEN tipo = 'falla' AND DATE_FORMAT(creado_en, '%Y-%m') = ? THEN gramos END), 0) AS mes_real,
                    SUM(tipo = 'falla') AS fallas,
                    COALESCE(SUM(CASE WHEN tipo = 'falla' THEN costo END), 0) AS costo_fallas
             FROM carrete_consumos",
            [$mes, $mes]
        )[0];
        return [
            'presupuestada_g'     => round((float) $f['presup'], 1),
            'real_g'              => round((float) $f['real_g'], 1),
            'mes_presupuestada_g' => round((float) $f['mes_presup'], 1),
            'mes_real_g'          => round((float) $f['mes_real'], 1),
            'fallas'              => (int) $f['fallas'],
            'costo_fallas'        => round((float) $f['costo_fallas'], 2),
        ];
    }

    // Carretes con filamento que sirven para una pieza que falló: primero los de los colores del pedido
    public static function carretesParaFalla(int $pedidoId): array
    {
        $piden = [];
        foreach (self::necesidades($pedidoId) as $n) {
            // Sin color en la cotización sirve cualquier color de ese material
            $piden[$n['material_id'] . '|' . mb_strtolower(trim((string) $n['color']))] = true;
        }
        $lista = [];
        foreach (self::carretes() as $k) {
            if ($k['agotado']) {
                continue;
            }
            $k['sugerido'] = isset($piden[$k['material_id'] . '|' . mb_strtolower($k['color'])]) || isset($piden[$k['material_id'] . '|']);
            // Solo los carretes de los colores que lleva esta impresión: no tiene sentido descontar otro
            if (!$k['sugerido']) {
                continue;
            }
            $lista[] = $k;
        }
        usort($lista, fn($a, $b) => $a['gramos_actuales'] <=> $b['gramos_actuales']);
        return $lista;
    }

    // Un renglón por carrete: color, cuánto le queda y cuánto costó (lo más reciente primero dentro de cada material)
    public static function carretes(): array
    {
        if (!self::hayCarretes()) {
            return [];
        }
        $filas = ActiveRecord::fetchArray(
            "SELECT k.id, k.compra_id, k.material_id, m.nombre AS material, k.color, k.peso_inicial_g, k.gramos_registrados,
                    k.gramos_actuales, k.costo, cm.fecha, cm.origen
             FROM carretes k
             JOIN materiales m ON m.id = k.material_id
             JOIN compras_material cm ON cm.id = k.compra_id
             ORDER BY m.nombre, k.color, k.id"
        );
        foreach ($filas as &$f) {
            $peso = max((float) $f['peso_inicial_g'], 0.01);
            $f['id'] = (int) $f['id'];
            $f['compra_id'] = (int) $f['compra_id'];
            $f['material_id'] = (int) $f['material_id'];
            $f['peso_inicial_g'] = round((float) $f['peso_inicial_g'], 2);
            $f['gramos_registrados'] = round((float) $f['gramos_registrados'], 2);
            $f['gramos_actuales'] = round((float) $f['gramos_actuales'], 2);
            $f['costo'] = round((float) $f['costo'], 2);
            $f['costo_kg'] = round((float) $f['costo'] / $peso * 1000, 2);
            $f['valor'] = round(max($f['gramos_actuales'], 0) * (float) $f['costo'] / $peso, 2);
            $f['agotado'] = $f['gramos_actuales'] <= 0.5;
        }
        unset($f);
        return $filas;
    }
}
