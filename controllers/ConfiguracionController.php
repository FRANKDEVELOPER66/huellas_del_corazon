<?php

namespace Controllers;

use MVC\Router;
use Model\Configuracion;

class ConfiguracionController
{
    use RespuestaApi;

    public static function index(Router $router): void
    {
        $router->render('configuracion/index', []);
    }

    public static function vigenteAPI(Router $router): void
    {
        getHeadersApi();

        $cfg = Configuracion::vigente();
        self::responder($cfg ? 1 : 0, $cfg ? '' : 'No hay configuración vigente', ['datos' => $cfg]);
    }

    // No edita la fila actual: guarda una NUEVA versión y la deja vigente.
    // Las cotizaciones y ventas viejas conservan los costos con los que se calcularon.
    public static function guardarAPI(Router $router): void
    {
        getHeadersApi();

        $actual = Configuracion::vigente();
        $campos = ['merma_pct', 'consumo_watts', 'tarifa_kwh', 'desgaste_hora', 'empaque', 'margen_pct', 'impuesto_pct', 'precio_minimo', 'redondeo'];

        // Lo que no llegue en el POST conserva el valor vigente
        $args = [];
        foreach ($campos as $campo) {
            $args[$campo] = $_POST[$campo] ?? $actual?->$campo;
        }

        $nueva = new Configuracion($args);
        self::errores($nueva->validar());

        $resultado = $nueva->activar();
        self::responder(
            $resultado['resultado'] !== false ? 1 : 0,
            $resultado['resultado'] !== false ? 'Configuración guardada. Aplica a las cotizaciones nuevas' : 'Error al guardar la configuración',
            ['id' => $resultado['id'] ?? null]
        );
    }
}
