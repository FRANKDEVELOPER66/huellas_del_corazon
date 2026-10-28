<?php

namespace Controllers;

use MVC\Router;
use Model\ActiveRecord;
use Model\Material;

class MaterialesController
{
    use RespuestaApi;

    public static function index(Router $router): void
    {
        $router->render('materiales/index', []);
    }

    public static function listarAPI(Router $router): void
    {
        getHeadersApi();

        $datos = !empty($_GET['activos']) ? Material::activos() : Material::all();
        self::responder(1, '', ['datos' => $datos]);
    }

    public static function crearAPI(Router $router): void
    {
        getHeadersApi();

        $material = new Material([
            'nombre'     => trim($_POST['nombre'] ?? ''),
            'costo_kg'   => (float)($_POST['costo_kg'] ?? 0),
            'activo'     => (int)!empty($_POST['activo'] ?? 1),
        ]);

        self::errores($material->validar());

        $resultado = $material->crear();

        self::responder(
            $resultado['resultado'] !== false ? 1 : 0,
            $resultado['resultado'] !== false ? 'Material registrado correctamente' : 'Error al registrar',
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

        $material = Material::find($id);
        if (!$material) {
            self::responder(0, 'Material no encontrado');
        }

        // Solo cambia los campos que llegan en el POST (permite dejar un campo opcional en blanco)
        if (array_key_exists('nombre', $_POST)) {
            $material->nombre = trim($_POST['nombre'] ?? '');
        }
        if (array_key_exists('costo_kg', $_POST)) {
            $material->costo_kg = (float)($_POST['costo_kg'] ?? 0);
        }
        if (array_key_exists('activo', $_POST)) {
            $material->activo = (int)!empty($_POST['activo'] ?? 1);
        }

        self::errores($material->validar());

        // exec() devuelve 0 si no cambió nada: eso no es un error
        $resultado = $material->actualizar();
        self::responder($resultado['resultado'] !== false ? 1 : 0, $resultado['resultado'] !== false ? 'Material actualizado' : 'Error al actualizar');
    }

    public static function eliminarAPI(Router $router): void
    {
        getHeadersApi();

        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            self::responder(0, 'ID inválido');
        }

        $uso = ActiveRecord::fetchArray('SELECT COUNT(*) AS total FROM cotizacion_partes WHERE material_id = ?', [$id]);
        if ((int)($uso[0]['total'] ?? 0) > 0) {
            self::responder(0, 'No se puede eliminar, el material se usó en cotizaciones. Desactívalo en su lugar');
        }

        $material = Material::find($id);
        if (!$material) {
            self::responder(0, 'Material no encontrado');
        }

        $material->eliminar();
        self::responder(1, 'Material eliminado correctamente');
    }
}
