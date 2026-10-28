<?php

namespace Controllers;

use MVC\Router;
use Model\ActiveRecord;
use Model\Inventario;
use Model\Pago;
use Model\Pedido;
use Model\Reporte;
use Model\Venta;

class PedidosController
{
    use RespuestaApi, Comprobantes;

    // Etapas en orden. Solo se avanza una a la vez y no hay vuelta atrás.
    // De "listo" a "entregado" se pasa únicamente con finalizarAPI (verifica el pago completo).
    private const SIGUIENTE = [
        'solicitado'  => 'imprimiendo',
        'imprimiendo' => 'impreso',
        'impreso'     => 'pintando',
        'pintando'    => 'listo',
    ];

    private const ETAPAS = [
        'solicitado'  => 'Solicitado',
        'imprimiendo' => 'Imprimiendo',
        'impreso'     => 'Impreso',
        'pintando'    => 'En pintura',
        'listo'       => 'Listo para entregar',
        'entregado'   => 'Entregado',
    ];

    public static function index(Router $router): void
    {
        $router->render('pedidos/index', []);
    }

    public static function listarAPI(Router $router): void
    {
        getHeadersApi();

        $estado = trim($_GET['estado'] ?? '');
        if ($estado !== '' && !in_array($estado, Pedido::ESTADOS, true)) {
            self::responder(0, 'Estado inválido');
        }

        $datos = ActiveRecord::fetchArray(
            "SELECT p.*, c.nombre AS cliente,
             v.id AS venta_id, v.precio_venta,
             COALESCE(pg.pagado, 0) AS pagado,
             v.precio_venta - COALESCE(pg.pagado, 0) AS saldo,
             CASE WHEN cp.id IS NOT NULL THEN 0 ELSE GREATEST(COALESCE(q.costo_pintura, 0) - COALESCE(gp.pintura, 0), 0) END AS falta_pintura,
             CASE WHEN ce.id IS NOT NULL THEN 0 ELSE GREATEST(COALESCE(q.costo_envio, 0) - COALESCE(gp.envio, 0), 0) END AS falta_envio,
             CASE WHEN co.id IS NOT NULL THEN 0 ELSE GREATEST(COALESCE(q.otros_insumos, 0) - COALESCE(gp.otros, 0), 0) END AS falta_otros
      FROM pedidos p
      JOIN clientes c ON c.id = p.cliente_id
      LEFT JOIN ventas v ON v.pedido_id = p.id
      LEFT JOIN (SELECT venta_id, SUM(monto) AS pagado FROM pagos GROUP BY venta_id) pg ON pg.venta_id = v.id
      LEFT JOIN cotizaciones q ON q.id = v.cotizacion_id
      " . Reporte::joinsCostos() . "
      WHERE (? = '' OR p.estado = ?)
      ORDER BY p.fecha_pedido DESC, p.id DESC",
            [$estado, $estado]
        );
        self::responder(1, '', ['datos' => $datos]);
    }

    public static function crearAPI(Router $router): void
    {
        getHeadersApi();

        $pedido = new Pedido([
            'cliente_id'       => (int)($_POST['cliente_id'] ?? 0),
            'cotizacion_id'    => ((int)($_POST['cotizacion_id'] ?? 0)) ?: null,
            'nombre_mascota'   => trim($_POST['nombre_mascota'] ?? ''),
            'descripcion'      => trim($_POST['descripcion'] ?? '') ?: null,
            'estado'           => 'solicitado', // todo pedido empieza aquí
            'fecha_pedido'     => trim($_POST['fecha_pedido'] ?? date('Y-m-d')),
            'fecha_entrega'    => trim($_POST['fecha_entrega'] ?? '') ?: null,
            'notas'            => trim($_POST['notas'] ?? '') ?: null,
        ]);

        self::errores($pedido->validar());

        $resultado = $pedido->crear();

        self::responder(
            $resultado['resultado'] !== false ? 1 : 0,
            $resultado['resultado'] !== false ? 'Pedido registrado correctamente' : 'Error al registrar',
            ['id' => $resultado['id'] ?? null]
        );
    }

