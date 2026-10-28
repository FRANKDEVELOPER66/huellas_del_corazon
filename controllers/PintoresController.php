<?php

namespace Controllers;

use MVC\Router;
use Model\ActiveRecord;
use Model\Pintor;
use Model\PintorTarifa;

class PintoresController
{
    use RespuestaApi;

    public static function index(Router $router): void
    {
        $router->render('pintores/index', []);
    }

    public static function listarAPI(Router $router): void
    {
        getHeadersApi();

        $pintores = !empty($_GET['activos']) ? Pintor::activos() : Pintor::all();

        $tarifas = ActiveRecord::fetchArray('SELECT * FROM pintor_tarifas ORDER BY pintor_id, alto_min_cm');
        $porPintor = [];
        foreach ($tarifas as $t) {
            $porPintor[$t['pintor_id']][] = $t;
        }

        $datos = [];
        foreach ($pintores as $p) {
            $datos[] = array_merge((array) $p, ['tarifas' => $porPintor[$p->id] ?? []]);
        }
        self::responder(1, '', ['datos' => $datos]);
    }

    public static function crearAPI(Router $router): void
    {
        getHeadersApi();

        $pintor = new Pintor([
            'nombre'     => trim($_POST['nombre'] ?? ''),
            'telefono'   => trim($_POST['telefono'] ?? '') ?: null,
            'activo'     => (int)!empty($_POST['activo'] ?? 1),
        ]);

        self::errores($pintor->validar());

        $resultado = $pintor->crear();

        self::responder(
            $resultado['resultado'] !== false ? 1 : 0,
            $resultado['resultado'] !== false ? 'Pintor registrado correctamente' : 'Error al registrar',
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

        $pintor = Pintor::find($id);
        if (!$pintor) {
            self::responder(0, 'Pintor no encontrado');
        }

        // Solo cambia los campos que llegan en el POST (permite dejar un campo opcional en blanco)
        if (array_key_exists('nombre', $_POST)) {
            $pintor->nombre = trim($_POST['nombre'] ?? '');
        }
        if (array_key_exists('telefono', $_POST)) {
            $pintor->telefono = trim($_POST['telefono'] ?? '') ?: null;
        }
        if (array_key_exists('activo', $_POST)) {
            $pintor->activo = (int)!empty($_POST['activo'] ?? 1);
        }

        self::errores($pintor->validar());

        // exec() devuelve 0 si no cambió nada: eso no es un error
        $resultado = $pintor->actualizar();
        self::responder($resultado['resultado'] !== false ? 1 : 0, $resultado['resultado'] !== false ? 'Pintor actualizado' : 'Error al actualizar');
    }

    public static function eliminarAPI(Router $router): void
    {
        getHeadersApi();

        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            self::responder(0, 'ID inválido');
        }

        $uso = ActiveRecord::fetchArray(
            'SELECT (SELECT COUNT(*) FROM cotizaciones WHERE pintor_id = ?) + (SELECT COUNT(*) FROM gastos WHERE pintor_id = ?) AS total',
            [$id, $id]
        );
        if ((int)($uso[0]['total'] ?? 0) > 0) {
            self::responder(0, 'No se puede eliminar, el pintor tiene cotizaciones o gastos registrados. Desactívalo en su lugar');
        }

        $pintor = Pintor::find($id);
        if (!$pintor) {
            self::responder(0, 'Pintor no encontrado');
        }

        $pintor->eliminar();
        self::responder(1, 'Pintor eliminado correctamente');
    }

    // Crea o edita un rango de tarifa (si llega id edita). Los rangos de un mismo pintor no pueden traslaparse.
    public static function guardarTarifaAPI(Router $router): void
    {
        getHeadersApi();

        $id = (int)($_POST['id'] ?? 0);
        $tarifa = $id ? PintorTarifa::find($id) : new PintorTarifa();
        if (!$tarifa) {
            self::responder(0, 'Tarifa no encontrada');
        }

        $tarifa->pintor_id   = (int)($_POST['pintor_id'] ?? $tarifa->pintor_id);
        $tarifa->alto_min_cm = (float)($_POST['alto_min_cm'] ?? 0);
        $tarifa->alto_max_cm = (float)($_POST['alto_max_cm'] ?? 0);
        $tarifa->precio      = (float)($_POST['precio'] ?? 0);

        self::errores($tarifa->validar());

        $solape = ActiveRecord::fetchArray(
            'SELECT COUNT(*) AS total FROM pintor_tarifas WHERE pintor_id = ? AND id <> ? AND alto_min_cm <= ? AND alto_max_cm >= ?',
            [$tarifa->pintor_id, $tarifa->id ?? 0, $tarifa->alto_max_cm, $tarifa->alto_min_cm]
        );
        if ((int)($solape[0]['total'] ?? 0) > 0) {
            self::responder(0, 'Ese rango de altura se traslapa con otro del mismo pintor');
        }

        $resultado = $tarifa->guardar();
        self::responder(
            $resultado['resultado'] !== false ? 1 : 0,
            $resultado['resultado'] !== false ? 'Tarifa guardada' : 'Error al guardar la tarifa',
            ['id' => $resultado['id'] ?? $tarifa->id]
        );
    }

    public static function eliminarTarifaAPI(Router $router): void
    {
        getHeadersApi();

        $tarifa = PintorTarifa::find((int)($_POST['id'] ?? 0));
        if (!$tarifa) {
            self::responder(0, 'Tarifa no encontrada');
        }

        $tarifa->eliminar();
        self::responder(1, 'Tarifa eliminada');
    }

    // Lo que cobra el pintor por una pieza de cierto alto (para autollenar el costo de pintura al cotizar)
    public static function tarifaAPI(Router $router): void
    {
        getHeadersApi();

        $pintor = Pintor::find((int)($_GET['pintor_id'] ?? 0));
        $alto = (float)($_GET['alto_cm'] ?? 0);
        if (!$pintor || $alto <= 0) {
            self::responder(0, 'Pintor o altura inválidos');
        }

        $precio = $pintor->tarifaPara($alto);
        self::responder($precio !== null ? 1 : 0, $precio !== null ? '' : 'No hay tarifa registrada para esa altura', ['precio' => $precio]);
    }
}
