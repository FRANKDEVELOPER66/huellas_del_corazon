<?php

namespace Controllers;

use MVC\Router;
use Model\ActiveRecord;
use Model\Gasto;
use Model\Inventario;
use Model\Material;

// Inventario de filamento: compras (con comprobante y de dónde salió el dinero) e inventario inicial.
// Las salidas de filamento no se registran a mano: salen solas de las piezas que ya se están imprimiendo.
class InventarioController
{
    use RespuestaApi, Comprobantes, ReglasGasto;

    public static function index(Router $router): void
    {
        $router->render('inventario/index', []);
    }

    // Stock por material (kg y Q) y las entradas registradas
    public static function resumenAPI(Router $router): void
    {
        getHeadersApi();

        $materiales = Inventario::resumen();
        $kg = 0.0;
        $valor = 0.0;
        $bajos = 0;
        foreach ($materiales as $m) {
            $kg += max((float) $m['disponible_kg'], 0);
            $valor += (float) $m['valor'];
            // Solo cuenta como alerta un material que ya compraste o usaste (los que nunca has comprado no molestan)
            $bajos += ($m['estado'] !== 'ok' && ((float) $m['comprado_kg'] > 0 || (float) $m['usado_kg'] > 0)) ? 1 : 0;
        }

        $compras = ActiveRecord::fetchArray(
            "SELECT cm.id, cm.fecha, cm.material_id, m.nombre AS material, cm.kg, cm.costo_total, cm.origen, cm.gasto_id,
                    g.comprobante,
                    (SELECT COUNT(*) FROM gastos_historial h WHERE h.tipo = 'compra' AND h.ref_id = cm.id) AS ediciones
             FROM compras_material cm
             JOIN materiales m ON m.id = cm.material_id
             LEFT JOIN gastos g ON g.id = cm.gasto_id
             ORDER BY cm.fecha DESC, cm.id DESC
             LIMIT 100"
        );

        self::responder(1, '', ['datos' => [
            'materiales' => $materiales,
            'compras'    => $compras,
            'total_kg'   => round($kg, 3),
            'total_valor' => round($valor, 2),
            'alertas'    => Inventario::hayCarretes() ? count(Inventario::alertasColores()) : $bajos,
            'minimo_kg'  => Inventario::MINIMO_KG,
            'carretes_ok' => Inventario::hayCarretes(),
            'carretes'   => Inventario::carretes(),
            'paso2_ok'   => Inventario::hayConsumos() && Inventario::hayColor(),
            'por_acabarse' => Inventario::alertasColores(),
            'alerta_g'   => Inventario::alertaGramos(),
            'colores'    => Inventario::colores(),
            'merma'      => Inventario::merma(),
        ]]);
    }

    // Colores con filamento y lo que cuesta cada uno por kg (para elegir el color en el cotizador)
    public static function coloresAPI(Router $router): void
    {
        getHeadersApi();
        self::responder(1, '', ['datos' => Inventario::colores()]);
    }

    // Colores por acabarse o agotados (para el aviso de Pedidos)
    public static function alertasAPI(Router $router): void
    {
        getHeadersApi();
        self::responder(1, '', ['datos' => Inventario::alertasColores()]);
    }

    // Lee y valida lo común de una compra: material, kg, costo y fecha
    private static function leerCompra(?array $actual = null): array
    {
        $materialId = array_key_exists('material_id', $_POST) ? (int) $_POST['material_id'] : (int) ($actual['material_id'] ?? 0);
        $material = $materialId ? Material::find($materialId) : null;
        if (!$material) {
            self::responder(0, 'Elige el material');
        }
        if (!$actual && !(int) $material->activo) {
            self::responder(0, 'Ese material está inactivo');
        }

        $kg = round((float) (array_key_exists('kg', $_POST) ? $_POST['kg'] : ($actual['kg'] ?? 0)), 3);
        if (!($kg > 0) || $kg > 1000) {
            self::responder(0, 'Escribe los kg (mayor a 0)');
        }

        $costo = round((float) (array_key_exists('costo_total', $_POST) ? $_POST['costo_total'] : ($actual['costo_total'] ?? 0)), 2);
        if (!($costo > 0)) {
            self::responder(0, 'Escribe cuánto costó en total (mayor a 0)');
        }

        $fecha = trim((string) (array_key_exists('fecha', $_POST) ? $_POST['fecha'] : ($actual['fecha'] ?? date('Y-m-d'))));
        self::fechaNoFutura($fecha);
        self::exigirAbierta($fecha);

        return [$material, $kg, $costo, $fecha];
    }

