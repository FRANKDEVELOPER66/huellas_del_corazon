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
            'alertas'    => $bajos,
            'minimo_kg'  => Inventario::MINIMO_KG,
        ]]);
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

        return [$material, $kg, $costo, $fecha];
    }

    private static function descripcion(Material $material, float $kg, string $nota = ''): string
    {
        $texto = 'Filamento ' . $material->nombre . ' ' . rtrim(rtrim(number_format($kg, 3, '.', ''), '0'), '.') . ' kg';
        return mb_substr($nota !== '' ? $texto . ' · ' . $nota : $texto, 0, 250);
    }

    // POST: material_id, kg, costo_total, fecha, origen (caja | bolsillo | inicial), nota, comprobante, actualizar_costo
    public static function comprarAPI(Router $router): void
    {
        getHeadersApi();

        [$material, $kg, $costo, $fecha] = self::leerCompra();
        $origen = self::origenValido($_POST['origen'] ?? '', true);
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
}
