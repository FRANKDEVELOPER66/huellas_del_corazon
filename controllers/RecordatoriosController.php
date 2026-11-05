<?php

namespace Controllers;

use MVC\Router;
use Model\ActiveRecord;
use Model\Inventario;

// Recordatorios de trabajo: lista de pendientes (comprar filamento, arreglar un STL, llamar a un cliente...)
class RecordatoriosController
{
    use RespuestaApi;

    public const TIPOS = ['material', 'diseno', 'cliente', 'pintura', 'envio', 'otro'];
    public const PRIORIDADES = ['normal', 'urgente'];

    public static function index(Router $router): void
    {
        $router->render('recordatorios/index', []);
    }

    // true si la tabla ya existe (para avisar con claridad si falta correr el SQL)
    public static function hayTabla(): bool
    {
        static $hay = null;
        if ($hay === null) {
            try {
                ActiveRecord::fetchArray('SELECT 1 FROM recordatorios LIMIT 1');
                $hay = true;
            } catch (\Throwable $e) {
                $hay = false;
            }
        }
        return $hay;
    }

    private static function exigirTabla(): void
    {
        if (!self::hayTabla()) {
            self::responder(0, 'Falta crear la tabla de recordatorios: ejecuta sql/recordatorios.sql');
        }
    }

    // GET estado = pendiente (por defecto) | hecho | todos
    public static function listarAPI(Router $router): void
    {
        getHeadersApi();
        self::exigirTabla();

        $estado = $_GET['estado'] ?? 'pendiente';
        if (!in_array($estado, ['pendiente', 'hecho', 'todos'], true)) {
            $estado = 'pendiente';
        }

        $yaComprado = Inventario::hayCarretes()
            ? "EXISTS (SELECT 1 FROM carretes k WHERE k.material_id = r.material_id AND LOWER(k.color) = LOWER(r.color) AND k.creado_en >= r.creado_en)"
            : '0';

        $datos = ActiveRecord::fetchArray(
            "SELECT r.*, c.nombre AS cliente, q.nombre_pieza AS cotizacion, m.nombre AS material,
                    DATEDIFF(r.fecha_limite, CURDATE()) AS dias_para,
                    ($yaComprado) AS compro_ya
             FROM recordatorios r
             LEFT JOIN clientes c ON c.id = r.cliente_id
             LEFT JOIN cotizaciones q ON q.id = r.cotizacion_id
             LEFT JOIN materiales m ON m.id = r.material_id
             WHERE (? = 'todos' OR r.estado = ?)
             ORDER BY (r.estado = 'hecho'), (r.prioridad = 'urgente') DESC, r.fecha_limite IS NULL, r.fecha_limite, r.id DESC
             LIMIT 500",
            [$estado, $estado]
        );
        foreach ($datos as &$d) {
            $d['compro_ya'] = (int) $d['compro_ya'] === 1 && $d['estado'] === 'pendiente';
        }
        self::responder(1, '', ['datos' => $datos]);
    }