    private static function descripcion(Material $material, float $kg, string $nota = ''): string
    {
        $texto = 'Filamento ' . $material->nombre . ' ' . rtrim(rtrim(number_format($kg, 3, '.', ''), '0'), '.') . ' kg';
        return mb_substr($nota !== '' ? $texto . ' · ' . $nota : $texto, 0, 250);
    }


    // ── Carretes ────────────────────────────────────────────────────────────

    // Lee la lista de carretes que manda la pantalla (JSON). Devuelve null si no viene (compra sin carretes).
    private static function leerLineas(): ?array
    {
        if (!array_key_exists('carretes', $_POST)) {
            return null;
        }
        $lineas = json_decode((string) $_POST['carretes'], true);
        if (!is_array($lineas) || !$lineas) {
            self::responder(0, 'Agrega al menos un carrete');
        }
        return array_values($lineas);
    }

    // Respeta la escritura de un color que ya usaste (Negro, negro y NEGRO son el mismo), también dentro de una misma lista
    private static function colorLimpio($valor): string
    {
        static $vistos = [];
        $color = trim(preg_replace('/\s+/u', ' ', (string) $valor));
        $color = mb_substr($color, 0, 40);
        if ($color === '') {
            self::responder(0, 'Escribe el color de cada carrete');
        }
        $clave = mb_strtolower($color);
        if (isset($vistos[$clave])) {
            return $vistos[$clave];
        }
        foreach (ActiveRecord::fetchArray('SELECT DISTINCT color FROM carretes') as $f) {
            if (mb_strtolower($f['color']) === $clave) {
                return $vistos[$clave] = $f['color'];
            }
        }
        return $vistos[$clave] = mb_strtoupper(mb_substr($color, 0, 1)) . mb_substr($color, 1);
    }

    // Valida una línea: color, cantidad y peso original. Devuelve [color, cantidad, peso_g]
    private static function leerLinea(array $l): array
    {
        $color = self::colorLimpio($l['color'] ?? '');
        $cantidad = (int) ($l['cantidad'] ?? 1);
        if ($cantidad < 1 || $cantidad > 50) {
            self::responder(0, 'La cantidad de carretes de ' . $color . ' debe estar entre 1 y 50');
        }
        $peso = round((float) ($l['peso_g'] ?? 1000), 2);
        if ($peso < 100 || $peso > 10000) {
            self::responder(0, 'El peso de un carrete de ' . $color . ' debe estar entre 100 g y 10,000 g');
        }
        return [$color, $cantidad, $peso];
    }

