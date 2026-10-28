<?php

namespace Controllers;

use MVC\Router;
use Model\ActiveRecord;
use Model\Aporte;
use Model\Reporte;

class CajaController
{
    use RespuestaApi;

    // Caja de hoy y los últimos aportes y retiros
    public static function resumenAPI(Router $router): void
    {
        getHeadersApi();

        self::responder(1, '', ['datos' => [
            'caja'        => Reporte::caja(),
            'movimientos' => ActiveRecord::fetchArray('SELECT * FROM aportes ORDER BY fecha DESC, id DESC LIMIT 20')
        ]]);
    }

    // Registrar dinero que PONES en el negocio (aporte) o que SACAS (retiro)
    public static function crearAPI(Router $router): void
    {
        getHeadersApi();

        $aporte = new Aporte([
            'fecha'       => trim($_POST['fecha'] ?? date('Y-m-d')),
            'tipo'        => trim($_POST['tipo'] ?? 'aporte'),
            'monto'       => (float)($_POST['monto'] ?? 0),
            'descripcion' => trim($_POST['descripcion'] ?? '')
        ]);

        self::errores($aporte->validar());

        // No se puede sacar más de lo que está disponible (la caja no puede quedar corta para pagar lo pendiente)
        if ($aporte->tipo === 'retiro') {
            $disponible = Reporte::caja()['disponible'];
            if ($aporte->monto > $disponible + 0.005) {
                self::responder(0, 'No puedes retirar más de lo disponible (Q' . number_format($disponible, 2) . ')');
            }
        }

        $resultado = $aporte->crear();
        self::responder(
            $resultado['resultado'] !== false ? 1 : 0,
            $resultado['resultado'] !== false ? ($aporte->tipo === 'aporte' ? 'Aporte registrado' : 'Retiro registrado') : 'Error al registrar',
            ['id' => $resultado['id'] ?? null]
        );
    }

    public static function eliminarAPI(Router $router): void
    {
        getHeadersApi();

        $aporte = Aporte::find((int)($_POST['id'] ?? 0));
        if (!$aporte) {
            self::responder(0, 'Movimiento no encontrado');
        }

        $aporte->eliminar();
        self::responder(1, 'Movimiento eliminado');
    }
}
