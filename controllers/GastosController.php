<?php

namespace Controllers;

use MVC\Router;
use Model\ActiveRecord;
use Model\Gasto;
use Model\CategoriaGasto;
use Model\Reporte;

class GastosController
{
    use RespuestaApi, Comprobantes, ReglasGasto;

    public static function index(Router $router): void
    {
        $router->render('gastos/index', []);
    }

    public static function listarAPI(Router $router): void
    {
        getHeadersApi();

        $mes = self::mesValido($_GET['mes'] ?? '');

        $datos = ActiveRecord::fetchArray(
            "SELECT g.*, cg.nombre AS categoria, cg.tipo AS tipo_categoria,
                    cm.id AS compra_id, cm.kg AS compra_kg, cm.material_id,
                    (SELECT COUNT(*) FROM gastos_historial h WHERE h.tipo = 'gasto' AND h.ref_id = g.id) AS ediciones
             FROM gastos g
             JOIN categorias_gasto cg ON cg.id = g.categoria_id
             LEFT JOIN compras_material cm ON cm.gasto_id = g.id
             WHERE (? = '' OR DATE_FORMAT(g.fecha, '%Y-%m') = ?)
             ORDER BY g.fecha DESC, g.id DESC",
            [$mes, $mes]
        );
        self::responder(1, '', ['datos' => $datos]);
    }

    // Reglas según el tipo de categoría
    private static function reglasCategoria(CategoriaGasto $cat, ?int $ventaId): void
    {
        if ($cat->tipo === 'inventario') {
            self::responder(0, 'Las compras de filamento se registran en “Inventario de filamento > Comprar filamento”: así suman kg y costo al inventario');
        }
        if ($cat->tipo === 'directo' && !$ventaId) {
            self::responder(0, 'Los pagos de pintora, envíos y otros insumos se registran en “Pagos pendientes de las piezas”, ligados a la pieza');
        }
        if ($cat->tipo !== 'directo' && $ventaId) {
            self::responder(0, 'Solo los costos de una pieza (pintora, envíos, otros) se ligan a una venta');
        }
    }

    public static function crearAPI(Router $router): void
    {
        getHeadersApi();

        $origen = self::origenValido($_POST['origen'] ?? '');

        $gasto = new Gasto([
            'fecha'          => trim($_POST['fecha'] ?? date('Y-m-d')),
            'categoria_id'   => (int)($_POST['categoria_id'] ?? 0),
            'venta_id'       => ((int)($_POST['venta_id'] ?? 0)) ?: null,
            'pintor_id'      => ((int)($_POST['pintor_id'] ?? 0)) ?: null,
            'descripcion'    => trim($_POST['descripcion'] ?? ''),
            'monto'          => round((float)($_POST['monto'] ?? 0), 2),
            'origen'         => $origen,
        ]);

        self::errores($gasto->validar());
        self::fechaNoFutura($gasto->fecha);

        $cat = CategoriaGasto::find((int)$gasto->categoria_id);
        if (!$cat) {
            self::responder(0, 'La categoría no existe');
        }
        self::reglasCategoria($cat, $gasto->venta_id ? (int)$gasto->venta_id : null);

        // Comprobante obligatorio desde cierto monto
        if ($gasto->monto >= self::comprobanteDesde() && empty($_FILES['comprobante']['name'])) {
            self::responder(0, 'Los gastos de Q' . number_format(self::comprobanteDesde(), 0) . ' o más llevan comprobante: sube la captura o la factura');
        }

        // Si sale de la caja, tiene que alcanzar
        if ($origen === 'caja') {
            self::exigirCaja((float)$gasto->monto, $cat->tipo);
        }

        // Posible duplicado (se puede confirmar)
        if (empty($_POST['confirmar_duplicado'])) {
            $params = [$gasto->fecha, (int)$gasto->categoria_id, (float)$gasto->monto];
            $filtroVenta = 'venta_id IS NULL';
            if ($gasto->venta_id) {
                $filtroVenta = 'venta_id = ?';
                $params[] = (int)$gasto->venta_id;
            }
            $dup = ActiveRecord::fetchArray(
                "SELECT COUNT(*) AS total FROM gastos
                 WHERE fecha = ? AND categoria_id = ? AND ABS(monto - ?) < 0.005 AND $filtroVenta",
                $params
            );
            if ((int)($dup[0]['total'] ?? 0) > 0) {
                self::responder(0, 'Ya registraste un gasto igual (misma fecha, categoría y monto). Si es otro gasto distinto, confírmalo.', ['duplicado' => 1]);
            }
        }

        $gasto->comprobante = self::guardarComprobante();

        $db = ActiveRecord::getDB();
        $db->beginTransaction();
        try {
            $resultado = $gasto->crear();
            if ($resultado['resultado'] === false) {
                throw new \RuntimeException('No se pudo guardar');
            }
            $gastoId = (int)$resultado['id'];
            self::sincronizarAporte($gastoId, $origen, (float)$gasto->monto, $gasto->fecha, $gasto->descripcion);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            self::borrarComprobante($gasto->comprobante);
            self::responder(0, 'Error al registrar el gasto');
        }

        // “Con este pago queda saldado”: ya no falta pagar nada de ese concepto (pintora, envío u otros)
        if (!empty($_POST['cerrar']) && $gasto->venta_id && $cat->concepto) {
            self::cerrarConcepto((int)$gasto->venta_id, $cat->concepto);
        }

        self::responder(1, 'Gasto registrado correctamente', ['id' => $gastoId]);
    }