    public static function actualizarAPI(Router $router): void
    {
        getHeadersApi();

        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            self::responder(0, 'ID inválido');
        }

        $pedido = Pedido::find($id);
        if (!$pedido) {
            self::responder(0, 'Pedido no encontrado');
        }

        if (in_array($pedido->estado, ['entregado', 'cancelado'], true)) {
            self::responder(0, 'Un pedido entregado ya no se puede modificar');
        }

        // La etapa no se edita aquí: solo avanza con el botón (estadoAPI). El resto de campos, solo si llegan en el POST
        if (array_key_exists('cliente_id', $_POST)) {
            $pedido->cliente_id = (int)($_POST['cliente_id'] ?? 0);
        }
        if (array_key_exists('cotizacion_id', $_POST)) {
            $pedido->cotizacion_id = ((int)($_POST['cotizacion_id'] ?? 0)) ?: null;
        }
        if (array_key_exists('nombre_mascota', $_POST)) {
            $pedido->nombre_mascota = trim($_POST['nombre_mascota'] ?? '');
        }
        if (array_key_exists('descripcion', $_POST)) {
            $pedido->descripcion = trim($_POST['descripcion'] ?? '') ?: null;
        }
        if (array_key_exists('fecha_pedido', $_POST)) {
            $pedido->fecha_pedido = trim($_POST['fecha_pedido'] ?? date('Y-m-d'));
        }
        if (array_key_exists('fecha_entrega', $_POST)) {
            $pedido->fecha_entrega = trim($_POST['fecha_entrega'] ?? '') ?: null;
        }
        if (array_key_exists('notas', $_POST)) {
            $pedido->notas = trim($_POST['notas'] ?? '') ?: null;
        }

        self::errores($pedido->validar());

