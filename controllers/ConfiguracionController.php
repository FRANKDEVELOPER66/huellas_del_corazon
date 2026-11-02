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
        self::responder(1, '', ['datos' => ['meta' => Reporte::reservaMeta(), 'multiplo' => Reporte::capitalMultiplo(), 'alerta_g' => \Model\Inventario::alertaGramos(), 'caja' => Reporte::caja()]]);
    }

    public static function guardarReservaAPI(Router $router): void
    {
        getHeadersApi();

        if (!isset($_POST['meta']) || !is_numeric($_POST['meta']) || (float) $_POST['meta'] < 0 || (float) $_POST['meta'] > 1000000) {
            self::responder(0, 'Escribe la meta en quetzales (0 o más)');
        }
        $meta = round((float) $_POST['meta'], 2);

        $db = ActiveRecord::getDB();
        $guardar = $db->prepare("INSERT INTO ajustes (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)");
        $guardar->execute(['reserva_repuestos', (string) $meta]);

        // Cuántas veces tu inversión debe valer el negocio para poder devolver capital (opcional)
        if (isset($_POST['multiplo']) && $_POST['multiplo'] !== '') {
            if (!is_numeric($_POST['multiplo']) || (float) $_POST['multiplo'] < 1 || (float) $_POST['multiplo'] > 100) {
                self::responder(0, 'El múltiplo debe ser 1 o más (por ejemplo 3)');
            }
            $guardar->execute(['capital_multiplo', (string) round((float) $_POST['multiplo'], 2)]);
        }

        // Avisar cuando a un color le queden menos de estos gramos (opcional)
        if (isset($_POST['alerta_g']) && $_POST['alerta_g'] !== '') {
            if (!is_numeric($_POST['alerta_g']) || (float) $_POST['alerta_g'] < 0 || (float) $_POST['alerta_g'] > 5000) {
                self::responder(0, 'El aviso de poco filamento va en gramos (entre 0 y 5,000)');
            }
            $guardar->execute(['alerta_carrete_g', (string) round((float) $_POST['alerta_g'], 0)]);
        }

        self::responder(1, 'Guardado: reserva de Q' . number_format($meta, 2));
    }
}
