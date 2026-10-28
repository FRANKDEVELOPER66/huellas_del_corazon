<?php

namespace Controllers;

use MVC\Router;
use Model\ActiveRecord;
use Model\Cliente;

class ClientesController
{
    use RespuestaApi;

    public static function index(Router $router): void
    {
        $router->render('clientes/index', []);
    }

    public static function listarAPI(Router $router): void
    {
        getHeadersApi();

        $q = trim($_GET['q'] ?? '');
        $datos = $q !== '' ? Cliente::buscar($q) : Cliente::all();
        self::responder(1, '', ['datos' => $datos]);
    }

    public static function crearAPI(Router $router): void
    {
        getHeadersApi();

        $cliente = new Cliente([
            'nombre'     => trim($_POST['nombre'] ?? ''),
            'telefono'   => trim($_POST['telefono'] ?? '') ?: null,
            'notas'      => trim($_POST['notas'] ?? '') ?: null,
        ]);

        self::errores($cliente->validar());

        $resultado = $cliente->crear();

        self::responder(
            $resultado['resultado'] !== false ? 1 : 0,
            $resultado['resultado'] !== false ? 'Cliente registrado correctamente' : 'Error al registrar',
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

        $cliente = Cliente::find($id);
        if (!$cliente) {
            self::responder(0, 'Cliente no encontrado');
        }

        // Solo cambia los campos que llegan en el POST (permite dejar un campo opcional en blanco)
        if (array_key_exists('nombre', $_POST)) {
            $cliente->nombre = trim($_POST['nombre'] ?? '');
        }
        if (array_key_exists('telefono', $_POST)) {
            $cliente->telefono = trim($_POST['telefono'] ?? '') ?: null;
        }
        if (array_key_exists('notas', $_POST)) {
            $cliente->notas = trim($_POST['notas'] ?? '') ?: null;
        }

        self::errores($cliente->validar());

        // exec() devuelve 0 si no cambió nada: eso no es un error
        $resultado = $cliente->actualizar();
        self::responder($resultado['resultado'] !== false ? 1 : 0, $resultado['resultado'] !== false ? 'Cliente actualizado' : 'Error al actualizar');
    }

    public static function eliminarAPI(Router $router): void
    {
        getHeadersApi();

        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            self::responder(0, 'ID inválido');
        }

        $uso = ActiveRecord::fetchArray(
            'SELECT (SELECT COUNT(*) FROM ventas WHERE cliente_id = ?) + (SELECT COUNT(*) FROM pedidos WHERE cliente_id = ?) AS total',
            [$id, $id]
        );
        if ((int)($uso[0]['total'] ?? 0) > 0) {
            self::responder(0, 'No se puede eliminar, el cliente tiene pedidos o ventas registrados');
        }

        $cliente = Cliente::find($id);
        if (!$cliente) {
            self::responder(0, 'Cliente no encontrado');
        }

        $cliente->eliminar();
        self::responder(1, 'Cliente eliminado correctamente');
    }
}