        // exec() devuelve 0 si no cambió nada: eso no es un error
        $resultado = $pedido->actualizar();
        self::responder($resultado['resultado'] !== false ? 1 : 0, $resultado['resultado'] !== false ? 'Pedido actualizado' : 'Error al actualizar');
    }

    public static function eliminarAPI(Router $router): void
    {
        getHeadersApi();

        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            self::responder(0, 'ID inválido');
        }

        $uso = ActiveRecord::fetchArray('SELECT COUNT(*) AS total FROM ventas WHERE pedido_id = ?', [$id]);
        if ((int)($uso[0]['total'] ?? 0) > 0) {
            self::responder(0, 'No se puede eliminar, el pedido ya tiene una venta registrada');
        }

        $pedido = Pedido::find($id);
        if (!$pedido) {
            self::responder(0, 'Pedido no encontrado');
        }

        $pedido->eliminar();
        self::responder(1, 'Pedido eliminado correctamente');
    }

    // Pasa el pedido a la etapa SIGUIENTE. No hay vuelta atrás.
    // POST: id y desde (la etapa que el usuario veía): si ya cambió, no se aplica (evita dobles clics).
    public static function estadoAPI(Router $router): void
    {
        getHeadersApi();

        $pedido = Pedido::find((int)($_POST['id'] ?? 0));
        if (!$pedido) {
            self::responder(0, 'Pedido no encontrado');
        }

        if (trim($_POST['desde'] ?? '') !== $pedido->estado) {
            self::responder(0, 'El pedido ya cambió de etapa. Recarga la pantalla');
        }
        if ($pedido->estado === 'listo') {
            self::responder(0, 'Para entregar usa “Finalizar venta”: así se verifica que esté pagado completo');
        }

        $siguiente = self::SIGUIENTE[$pedido->estado] ?? null;
        if (!$siguiente) {
            self::responder(0, 'Este pedido ya está en una etapa final y no se puede cambiar');
        }

        // Para empezar a imprimir tiene que haber filamento suficiente en el inventario
        if ($siguiente === 'imprimiendo') {
            $faltan = Inventario::faltantes((int)$pedido->id);
            if ($faltan) {
                $txt = array_map(
                    fn($f) => $f['nombre'] . ': necesita ' . number_format($f['necesita_kg'], 3) . ' kg y hay ' . number_format($f['disponible_kg'], 3) . ' kg',
                    $faltan
                );
                self::responder(0, 'No hay filamento suficiente para imprimir. ' . implode('; ', $txt) . '. Registra la compra (o tu inventario inicial) en Gastos > Inventario de filamento');
            }
        }

        if (!self::cambiarEtapa((int)$pedido->id, $pedido->estado, $siguiente)) {
            self::responder(0, 'El pedido ya cambió de etapa. Recarga la pantalla');
        }

        self::responder(1, 'Pedido pasado a “' . self::ETAPAS[$siguiente] . '”', ['estado' => $siguiente]);
    }

    // Finaliza la venta: el pedido debe estar "listo" y tener venta. Si falta dinero por cobrar,
    // registra el pago del saldo COMPLETO (con comprobante opcional) y recién entonces lo pasa a "entregado".
    // Todo en una transacción: o se hace todo o no se hace nada.
    public static function finalizarAPI(Router $router): void
    {
        getHeadersApi();

        $pedido = Pedido::find((int)($_POST['id'] ?? 0));
        if (!$pedido) {
            self::responder(0, 'Pedido no encontrado');
        }
        if ($pedido->estado !== 'listo') {
            self::responder(0, 'Solo se puede finalizar un pedido que esté “Listo para entregar”');
        }

        $filas = ActiveRecord::fetchArray('SELECT id FROM ventas WHERE pedido_id = ?', [(int)$pedido->id]);
        $venta = $filas ? Venta::find((int)$filas[0]['id']) : null;
        if (!$venta) {
            self::responder(0, 'Primero registra la venta de este pedido');
        }

        // Fecha de entrega (cuenta para el mes de las ventas en el estado de resultados)
        $fecha = trim($_POST['fecha'] ?? '') ?: date('Y-m-d');
        $partes = explode('-', $fecha);
        if (count($partes) !== 3 || !checkdate((int)$partes[1], (int)$partes[2], (int)$partes[0])) {
            self::responder(0, 'La fecha de entrega no es válida');
        }

        $saldo = round($venta->saldo(), 2);
        $pago = null;

        if ($saldo > 0.005) {
            // El pago final es por TODO lo que falta (el monto lo calcula el servidor)
            $pago = new Pago([
                'venta_id' => (int)$venta->id,
                'monto'    => $saldo,
                'tipo'     => 'saldo',
                'fecha'    => $fecha,
                'notas'    => 'Pago final al entregar'
            ]);
            self::errores($pago->validar());
            $pago->comprobante = self::guardarComprobante();
        }

        $db = ActiveRecord::getDB();
        $db->beginTransaction();

        try {
            if ($pago && $pago->crear()['resultado'] === false) {
                throw new \Exception('pago');
            }
            if (!self::cambiarEtapa((int)$pedido->id, 'listo', 'entregado', $fecha)) {
                throw new \RuntimeException('etapa');
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            if ($pago) {
                self::borrarComprobante($pago->comprobante);
            }
            self::responder(0, $e instanceof \RuntimeException
                ? 'El pedido ya cambió de etapa. Recarga la pantalla'
                : 'No se pudo finalizar la venta');
        }

        self::responder(1, $pago
            ? 'Se registró el pago de Q' . number_format($saldo, 2) . ' y el pedido quedó entregado'
            : 'Venta finalizada: el pedido quedó entregado');
    }

    // Cambio atómico: solo se aplica si el pedido sigue en la etapa de la que se partió.
    // Al pasar a "entregado" guarda también la fecha de entrega.
    private static function cambiarEtapa(int $id, string $desde, string $hacia, ?string $entregadoEn = null): bool
    {
        $db = ActiveRecord::getDB();

        if ($hacia === 'entregado') {
            $stmt = $db->prepare('UPDATE pedidos SET estado = ?, entregado_en = ? WHERE id = ? AND estado = ?');
            $stmt->execute([$hacia, $entregadoEn ?: date('Y-m-d'), $id, $desde]);
        } else {
            $stmt = $db->prepare('UPDATE pedidos SET estado = ? WHERE id = ? AND estado = ?');
            $stmt->execute([$hacia, $id, $desde]);
        }

        return $stmt->rowCount() === 1;
    }
}