    public static function actualizarAPI(Router $router): void
    {
        getHeadersApi();

        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            self::responder(0, 'ID inválido');
        }

        $gasto = Gasto::find($id);
        if (!$gasto) {
            self::responder(0, 'Gasto no encontrado');
        }
        $catActual = CategoriaGasto::find((int)$gasto->categoria_id);
        if ($catActual && $catActual->tipo === 'inventario') {
            self::responder(0, 'Las compras de filamento se editan en “Inventario de filamento”');
        }

        $antes = [
            'fecha'       => (string)$gasto->fecha,
            'categoria_id' => (int)$gasto->categoria_id,
            'descripcion' => $gasto->descripcion,
            'monto'       => (float)$gasto->monto,
            'origen'      => $gasto->origen ?: 'caja',
            'comprobante' => $gasto->comprobante,
        ];
        $cajaAntes = $antes['origen'] === 'caja' ? $antes['monto'] : 0.0;

        // Solo cambia los campos que llegan en el POST (la pieza a la que pertenece no se cambia)
        if (array_key_exists('fecha', $_POST)) {
            $gasto->fecha = trim($_POST['fecha'] ?? date('Y-m-d'));
        }
        if (array_key_exists('categoria_id', $_POST)) {
            $gasto->categoria_id = (int)($_POST['categoria_id'] ?? 0);
        }
        if (array_key_exists('pintor_id', $_POST)) {
            $gasto->pintor_id = ((int)($_POST['pintor_id'] ?? 0)) ?: null;
        }
        if (array_key_exists('descripcion', $_POST)) {
            $gasto->descripcion = trim($_POST['descripcion'] ?? '');
        }
        if (array_key_exists('monto', $_POST)) {
            $gasto->monto = round((float)($_POST['monto'] ?? 0), 2);
        }
        if (array_key_exists('origen', $_POST)) {
            $gasto->origen = self::origenValido($_POST['origen']);
        }
        $gasto->origen = $gasto->origen ?: 'caja';

        self::errores($gasto->validar());
        self::fechaNoFutura($gasto->fecha);

        $cat = CategoriaGasto::find((int)$gasto->categoria_id);
        if (!$cat) {
            self::responder(0, 'La categoría no existe');
        }
        if ($catActual && $cat->tipo !== $catActual->tipo) {
            self::responder(0, 'Solo puedes cambiar a otra categoría del mismo tipo');
        }
        self::reglasCategoria($cat, $gasto->venta_id ? (int)$gasto->venta_id : null);

        $hayArchivo = !empty($_FILES['comprobante']['name']);
        if ($gasto->monto >= self::comprobanteDesde() && !$gasto->comprobante && !$hayArchivo) {
            self::responder(0, 'Los gastos de Q' . number_format(self::comprobanteDesde(), 0) . ' o más llevan comprobante: sube la captura o la factura');
        }

        // Solo se exige caja por lo que se agrega: si el gasto sube o pasa de bolsillo a caja
        $cajaDespues = $gasto->origen === 'caja' ? (float)$gasto->monto : 0.0;
        if ($cajaDespues - $cajaAntes > 0.005) {
            self::exigirCaja($cajaDespues - $cajaAntes, $cat->tipo);
        }

        // El comprobante anterior NO se borra: queda como respaldo del historial
        $nuevo = self::guardarComprobante();
        if ($nuevo) {
            $gasto->comprobante = $nuevo;
        }

