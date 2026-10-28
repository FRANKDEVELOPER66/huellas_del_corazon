<?php

namespace Controllers;

use MVC\Router;
use Model\Empaque;

class EmpaquesController
{
    use RespuestaApi;

    public static function index(Router $router): void
    {
        $router->render('empaques/index', []);
    }

    public static function listarAPI(Router $router): void
    {
        getHeadersApi();

        $datos = !empty($_GET['activos']) ? Empaque::activos() : Empaque::all();
        self::responder(1, '', ['datos' => $datos]);
    }

    public static function crearAPI(Router $router): void
    {
        getHeadersApi();

        $empaque = new Empaque([
            'nombre'     => trim($_POST['nombre'] ?? ''),
            'costo'   => (float)($_POST['costo'] ?? 0),
            'activo'     => (int)!empty($_POST['activo'] ?? 1),
        ]);

        self::errores($empaque->validar());

        $resultado = $empaque->crear();

        self::responder(
            $resultado['resultado'] !== false ? 1 : 0,
            $resultado['resultado'] !== false ? 'Empaque registrado correctamente' : 'Error al registrar',
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

        $empaque = Empaque::find($id);
        if (!$empaque) {
            self::responder(0, 'Empaque no encontrado');
        }

        // Solo cambia los campos que llegan en el POST (permite dejar un campo opcional en blanco)
        if (array_key_exists('nombre', $_POST)) {
            $empaque->nombre = trim($_POST['nombre'] ?? '');
        }
        if (array_key_exists('costo', $_POST)) {
            $empaque->costo = (float)($_POST['costo'] ?? 0);
        }
        if (array_key_exists('activo', $_POST)) {
            $empaque->activo = (int)!empty($_POST['activo'] ?? 1);
        }

        self::errores($empaque->validar());

        // exec() devuelve 0 si no cambió nada: eso no es un error
        $resultado = $empaque->actualizar();
        self::responder($resultado['resultado'] !== false ? 1 : 0, $resultado['resultado'] !== false ? 'Empaque actualizado' : 'Error al actualizar');
    }

    public static function eliminarAPI(Router $router): void
    {
        getHeadersApi();

        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            self::responder(0, 'ID inválido');
        }


        $empaque = Empaque::find($id);
        if (!$empaque) {
            self::responder(0, 'Empaque no encontrado');
        }

        $empaque->eliminar();
        self::responder(1, 'Empaque eliminado correctamente');
    }
}
