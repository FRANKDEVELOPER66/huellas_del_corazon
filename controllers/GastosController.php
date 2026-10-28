<?php

namespace Controllers;

use MVC\Router;
use Model\ActiveRecord;
use Model\Gasto;
use Model\CategoriaGasto;
use Model\Reporte;

class GastosController
{
    use RespuestaApi, Comprobantes;

    public static function index(Router $router): void
    {
        $router->render('gastos/index', []);
    }

    public static function listarAPI(Router $router): void
    {
        getHeadersApi();

        $mes = self::mesValido($_GET['mes'] ?? '');

        $datos = ActiveRecord::fetchArray(
            "SELECT g.*, cg.nombre AS categoria
             FROM gastos g
             JOIN categorias_gasto cg ON cg.id = g.categoria_id
             WHERE (? = '' OR DATE_FORMAT(g.fecha, '%Y-%m') = ?)
             ORDER BY g.fecha DESC, g.id DESC",
            [$mes, $mes]
        );
        self::responder(1, '', ['datos' => $datos]);
    }

    public static function crearAPI(Router $router): void
    {
        getHeadersApi();

        $gasto = new Gasto([
            'fecha'          => trim($_POST['fecha'] ?? date('Y-m-d')),
            'categoria_id'   => (int)($_POST['categoria_id'] ?? 0),
            'venta_id'       => ((int)($_POST['venta_id'] ?? 0)) ?: null,
            'pintor_id'      => ((int)($_POST['pintor_id'] ?? 0)) ?: null,
            'descripcion'    => trim($_POST['descripcion'] ?? ''),
            'monto'          => (float)($_POST['monto'] ?? 0),
        ]);

        self::errores($gasto->validar());

        // Comprobante de la transferencia (opcional): imagen o PDF
        $gasto->comprobante = self::guardarComprobante();

        $resultado = $gasto->crear();
        if ($resultado['resultado'] === false) {
            self::borrarComprobante($gasto->comprobante);
        }

        self::responder(
            $resultado['resultado'] !== false ? 1 : 0,
            $resultado['resultado'] !== false ? 'Gasto registrado correctamente' : 'Error al registrar',
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

        $gasto = Gasto::find($id);
        if (!$gasto) {
            self::responder(0, 'Gasto no encontrado');
        }

        // Solo cambia los campos que llegan en el POST (permite dejar un campo opcional en blanco)
        if (array_key_exists('fecha', $_POST)) {
            $gasto->fecha = trim($_POST['fecha'] ?? date('Y-m-d'));
        }
        if (array_key_exists('categoria_id', $_POST)) {
            $gasto->categoria_id = (int)($_POST['categoria_id'] ?? 0);
        }
        if (array_key_exists('venta_id', $_POST)) {
            $gasto->venta_id = ((int)($_POST['venta_id'] ?? 0)) ?: null;
        }
        if (array_key_exists('pintor_id', $_POST)) {
            $gasto->pintor_id = ((int)($_POST['pintor_id'] ?? 0)) ?: null;
        }
        if (array_key_exists('descripcion', $_POST)) {
            $gasto->descripcion = trim($_POST['descripcion'] ?? '');
        }
        if (array_key_exists('monto', $_POST)) {
            $gasto->monto = (float)($_POST['monto'] ?? 0);
        }

        self::errores($gasto->validar());

        // Si sube un comprobante nuevo, reemplaza al anterior
        $anterior = $gasto->comprobante;
        $nuevo = self::guardarComprobante();
        if ($nuevo) {
            $gasto->comprobante = $nuevo;
        }

        // exec() devuelve 0 si no cambió nada: eso no es un error
        $resultado = $gasto->actualizar();
        if ($resultado['resultado'] === false) {
            self::borrarComprobante($nuevo);
            self::responder(0, 'Error al actualizar');
        }
        if ($nuevo) {
            self::borrarComprobante($anterior);
        }
        self::responder(1, 'Gasto actualizado');
    }

    public static function eliminarAPI(Router $router): void
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

        $gasto->eliminar();
        self::borrarComprobante($gasto->comprobante);
        self::responder(1, 'Gasto eliminado correctamente');
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
        self::responder(1, '', ['datos' => Reporte::pintora()]);
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
}