        $despues = [
            'fecha'       => (string)$gasto->fecha,
            'categoria_id' => (int)$gasto->categoria_id,
            'descripcion' => $gasto->descripcion,
            'monto'       => (float)$gasto->monto,
            'origen'      => $gasto->origen,
            'comprobante' => $gasto->comprobante,
        ];

        $db = ActiveRecord::getDB();
        $db->beginTransaction();
        try {
            if ($despues != $antes) {
                self::guardarHistorial('gasto', $id, $antes);
            }
            // exec() devuelve 0 si no cambió nada: eso no es un error
            $resultado = $gasto->actualizar();
            if ($resultado['resultado'] === false) {
                throw new \RuntimeException('No se pudo actualizar');
            }
            self::sincronizarAporte($id, $gasto->origen, (float)$gasto->monto, $gasto->fecha, $gasto->descripcion);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            self::borrarComprobante($nuevo);
            self::responder(0, 'Error al actualizar');
        }

        self::responder(1, 'Gasto actualizado');
    }

    // Los gastos no se borran: se editan (queda el historial de cómo estaban)
    public static function eliminarAPI(Router $router): void
    {
        getHeadersApi();
        self::responder(0, 'Los gastos no se borran. Si hay un error, edítalo: queda guardado cómo estaba antes');
    }

    // Cómo estaba un gasto o una compra antes de cada edición
    public static function historialAPI(Router $router): void
    {
        getHeadersApi();

        $tipo = ($_GET['tipo'] ?? 'gasto') === 'compra' ? 'compra' : 'gasto';
        $filas = ActiveRecord::fetchArray(
            'SELECT anterior, cambiado_en FROM gastos_historial WHERE tipo = ? AND ref_id = ? ORDER BY id DESC',
            [$tipo, (int)($_GET['id'] ?? 0)]
        );
        foreach ($filas as &$f) {
            $f['anterior'] = json_decode($f['anterior'], true);
        }
        unset($f);

        self::responder(1, '', ['datos' => $filas]);
    }

    // Para el selector de categoría
    public static function categoriasAPI(Router $router): void
    {
        getHeadersApi();
        self::responder(1, '', ['datos' => CategoriaGasto::all()]);
    }

    // Control de pagos a la pintora: por pieza, lo acordado contra lo ya pagado
    public static function pintoraAPI(Router $router): void
    {
        getHeadersApi();
        self::responder(1, '', ['datos' => Reporte::pendientesPiezas()]);
    }

    // Muestra el comprobante de un gasto (los archivos no se abren directo por URL)
    public static function comprobanteAPI(Router $router): void
    {
        $gasto = Gasto::find((int)($_GET['id'] ?? 0));
        $ruta = $gasto && $gasto->comprobante ? self::carpetaComprobantes(false) . '/' . basename($gasto->comprobante) : '';

        if (!$ruta || !is_file($ruta)) {
            http_response_code(404);
            exit('Comprobante no encontrado');
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($ruta);
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($ruta));
        header('Content-Disposition: inline; filename="gasto-' . (int)$gasto->id . '.' . pathinfo($ruta, PATHINFO_EXTENSION) . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($ruta);
        exit;
    }

    // Saldar un costo de la pieza sin pagar más (más barato de lo acordado, o no hizo falta):
    // su costo final queda en lo que ya se pagó y deja de aparecer como pendiente.
    public static function cerrarAPI(Router $router): void
    {
        getHeadersApi();

        $ventaId = (int)($_POST['venta_id'] ?? 0);
        $concepto = trim($_POST['concepto'] ?? '');
        if (!$ventaId || !in_array($concepto, ['pintura', 'envio', 'otros'], true)) {
            self::responder(0, 'Datos inválidos');
        }

        $existe = ActiveRecord::fetchArray('SELECT COUNT(*) AS total FROM ventas WHERE id = ?', [$ventaId]);
        if ((int)($existe[0]['total'] ?? 0) === 0) {
            self::responder(0, 'Venta no encontrada');
        }

        self::cerrarConcepto($ventaId, $concepto);
        self::responder(1, 'Costo saldado');
    }

    private static function cerrarConcepto(int $ventaId, string $concepto): void
    {
        $stmt = ActiveRecord::getDB()->prepare('INSERT IGNORE INTO cierres_costo (venta_id, concepto) VALUES (?, ?)');
        $stmt->execute([$ventaId, $concepto]);
    }
}
