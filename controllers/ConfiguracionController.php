<?php

namespace Controllers;

use MVC\Router;
use Model\Configuracion;
use Model\Reporte;
use Model\ActiveRecord;

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

    // Meta de la reserva para repuestos y equipo
    public static function reservaAPI(Router $router): void
    {
        getHeadersApi();
        self::responder(1, '', ['datos' => ['meta' => Reporte::reservaMeta(), 'caja' => Reporte::caja()]]);
    }

    public static function guardarReservaAPI(Router $router): void
    {
        getHeadersApi();

        if (!isset($_POST['meta']) || !is_numeric($_POST['meta']) || (float) $_POST['meta'] < 0 || (float) $_POST['meta'] > 1000000) {
            self::responder(0, 'Escribe la meta en quetzales (0 o más)');
        }
        $meta = round((float) $_POST['meta'], 2);

        ActiveRecord::getDB()
            ->prepare("INSERT INTO ajustes (clave, valor) VALUES ('reserva_repuestos', ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)")
            ->execute([(string) $meta]);

        self::responder(1, 'Meta de reserva guardada: Q' . number_format($meta, 2));
    }
}
