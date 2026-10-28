<?php

namespace Controllers;

use MVC\Router;
use Model\Reporte;

class DashboardController
{
    use RespuestaApi;

    public static function index(Router $router): void
    {
        $router->render('dashboard/index', []);
    }

    // Estado de resultados y balance del mes (YYYY-MM, por defecto el actual), más el histórico
    public static function resumenAPI(Router $router): void
    {
        getHeadersApi();

        $mes = self::mesValido($_GET['mes'] ?? '') ?: date('Y-m');

        self::responder(1, '', ['datos' => [
            'mes'        => $mes,
            'resultados' => Reporte::estadoResultados($mes),
            'balance'    => Reporte::balanceAl($mes),
            'produccion' => Reporte::enProduccion(),
            'caja'       => Reporte::caja(),
            'por_mes'    => Reporte::resultadosPorMes(),
            'flujo'      => Reporte::flujoCajaMensual()
        ]]);
    }

    // Todos los meses: balance (resultados) y flujo de caja con acumulado
    public static function historicoAPI(Router $router): void
    {
        getHeadersApi();

        self::responder(1, '', ['datos' => [
            'balance' => Reporte::balanceMensual(),
            'flujo'   => Reporte::flujoCajaMensual()
        ]]);
    }
}