    private static function insertarCarrete(\PDO $db, int $compraId, int $materialId, string $color, float $peso, float $gramos, float $costo): void
    {
        $db->prepare('INSERT INTO carretes (compra_id, material_id, color, peso_inicial_g, gramos_registrados, gramos_actuales, costo) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$compraId, $materialId, $color, $peso, $gramos, $gramos, round($costo, 2)]);
    }

    // Registra filamento que ya tenías: cada línea trae su material, color, precio de uno nuevo y cuánto le queda.
    // No sale de la caja. Crea una entrada de inventario por material.
    private static function inicialConCarretes(array $lineas): void
    {
        $fecha = trim((string) ($_POST['fecha'] ?? date('Y-m-d')));
        self::fechaNoFutura($fecha);
        self::exigirAbierta($fecha);

        // Valida todo antes de guardar nada
        $porMaterial = [];
        foreach ($lineas as $l) {
            $material = Material::find((int) ($l['material_id'] ?? 0));
            if (!$material || !(int) $material->activo) {
                self::responder(0, 'Elige el material de cada carrete (activo)');
            }
            [$color, $cantidad, $peso] = self::leerLinea($l);
            $gramos = round((float) ($l['gramos'] ?? 0), 2);
            if (!($gramos > 0) || $gramos > $peso) {
                self::responder(0, 'Cuánto le queda al carrete ' . $color . ': debe ser mayor a 0 y no más que su peso original (' . $peso . ' g)');
            }
            $precio = round((float) ($l['precio'] ?? 0), 2);
            if (!($precio > 0)) {
                self::responder(0, 'Escribe cuánto cuesta un carrete nuevo de ' . $color . ' (aunque sea aproximado)');
            }
            $porMaterial[(int) $material->id][] = [$color, $cantidad, $peso, $gramos, $precio];
        }

        $db = ActiveRecord::getDB();
        $db->beginTransaction();
        try {
            foreach ($porMaterial as $materialId => $items) {
                $kg = 0.0;
                $valor = 0.0;
                foreach ($items as [$color, $cantidad, $peso, $gramos, $precio]) {
                    $kg += $cantidad * $gramos / 1000;
                    $valor += $cantidad * $precio * $gramos / $peso;
                }
                $db->prepare("INSERT INTO compras_material (fecha, material_id, kg, costo_total, origen, gasto_id) VALUES (?, ?, ?, ?, 'inicial', NULL)")
                    ->execute([$fecha, $materialId, round($kg, 3), max(round($valor, 2), 0.01)]);
                $compraId = (int) $db->lastInsertId();
                foreach ($items as [$color, $cantidad, $peso, $gramos, $precio]) {
                    for ($i = 0; $i < $cantidad; $i++) {
                        self::insertarCarrete($db, $compraId, $materialId, $color, $peso, $gramos, $precio);
                    }
                }
            }
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            self::responder(0, 'No se pudo registrar el inventario inicial');
        }
        self::responder(1, 'Inventario inicial registrado');
    }

    // POST: material_id, costo_total, fecha, origen (caja | bolsillo | inicial), nota, comprobante, actualizar_costo,
    //       carretes (JSON). Compra: [{color, cantidad, peso_g}]. Inicial: [{material_id, color, cantidad, peso_g, gramos, precio}]
    //       Sin "carretes" funciona como antes (kg a mano, sin detalle de carretes).
    public static function comprarAPI(Router $router): void
    {
        getHeadersApi();

        $origen = self::origenValido($_POST['origen'] ?? '', true);
        $lineas = self::leerLineas();
        if ($lineas !== null && !Inventario::hayCarretes()) {
            self::responder(0, 'Falta crear las tablas de carretes: ejecuta sql/carretes.sql');
        }
        if ($lineas !== null && $origen === 'inicial') {
            self::inicialConCarretes($lineas);
        }

        // Compra con carretes: los kg salen de los carretes (cantidad × peso)
        $carretes = [];
        if ($lineas !== null) {
            $gramosTotal = 0.0;
            foreach ($lineas as $l) {
                [$color, $cantidad, $peso] = self::leerLinea($l);
                $carretes[] = [$color, $cantidad, $peso];
                $gramosTotal += $cantidad * $peso;
            }
            $_POST['kg'] = round($gramosTotal / 1000, 3);
        }

        [$material, $kg, $costo, $fecha] = self::leerCompra();
        $nota = trim($_POST['nota'] ?? '');

        $categoriaId = 0;
        if ($origen !== 'inicial') {
            // Toda compra de filamento lleva comprobante
            if (empty($_FILES['comprobante']['name'])) {
                self::responder(0, 'La compra de filamento lleva comprobante: sube la captura de la transferencia o la factura');
            }
            if ($origen === 'caja') {
                self::exigirCaja($costo, 'inventario');
            }
            $cat = ActiveRecord::fetchArray("SELECT id FROM categorias_gasto WHERE tipo = 'inventario' ORDER BY id LIMIT 1");
            $categoriaId = (int) ($cat[0]['id'] ?? 0);
            if (!$categoriaId) {
                self::responder(0, 'Falta la categoría de gasto de tipo “inventario” (Filamento)');
            }
        }

        $comprobante = $origen === 'inicial' ? null : self::guardarComprobante();
        $descripcion = self::descripcion($material, $kg, $nota);

        $db = ActiveRecord::getDB();
        $db->beginTransaction();
        try {
            $gastoId = null;
            if ($origen !== 'inicial') {
                $gasto = new Gasto([
                    'fecha'        => $fecha,
                    'categoria_id' => $categoriaId,
                    'descripcion'  => $descripcion,
                    'monto'        => $costo,
                    'comprobante'  => $comprobante,
                    'origen'       => $origen,
                ]);
                $r = $gasto->crear();
                if ($r['resultado'] === false) {
                    throw new \RuntimeException('No se pudo guardar el gasto');
                }
                $gastoId = (int) $r['id'];
                self::sincronizarAporte($gastoId, $origen, $costo, $fecha, $descripcion);
            }

            $db->prepare('INSERT INTO compras_material (fecha, material_id, kg, costo_total, origen, gasto_id) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$fecha, (int) $material->id, $kg, $costo, $origen, $gastoId]);
            $compraId = (int) $db->lastInsertId();

            // Lo que pagaste se reparte entre los carretes según su peso
            $gramosTotal = array_sum(array_map(fn ($c) => $c[1] * $c[2], $carretes));
            foreach ($carretes as [$color, $cantidad, $peso]) {
                for ($i = 0; $i < $cantidad; $i++) {
                    self::insertarCarrete($db, $compraId, (int) $material->id, $color, $peso, $peso, $costo * $peso / $gramosTotal);
                }
            }

            if (!empty($_POST['actualizar_costo'])) {
                $db->prepare('UPDATE materiales SET costo_kg = ROUND(? / ?, 2) WHERE id = ?')->execute([$costo, $kg, (int) $material->id]);
            }

            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            self::borrarComprobante($comprobante);
            self::responder(0, 'No se pudo registrar la compra');
        }

        self::responder(1, $origen === 'inicial' ? 'Inventario inicial registrado' : 'Compra de filamento registrada');
    }

    // POST: id (de la compra) y los campos que cambian. El origen del dinero no se cambia aquí.
    public static function actualizarAPI(Router $router): void
    {
        getHeadersApi();

        $filas = ActiveRecord::fetchArray(
            'SELECT cm.*, g.comprobante AS g_comprobante, g.descripcion AS g_descripcion
             FROM compras_material cm LEFT JOIN gastos g ON g.id = cm.gasto_id WHERE cm.id = ?',
            [(int) ($_POST['id'] ?? 0)]
        );
        if (!$filas) {
            self::responder(0, 'Compra no encontrada');
        }
        $c = $filas[0];
        $id = (int) $c['id'];

        $nCarretes = Inventario::hayCarretes()
            ? (int) (ActiveRecord::fetchArray('SELECT COUNT(*) AS n FROM carretes WHERE compra_id = ?', [$id])[0]['n'] ?? 0)
            : 0;
        if ($nCarretes > 0) {
            // El material y los kg salen de los carretes: no se cambian desde aquí
            $_POST['material_id'] = $c['material_id'];
            $_POST['kg'] = $c['kg'];
        }

        // La compra original no puede estar en un período cerrado (la nueva fecha se revisa dentro de leerCompra)
        self::exigirAbierta((string) $c['fecha']);
        [$material, $kg, $costo, $fecha] = self::leerCompra($c);
        $origen = $c['origen'];
        $nota = trim($_POST['nota'] ?? '');

        // El stock no puede quedar en negativo por esta edición
        $stock = [];
        foreach (Inventario::resumen() as $m) {
            $stock[$m['id']] = (float) $m['disponible_kg'];
        }
        $viejoMat = (int) $c['material_id'];
        $nuevoMat = (int) $material->id;
        $quedaViejo = ($stock[$viejoMat] ?? 0) - (float) $c['kg'] + ($viejoMat === $nuevoMat ? $kg : 0);
        if ($quedaViejo < -0.0005) {
            self::responder(0, 'No se puede: ya usaste ese filamento. Con este cambio el inventario quedaría en ' . number_format($quedaViejo, 3) . ' kg');
        }

        $hayArchivo = !empty($_FILES['comprobante']['name']);
        if ($origen !== 'inicial') {
            if (!$c['g_comprobante'] && !$hayArchivo) {
                self::responder(0, 'La compra de filamento lleva comprobante');
            }
            if ($origen === 'caja' && $costo - (float) $c['costo_total'] > 0.005) {
                self::exigirCaja($costo - (float) $c['costo_total'], 'inventario');
            }
        }

        $nuevo = $origen === 'inicial' ? null : self::guardarComprobante();
        $descripcion = self::descripcion($material, $kg, $nota);

        $db = ActiveRecord::getDB();
        $db->beginTransaction();
        try {
            self::guardarHistorial('compra', $id, [
                'fecha'       => $c['fecha'],
                'material_id' => $viejoMat,
                'kg'          => (float) $c['kg'],
                'costo_total' => (float) $c['costo_total'],
                'comprobante' => $c['g_comprobante'],
            ]);

            $db->prepare('UPDATE compras_material SET fecha = ?, material_id = ?, kg = ?, costo_total = ? WHERE id = ?')
                ->execute([$fecha, $nuevoMat, $kg, $costo, $id]);

            // Si cambia lo que costó, el costo de sus carretes cambia en la misma proporción
            if ($nCarretes > 0 && (float) $c['costo_total'] > 0 && abs($costo - (float) $c['costo_total']) > 0.004) {
                $db->prepare('UPDATE carretes SET costo = ROUND(costo * ? / ?, 2) WHERE compra_id = ?')
                    ->execute([$costo, (float) $c['costo_total'], $id]);
            }

            if ($c['gasto_id']) {
                $gastoId = (int) $c['gasto_id'];
                // El comprobante anterior no se borra: queda como respaldo
                $db->prepare('UPDATE gastos SET fecha = ?, monto = ?, descripcion = ?, comprobante = COALESCE(?, comprobante) WHERE id = ?')
                    ->execute([$fecha, $costo, $descripcion, $nuevo, $gastoId]);
                self::sincronizarAporte($gastoId, $origen, $costo, $fecha, $descripcion);
            }

            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            self::borrarComprobante($nuevo);
            self::responder(0, 'No se pudo actualizar la compra');
        }

        self::responder(1, 'Compra actualizada');
    }

    // POST: id (carrete), gramos (lo que le queda ahora), motivo. Corrige el peso y deja el antes y el después.
    public static function carretePesoAPI(Router $router): void
    {
        getHeadersApi();
        if (!Inventario::hayCarretes()) {
            self::responder(0, 'Falta crear las tablas de carretes: ejecuta sql/carretes.sql');
        }

        $filas = ActiveRecord::fetchArray('SELECT * FROM carretes WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
        if (!$filas) {
            self::responder(0, 'Carrete no encontrado');
        }
        $k = $filas[0];

        $nuevo = round((float) ($_POST['gramos'] ?? -1), 2);
        if ($nuevo < 0 || $nuevo > (float) $k['peso_inicial_g'] + 0.005) {
            self::responder(0, 'Los gramos deben estar entre 0 y ' . (float) $k['peso_inicial_g'] . ' (lo que pesa nuevo)');
        }
        $motivo = trim((string) ($_POST['motivo'] ?? ''));
        if (mb_strlen($motivo) < 3) {
            self::responder(0, 'Escribe el motivo del ajuste (por ejemplo: pesado en báscula)');
        }
        $motivo = mb_substr($motivo, 0, 250);

        $antes = round((float) $k['gramos_actuales'], 2);
        $delta = round($nuevo - $antes, 2);
        if (abs($delta) < 0.005) {
            self::responder(0, 'Ese ya es el peso que tiene registrado');
        }

        // Quitar peso no puede dejar el material en negativo
        if ($delta < 0) {
            $queda = Inventario::disponible((int) $k['material_id']) + $delta / 1000;
            if ($queda < -0.0005) {
                self::responder(0, 'No se puede: con ese ajuste el inventario de este material quedaría en ' . number_format($queda, 3) . ' kg');
            }
        }

        $db = ActiveRecord::getDB();
        $db->beginTransaction();
        try {
            $db->prepare('INSERT INTO carrete_ajustes (carrete_id, gramos_antes, gramos_despues, delta_g, motivo) VALUES (?, ?, ?, ?, ?)')
                ->execute([(int) $k['id'], $antes, $nuevo, $delta, $motivo]);
            $db->prepare('UPDATE carretes SET gramos_actuales = ? WHERE id = ?')->execute([$nuevo, (int) $k['id']]);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            self::responder(0, 'No se pudo guardar el ajuste');
        }

        self::responder(1, 'Peso actualizado');
    }

    // POST: id (carrete), color
    public static function carreteColorAPI(Router $router): void
    {
        getHeadersApi();
        if (!Inventario::hayCarretes()) {
            self::responder(0, 'Falta crear las tablas de carretes: ejecuta sql/carretes.sql');
        }
        $filas = ActiveRecord::fetchArray('SELECT id FROM carretes WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
        if (!$filas) {
            self::responder(0, 'Carrete no encontrado');
        }
        $color = self::colorLimpio($_POST['color'] ?? '');
        ActiveRecord::getDB()->prepare('UPDATE carretes SET color = ? WHERE id = ?')->execute([$color, (int) $filas[0]['id']]);
        self::responder(1, 'Color actualizado');
    }
}
