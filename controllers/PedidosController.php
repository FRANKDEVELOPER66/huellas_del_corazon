<?php

namespace Controllers;

use MVC\Router;
use Model\ActiveRecord;
use Model\Cancelacion;
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
             v.id AS venta_id, v.precio_venta, qp.precio_sugerido AS precio_cotizado,
             COALESCE(pg.pagado, 0) AS pagado,
             CASE WHEN p.estado = 'cancelado' THEN 0 ELSE v.precio_venta - COALESCE(pg.pagado, 0) END AS saldo,
             COALESCE(fa.gramos, 0) AS falla_g, COALESCE(fa.costo, 0) AS falla_q,
             cn.motivo AS cancel_motivo, cn.fecha AS cancel_fecha, cn.devuelto AS cancel_devuelto, cn.retenido AS cancel_retenido,
             CASE WHEN cp.id IS NOT NULL THEN 0 ELSE GREATEST(COALESCE(q.costo_pintura, 0) - COALESCE(gp.pintura, 0), 0) END AS falta_pintura,
             CASE WHEN ce.id IS NOT NULL THEN 0 ELSE GREATEST(COALESCE(q.costo_envio, 0) - COALESCE(gp.envio, 0), 0) END AS falta_envio,
             CASE WHEN co.id IS NOT NULL THEN 0 ELSE GREATEST(COALESCE(q.otros_insumos, 0) - COALESCE(gp.otros, 0), 0) END AS falta_otros
      FROM pedidos p
      JOIN clientes c ON c.id = p.cliente_id
      LEFT JOIN ventas v ON v.pedido_id = p.id
      " . Cancelacion::join() . "
      LEFT JOIN (SELECT venta_id, SUM(monto) AS pagado FROM pagos GROUP BY venta_id) pg ON pg.venta_id = v.id
      LEFT JOIN cotizaciones q ON q.id = v.cotizacion_id
      LEFT JOIN cotizaciones qp ON qp.id = p.cotizacion_id
      " . (Inventario::hayConsumos()
            ? "LEFT JOIN (SELECT pedido_id, SUM(gramos) AS gramos, SUM(costo) AS costo FROM carrete_consumos WHERE tipo = 'falla' GROUP BY pedido_id) fa ON fa.pedido_id = p.id"
            : "LEFT JOIN (SELECT 0 AS pedido_id, 0 AS gramos, 0 AS costo FROM DUAL WHERE 1 = 0) fa ON fa.pedido_id = p.id") . "
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

        // Para empezar a imprimir tiene que haber filamento suficiente (del color que pide la cotización)
        if ($siguiente === 'imprimiendo') {
            $faltan = Inventario::faltantes((int)$pedido->id);
            if ($faltan) {
                $txt = array_map(
                    fn($f) => ($f['color'] !== '' ? $f['color'] . ' ' : '') . $f['nombre'] . ': necesita ' . number_format($f['necesita_g'], 0) . ' g y hay ' . number_format($f['disponible_g'], 0) . ' g',
                    $faltan
                );
                self::responder(0, 'No hay filamento suficiente para imprimir. ' . implode('; ', $txt) . '. Registra la compra (o tu inventario inicial) en Gastos > Inventario de filamento');
            }
        }

        // Al pasar a "imprimiendo" el filamento sale de los carretes: todo junto o nada
        $db = ActiveRecord::getDB();
        $db->beginTransaction();
        $tocados = [];
        try {
            if (!self::cambiarEtapa((int)$pedido->id, $pedido->estado, $siguiente)) {
                $db->rollBack();
                self::responder(0, 'El pedido ya cambió de etapa. Recarga la pantalla');
            }
            if ($siguiente === 'imprimiendo') {
                $tocados = Inventario::consumirPedido((int)$pedido->id);
            }
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            self::responder(0, 'No se pudo pasar el pedido de etapa');
        }

        // Aviso de poco filamento en los colores que acaba de usar
        $alertas = $tocados ? Inventario::alertasColores($tocados) : [];

        self::responder(1, 'Pedido pasado a “' . self::ETAPAS[$siguiente] . '”', ['estado' => $siguiente, 'alertas' => $alertas]);
    }

    // Carretes que puede usar una impresión fallida de este pedido (primero los de los colores de la pieza)
    public static function carretesAPI(Router $router): void
    {
        getHeadersApi();

        $pedido = Pedido::find((int)($_GET['id'] ?? 0));
        if (!$pedido) {
            self::responder(0, 'Pedido no encontrado');
        }
        self::responder(1, '', ['datos' => Inventario::carretesParaFalla((int)$pedido->id)]);
    }

    // Registra una impresión fallida: saca los gramos perdidos del carrete y suma su costo a esa pieza.
    // POST: id (pedido), carrete_id, gramos, motivo. Solo mientras el pedido está en "Imprimiendo".
    public static function fallaAPI(Router $router): void
    {
        getHeadersApi();

        if (!Inventario::hayConsumos()) {
            self::responder(0, 'Falta crear las tablas de carretes: ejecuta sql/carretes_paso2.sql');
        }
        $pedido = Pedido::find((int)($_POST['id'] ?? 0));
        if (!$pedido) {
            self::responder(0, 'Pedido no encontrado');
        }
        if ($pedido->estado !== 'imprimiendo') {
            self::responder(0, 'Una impresión fallida se registra mientras el pedido está en “Imprimiendo”');
        }

        $gramos = round((float)($_POST['gramos'] ?? 0), 2);
        if (!($gramos > 0) || $gramos > 5000) {
            self::responder(0, 'Escribe los gramos que se perdieron (mayor a 0)');
        }
        $motivo = trim((string)($_POST['motivo'] ?? ''));
        if (mb_strlen($motivo) < 3) {
            self::responder(0, 'Elige o escribe el motivo de la falla');
        }

        $filas = ActiveRecord::fetchArray('SELECT * FROM carretes WHERE id = ?', [(int)($_POST['carrete_id'] ?? 0)]);
        if (!$filas) {
            self::responder(0, 'Elige de qué carrete salió el filamento');
        }
        $k = $filas[0];
        if ($gramos > (float)$k['gramos_actuales'] + 0.005) {
            self::responder(0, 'Ese carrete solo tiene ' . number_format((float)$k['gramos_actuales'], 0) . ' g. Si el peso está mal, corrígelo en el inventario; si la falla usó dos carretes, regístrala en dos partes');
        }

        $costo = round($gramos * (float)$k['costo'] / (float)$k['peso_inicial_g'], 2);

        $db = ActiveRecord::getDB();
        $db->beginTransaction();
        try {
            $db->prepare('UPDATE carretes SET gramos_actuales = gramos_actuales - ? WHERE id = ?')->execute([$gramos, (int)$k['id']]);
            $db->prepare("INSERT INTO carrete_consumos (carrete_id, pedido_id, tipo, gramos, costo, motivo) VALUES (?, ?, 'falla', ?, ?, ?)")
                ->execute([(int)$k['id'], (int)$pedido->id, $gramos, $costo, mb_substr($motivo, 0, 250)]);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            self::responder(0, 'No se pudo registrar la falla');
        }

        $alertas = Inventario::alertasColores([[(int)$k['material_id'], (string)$k['color']]]);
        self::responder(1, 'Falla registrada: ' . number_format($gramos, 0) . ' g · Q' . number_format($costo, 2) . ' se suman al costo de la pieza', ['costo' => $costo, 'alertas' => $alertas]);
    }

    // ── Cancelar un pedido (el cliente se arrepiente) ────────────────────────
    // Política: se devuelve el 50% de lo cobrado y el otro 50% se retiene por el trabajo perdido.
    // Lo gastado en filamento (pieza + fallas) no se recupera: queda como costo de esta pieza.

    // Cifras para mostrar antes de confirmar
    private static function cifrasCancelacion(Pedido $pedido): array
    {
        $v = ActiveRecord::fetchArray('SELECT id, precio_venta, cotizacion_id FROM ventas WHERE pedido_id = ?', [(int)$pedido->id]);
        $venta = $v[0] ?? null;
        $pagado = 0.0;
        $directos = 0.0;
        if ($venta) {
            $pagado = (float)ActiveRecord::fetchArray('SELECT COALESCE(SUM(monto), 0) AS t FROM pagos WHERE venta_id = ?', [(int)$venta['id']])[0]['t'];
            $directos = (float)ActiveRecord::fetchArray('SELECT COALESCE(SUM(monto), 0) AS t FROM gastos WHERE venta_id = ?', [(int)$venta['id']])[0]['t'];
        }

        // Filamento ya gastado: lo real de los carretes; si la pieza empezó antes de usar carretes, lo estimado de la cotización
        $material = 0.0;
        if ($pedido->estado !== 'solicitado') {
            $filas = Inventario::hayConsumos()
                ? ActiveRecord::fetchArray('SELECT COUNT(*) AS n, COALESCE(SUM(costo), 0) AS c FROM carrete_consumos WHERE pedido_id = ?', [(int)$pedido->id])
                : [['n' => 0, 'c' => 0]];
            if ((int)$filas[0]['n'] > 0) {
                $material = (float)$filas[0]['c'];
            } elseif ($venta && $venta['cotizacion_id']) {
                $material = (float)ActiveRecord::fetchArray('SELECT costo_material AS c FROM cotizaciones WHERE id = ?', [(int)$venta['cotizacion_id']])[0]['c'];
            }
        }

        $sugerido = round($pagado * Cancelacion::DEVOLUCION_PCT / 100, 2);
        return [
            'venta_id'   => $venta ? (int)$venta['id'] : null,
            'precio'     => $venta ? round((float)$venta['precio_venta'], 2) : 0,
            'pagado'     => round($pagado, 2),
            'material'   => round($material, 2),
            'directos'   => round($directos, 2),
            'pct'        => Cancelacion::DEVOLUCION_PCT,
            'sugerido'   => $sugerido,
        ];
    }

    public static function cancelacionAPI(Router $router): void
    {
        getHeadersApi();
        $pedido = Pedido::find((int)($_GET['id'] ?? 0));
        if (!$pedido) {
            self::responder(0, 'Pedido no encontrado');
        }
        if (!isset(self::ETAPAS[$pedido->estado]) || $pedido->estado === 'entregado') {
            self::responder(0, 'Solo se puede cancelar un pedido que todavía no se entrega');
        }
        self::responder(1, '', ['datos' => self::cifrasCancelacion($pedido)]);
    }

    // POST: id, devolucion (0..pagado), motivo (mín. 5 letras), fecha (opcional, hoy por defecto)
    public static function cancelarAPI(Router $router): void
    {
        getHeadersApi();

        if (!Cancelacion::hayTabla()) {
            self::responder(0, 'Falta crear la tabla de cancelaciones (sql/cancelaciones.sql)');
        }
        $pedido = Pedido::find((int)($_POST['id'] ?? 0));
        if (!$pedido) {
            self::responder(0, 'Pedido no encontrado');
        }
        if (!isset(self::ETAPAS[$pedido->estado]) || $pedido->estado === 'entregado') {
            self::responder(0, 'Solo se puede cancelar un pedido que todavía no se entrega (o que ya está cancelado)');
        }

        $motivo = trim($_POST['motivo'] ?? '');
        if (mb_strlen($motivo) < 5) {
            self::responder(0, 'Escribe el motivo de la cancelación (mínimo 5 letras)');
        }
        $fecha = trim($_POST['fecha'] ?? '') ?: date('Y-m-d');
        $partes = explode('-', $fecha);
        if (count($partes) !== 3 || !checkdate((int)$partes[1], (int)$partes[2], (int)$partes[0])) {
            self::responder(0, 'La fecha no es válida');
        }
        if ($fecha > date('Y-m-d')) {
            self::responder(0, 'La fecha de cancelación no puede ser futura');
        }
        self::exigirAbierta($fecha);

        $c = self::cifrasCancelacion($pedido);
        $devolucion = round((float)($_POST['devolucion'] ?? $c['sugerido']), 2);
        if ($devolucion < 0 || $devolucion > $c['pagado'] + 0.005) {
            self::responder(0, 'La devolución debe estar entre Q0.00 y lo cobrado (Q' . number_format($c['pagado'], 2) . ')');
        }
        if ($c['pagado'] <= 0.005) {
            $devolucion = 0.0;
        }
        $retenido = round($c['pagado'] - $devolucion, 2);

        $db = ActiveRecord::getDB();
        $db->beginTransaction();
        try {
            $up = $db->prepare("UPDATE pedidos SET estado = 'cancelado' WHERE id = ? AND estado = ?");
            $up->execute([(int)$pedido->id, $pedido->estado]);
            if ($up->rowCount() !== 1) {
                throw new \RuntimeException('etapa');
            }

            // La devolución es un cobro NEGATIVO: así caja, conciliación con el banco y cierres la reflejan sin cambios
            if ($devolucion > 0.005) {
                $pago = new Pago([
                    'venta_id' => (int)$c['venta_id'],
                    'monto'    => -$devolucion,
                    'tipo'     => 'otro',
                    'fecha'    => $fecha,
                    'notas'    => 'Devolución por cancelación: ' . $motivo,
                ]);
                if ($pago->crear()['resultado'] === false) {
                    throw new \Exception('pago');
                }
            }

            $ins = $db->prepare('INSERT INTO cancelaciones (pedido_id, venta_id, etapa, fecha, motivo, pagado, devuelto, retenido, costo_material) VALUES (?,?,?,?,?,?,?,?,?)');
            $ins->execute([(int)$pedido->id, $c['venta_id'], $pedido->estado, $fecha, mb_substr($motivo, 0, 255), $c['pagado'], $devolucion, $retenido, $c['material']]);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            self::responder(0, $e instanceof \RuntimeException
                ? 'El pedido ya cambió de etapa. Recarga la pantalla'
                : 'No se pudo cancelar el pedido');
        }

        $perdida = round($c['material'] + $c['directos'], 2);
        self::responder(1, 'Pedido cancelado', [
            'pagado'    => $c['pagado'],
            'devuelto'  => $devolucion,
            'retenido'  => $retenido,
            'material'  => $c['material'],
            'directos'  => $c['directos'],
            'resultado' => round($retenido - $perdida, 2),
        ]);
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
        self::exigirAbierta($fecha);

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