    // Lee y valida los campos comunes de crear y editar
    private static function leer(): array
    {
        $texto = trim($_POST['texto'] ?? '');
        if (mb_strlen($texto) < 3) {
            self::responder(0, 'Escribe qué debes recordar (mínimo 3 letras)');
        }
        if (mb_strlen($texto) > 255) {
            self::responder(0, 'El recordatorio es muy largo (máximo 255 letras). Usa “Detalle” para lo demás');
        }
        $tipo = $_POST['tipo'] ?? 'otro';
        if (!in_array($tipo, self::TIPOS, true)) {
            self::responder(0, 'Tipo inválido');
        }
        $prioridad = $_POST['prioridad'] ?? 'normal';
        if (!in_array($prioridad, self::PRIORIDADES, true)) {
            self::responder(0, 'Prioridad inválida');
        }
        $limite = trim($_POST['fecha_limite'] ?? '');
        if ($limite !== '') {
            $f = \DateTime::createFromFormat('Y-m-d', $limite);
            if (!$f || $f->format('Y-m-d') !== $limite) {
                self::responder(0, 'La fecha límite no es válida');
            }
        }
        $detalle = trim($_POST['detalle'] ?? '');
        if (mb_strlen($detalle) > 500) {
            self::responder(0, 'El detalle es muy largo (máximo 500 letras)');
        }

        $clienteId = (int) ($_POST['cliente_id'] ?? 0) ?: null;
        if ($clienteId && !ActiveRecord::fetchArray('SELECT id FROM clientes WHERE id = ?', [$clienteId])) {
            self::responder(0, 'Cliente no encontrado');
        }
        $cotId = (int) ($_POST['cotizacion_id'] ?? 0) ?: null;
        if ($cotId && !ActiveRecord::fetchArray('SELECT id FROM cotizaciones WHERE id = ?', [$cotId])) {
            self::responder(0, 'Cotización no encontrada');
        }
        // El material y el color solo tienen sentido al comprar filamento
        $materialId = $tipo === 'material' ? ((int) ($_POST['material_id'] ?? 0) ?: null) : null;
        $color = $tipo === 'material' ? trim($_POST['color'] ?? '') : '';
        if ($materialId && !ActiveRecord::fetchArray('SELECT id FROM materiales WHERE id = ?', [$materialId])) {
            self::responder(0, 'Material no encontrado');
        }
        if (mb_strlen($color) > 40) {
            self::responder(0, 'El color es muy largo');
        }

        return [
            'texto' => $texto, 'detalle' => $detalle !== '' ? $detalle : null, 'tipo' => $tipo, 'prioridad' => $prioridad,
            'fecha_limite' => $limite !== '' ? $limite : null, 'cliente_id' => $clienteId, 'cotizacion_id' => $cotId,
            'material_id' => $materialId, 'color' => $color !== '' ? $color : null,
        ];
    }

    public static function crearAPI(Router $router): void
    {
        getHeadersApi();
        self::exigirTabla();
        $r = self::leer();

        $db = ActiveRecord::getDB();
        $db->prepare('INSERT INTO recordatorios (texto, detalle, tipo, prioridad, fecha_limite, cliente_id, cotizacion_id, material_id, color) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$r['texto'], $r['detalle'], $r['tipo'], $r['prioridad'], $r['fecha_limite'], $r['cliente_id'], $r['cotizacion_id'], $r['material_id'], $r['color']]);

        self::responder(1, 'Recordatorio guardado', ['id' => (int) $db->lastInsertId()]);
    }

    public static function actualizarAPI(Router $router): void
    {
        getHeadersApi();
        self::exigirTabla();

        $id = (int) ($_POST['id'] ?? 0);
        if (!$id || !ActiveRecord::fetchArray('SELECT id FROM recordatorios WHERE id = ?', [$id])) {
            self::responder(0, 'Recordatorio no encontrado');
        }
        $r = self::leer();

        ActiveRecord::getDB()->prepare('UPDATE recordatorios SET texto = ?, detalle = ?, tipo = ?, prioridad = ?, fecha_limite = ?, cliente_id = ?, cotizacion_id = ?, material_id = ?, color = ? WHERE id = ?')
            ->execute([$r['texto'], $r['detalle'], $r['tipo'], $r['prioridad'], $r['fecha_limite'], $r['cliente_id'], $r['cotizacion_id'], $r['material_id'], $r['color'], $id]);

        self::responder(1, 'Recordatorio actualizado');
    }

    // POST id, hecho (1 = marcar como hecho, 0 = volver a pendiente)
    public static function hechoAPI(Router $router): void
    {
        getHeadersApi();
        self::exigirTabla();

        $id = (int) ($_POST['id'] ?? 0);
        if (!$id || !ActiveRecord::fetchArray('SELECT id FROM recordatorios WHERE id = ?', [$id])) {
            self::responder(0, 'Recordatorio no encontrado');
        }
        $hecho = !empty($_POST['hecho']);
        ActiveRecord::getDB()->prepare('UPDATE recordatorios SET estado = ?, hecho_en = ' . ($hecho ? 'NOW()' : 'NULL') . ' WHERE id = ?')
            ->execute([$hecho ? 'hecho' : 'pendiente', $id]);

        self::responder(1, $hecho ? 'Marcado como hecho' : 'Vuelve a pendientes');
    }

    public static function eliminarAPI(Router $router): void
    {
        getHeadersApi();
        self::exigirTabla();

        $id = (int) ($_POST['id'] ?? 0);
        if (!$id) {
            self::responder(0, 'ID inválido');
        }
        ActiveRecord::getDB()->prepare('DELETE FROM recordatorios WHERE id = ?')->execute([$id]);
        self::responder(1, 'Recordatorio eliminado');
    }
}
