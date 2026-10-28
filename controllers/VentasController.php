<?php

namespace Controllers;

use MVC\Router;
use Model\ActiveRecord;
use Model\Configuracion;
use Model\Cotizacion;
use Model\Pago;
use Model\Pedido;
use Model\Reporte;
use Model\Venta;

class VentasController
{
    use RespuestaApi, Comprobantes;

    public static function index(Router $router): void
    {
        $router->render('ventas/index', []);
    }

    // Cuadro real por venta (estimado vs real). GET: mes = YYYY-MM (opcional)
    public static function listarAPI(Router $router): void
    {
        getHeadersApi();

        $mes = self::mesValido($_GET['mes'] ?? '');
        self::responder(1, '', ['datos' => Reporte::ventas($mes ?: null)]);
    }

    public static function detalleAPI(Router $router): void
    {
        getHeadersApi();

        $id = (int)($_GET['id'] ?? 0);
        $venta = ($fila = Reporte::ventaPorId($id)) ? [$fila] : [];
        if (!$venta) {
            self::responder(0, 'Venta no encontrada');
        }

        $pagos = ActiveRecord::fetchArray('SELECT * FROM pagos WHERE venta_id = ? ORDER BY fecha, id', [$id]);
        $gastos = ActiveRecord::fetchArray(
            'SELECT g.*, cg.nombre AS categoria
             FROM gastos g
             JOIN categorias_gasto cg ON cg.id = g.categoria_id
             WHERE g.venta_id = ?
             ORDER BY g.fecha, g.id',
            [$id]
        );

        self::responder(1, '', ['datos' => ['venta' => $venta[0], 'pagos' => $pagos, 'gastos' => $gastos]]);
    }

    // Registrar venta. Ejemplo: precio_venta = 858, cotizacion_id = 12, anticipo = 400.
    // Si llegan pedido_id o cotizacion_id, cliente y descripción se toman de ahí cuando no se envían.
    public static function crearAPI(Router $router): void
    {
        getHeadersApi();

        $cotizacionId = (int)($_POST['cotizacion_id'] ?? 0);
        $cotizacion = $cotizacionId ? Cotizacion::find($cotizacionId) : null;
        if ($cotizacionId && !$cotizacion) {
            self::responder(0, 'Cotización no encontrada');
        }
        if ($cotizacion) {
            $yaTiene = ActiveRecord::fetchArray('SELECT COUNT(*) AS total FROM ventas WHERE cotizacion_id = ?', [$cotizacionId]);
            if ((int)($yaTiene[0]['total'] ?? 0) > 0) {
                self::responder(0, 'Esa cotización ya tiene una venta registrada');
            }
        }

        $pedidoId = (int)($_POST['pedido_id'] ?? 0);
        $pedido = $pedidoId ? Pedido::find($pedidoId) : null;
        if ($pedidoId && !$pedido) {
            self::responder(0, 'Pedido no encontrado');
        }
        if ($pedido) {
            $yaVendido = ActiveRecord::fetchArray('SELECT COUNT(*) AS total FROM ventas WHERE pedido_id = ?', [$pedidoId]);
            if ((int)($yaVendido[0]['total'] ?? 0) > 0) {
                self::responder(0, 'Ese pedido ya tiene una venta registrada');
            }
        }

        $cfg = Configuracion::vigente();

        $clienteId = (int)($_POST['cliente_id'] ?? 0) ?: (int)($pedido->cliente_id ?? $cotizacion->cliente_id ?? 0);
        $descripcion = trim($_POST['descripcion'] ?? '') ?: ($pedido->nombre_mascota ?? $cotizacion->nombre_pieza ?? '');

        $venta = new Venta([
            'pedido_id'     => $pedidoId ?: null,
            'cotizacion_id' => $cotizacionId ?: ($pedido->cotizacion_id ?? null),
            'cliente_id'    => $clienteId,
            'descripcion'   => $descripcion,
            'fecha_venta'   => trim($_POST['fecha_venta'] ?? date('Y-m-d')),
            'precio_venta'  => (float)($_POST['precio_venta'] ?? 0),
            'impuesto_pct'  => $_POST['impuesto_pct'] ?? ($cfg->impuesto_pct ?? 0),
            'notas'         => trim($_POST['notas'] ?? '') ?: null
        ]);

        self::errores($venta->validar());

        $anticipo = (float)($_POST['anticipo'] ?? 0);
        if ($anticipo < 0 || $anticipo > (float)$venta->precio_venta) {
            self::responder(0, 'El anticipo no puede ser negativo ni mayor al precio de venta');
        }

        // Comprobante del anticipo (captura de la transferencia). Si no hay anticipo, no se guarda nada.
        $comprobante = $anticipo > 0 ? self::guardarComprobante() : null;

        $db = ActiveRecord::getDB();
        $db->beginTransaction();

        try {
            // El pedido entra al tablero de producción: con anticipo ya se puede imprimir; sin anticipo queda solicitado
            if (!$pedido) {
                $nuevoPedido = new Pedido([
                    'cliente_id'     => $clienteId,
                    'cotizacion_id'  => $cotizacionId ?: null,
                    'nombre_mascota' => $descripcion,
                    'estado'         => 'solicitado', // todo pedido empieza aquí; se avanza a mano
                    'fecha_pedido'   => $venta->fecha_venta
                ]);
                $venta->pedido_id = (int)$nuevoPedido->crear()['id'];
                $estadoPedido = $nuevoPedido->estado;
            } else {
                $estadoPedido = $pedido->estado;
            }

            $resultado = $venta->crear();
            $ventaId = (int)$resultado['id'];

            if ($anticipo > 0) {
                $pago = new Pago([
                    'venta_id' => $ventaId,
                    'monto'    => $anticipo,
                    'tipo'     => 'anticipo',
                    'fecha'    => $venta->fecha_venta,
                    'comprobante' => $comprobante
                ]);
                $pago->crear();
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            self::borrarComprobante($comprobante);
            self::responder(0, 'No se pudo registrar la venta');
        }

        self::responder(1, 'Venta registrada correctamente', ['id' => $ventaId, 'estado_pedido' => $estadoPedido]);
    }

    public static function actualizarAPI(Router $router): void
    {
        getHeadersApi();

        $id = (int)($_POST['id'] ?? 0);
        $venta = $id ? Venta::find($id) : null;
        if (!$venta) {
            self::responder(0, 'Venta no encontrada');
        }

        if (array_key_exists('descripcion', $_POST)) {
            $venta->descripcion = trim($_POST['descripcion']);
        }
        if (array_key_exists('fecha_venta', $_POST)) {
            $venta->fecha_venta = trim($_POST['fecha_venta']);
        }
        if (array_key_exists('precio_venta', $_POST)) {
            $venta->precio_venta = (float)$_POST['precio_venta'];
        }
        if (array_key_exists('notas', $_POST)) {
            $venta->notas = trim($_POST['notas']) ?: null;
        }

        self::errores($venta->validar());

        // exec() devuelve 0 si no cambió nada: eso no es un error
        $resultado = $venta->actualizar();
        self::responder($resultado['resultado'] !== false ? 1 : 0, $resultado['resultado'] !== false ? 'Venta actualizada' : 'Error al actualizar');
    }

    // Registrar un cobro (anticipo o saldo). No permite cobrar más de lo que falta.
    // Registrar un cobro (anticipo o saldo) con comprobante opcional. No permite cobrar más de lo que falta.
    public static function pagoAPI(Router $router): void
    {
        getHeadersApi();

        $venta = Venta::find((int)($_POST['venta_id'] ?? 0));
        if (!$venta) {
            self::responder(0, 'Venta no encontrada');
        }

        $pago = new Pago([
            'venta_id' => (int)$venta->id,
            'monto'    => (float)($_POST['monto'] ?? 0),
            'tipo'     => trim($_POST['tipo'] ?? 'saldo'),
            'fecha'    => trim($_POST['fecha'] ?? date('Y-m-d')),
            'notas'    => trim($_POST['notas'] ?? '') ?: null
        ]);

        self::errores($pago->validar());

        $saldo = $venta->saldo();
        if ($pago->monto > $saldo + 0.005) {
            self::responder(0, 'El pago excede el saldo pendiente (Q' . number_format($saldo, 2) . ')');
        }

        $pago->comprobante = self::guardarComprobante();

        try {
            $resultado = $pago->crear();
        } catch (\Throwable $e) {
            self::borrarComprobante($pago->comprobante);
            self::responder(0, 'Error al registrar el pago');
        }
        if ($resultado['resultado'] === false) {
            self::borrarComprobante($pago->comprobante);
            self::responder(0, 'Error al registrar el pago');
        }

        self::responder(1, 'Pago registrado', ['id' => $resultado['id'] ?? null, 'saldo' => round($saldo - $pago->monto, 2)]);
    }

    // Muestra un comprobante. Los archivos viven en storage/comprobantes (no se abren directo por URL).
    public static function comprobanteAPI(Router $router): void
    {
        $pago = Pago::find((int)($_GET['id'] ?? 0));
        $ruta = $pago && $pago->comprobante ? self::carpetaComprobantes(false) . '/' . basename($pago->comprobante) : '';

        if (!$ruta || !is_file($ruta)) {
            http_response_code(404);
            exit('Comprobante no encontrado');
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($ruta);
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($ruta));
        header('Content-Disposition: inline; filename="comprobante-' . (int)$pago->id . '.' . pathinfo($ruta, PATHINFO_EXTENSION) . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($ruta);
        exit;
    }

    // Corrige un pago (monto, tipo, fecha, notas o comprobante). Los pagos se editan, no se borran desde la pantalla.
    public static function actualizarPagoAPI(Router $router): void
    {
        getHeadersApi();

        $pago = Pago::find((int)($_POST['id'] ?? 0));
        if (!$pago) {
            self::responder(0, 'Pago no encontrado');
        }
        $venta = Venta::find((int)$pago->venta_id);
        if (!$venta) {
            self::responder(0, 'Venta no encontrada');
        }

        // Si la venta ya está entregada (y pagada completa), el monto queda fijo; fecha y comprobante sí se pueden corregir
        $entregada = ActiveRecord::fetchArray(
            "SELECT COUNT(*) AS total FROM ventas v JOIN pedidos p ON p.id = v.pedido_id WHERE v.id = ? AND p.estado = 'entregado'",
            [(int)$venta->id]
        );
        $monto = (float)($_POST['monto'] ?? $pago->monto);
        if ((int)($entregada[0]['total'] ?? 0) > 0 && abs($monto - (float)$pago->monto) > 0.005) {
            self::responder(0, 'La venta ya está entregada y pagada: el monto no se puede cambiar. Puedes corregir la fecha o el comprobante');
        }

        $pago->monto = $monto;
        $pago->tipo = trim($_POST['tipo'] ?? $pago->tipo);
        $pago->fecha = trim($_POST['fecha'] ?? $pago->fecha);
        if (array_key_exists('notas', $_POST)) {
            $pago->notas = trim($_POST['notas']) ?: null;
        }
        self::errores($pago->validar());

        // Lo cobrado no puede pasar del precio de la venta
        $otros = ActiveRecord::fetchArray('SELECT COALESCE(SUM(monto), 0) AS total FROM pagos WHERE venta_id = ? AND id <> ?', [(int)$venta->id, (int)$pago->id]);
        if ((float)$otros[0]['total'] + $pago->monto > (float)$venta->precio_venta + 0.005) {
            self::responder(0, 'Con ese monto lo cobrado pasaría del precio de la venta (Q' . number_format((float)$venta->precio_venta, 2) . ')');
        }

        // Si sube un comprobante nuevo, reemplaza al anterior
        $anterior = $pago->comprobante;
        $nuevo = self::guardarComprobante();
        if ($nuevo) {
            $pago->comprobante = $nuevo;
        }

        $resultado = $pago->actualizar();
        if ($resultado['resultado'] === false) {
            self::borrarComprobante($nuevo);
            self::responder(0, 'Error al actualizar el pago');
        }
        if ($nuevo) {
            self::borrarComprobante($anterior);
        }

        self::responder(1, 'Pago actualizado');
    }

    public static function eliminarPagoAPI(Router $router): void
    {
        getHeadersApi();

        $pago = Pago::find((int)($_POST['id'] ?? 0));
        if (!$pago) {
            self::responder(0, 'Pago no encontrado');
        }

        $pago->eliminar();
        self::borrarComprobante($pago->comprobante);
        self::responder(1, 'Pago eliminado');
    }

    // Los pagos se borran con la venta; sus gastos quedan como gastos generales (venta_id en NULL).
    public static function eliminarAPI(Router $router): void
    {
        getHeadersApi();

        $venta = Venta::find((int)($_POST['id'] ?? 0));
        if (!$venta) {
            self::responder(0, 'Venta no encontrada');
        }

        $archivos = ActiveRecord::fetchArray('SELECT comprobante FROM pagos WHERE venta_id = ? AND comprobante IS NOT NULL', [(int)$venta->id]);
        $venta->eliminar();
        foreach ($archivos as $a) {
            self::borrarComprobante($a['comprobante']);
        }
        self::responder(1, 'Venta eliminada correctamente');
    }
}
