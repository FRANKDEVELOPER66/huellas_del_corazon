<?php

namespace Controllers;

use MVC\Router;
use Model\Cierre;
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
        $porMes = Reporte::resultadosPorMes();
        $cierres = [];
        foreach ($porMes as $m) {
            $cierres[$m['mes']] = Cierre::estadoMes((string) $m['mes']);
        }

        self::responder(1, '', ['datos' => [
            'mes'        => $mes,
            'resultados' => Reporte::estadoResultados($mes),
            'balance'    => Reporte::balanceAl($mes),
            'produccion' => Reporte::enProduccion(),
            'caja'       => Reporte::caja(),
            'por_mes'    => $porMes,
            'cierre'     => Cierre::estadoMes($mes),
            'cierres'    => (object) $cierres,
            'cierre_hasta' => Cierre::ultimaFecha(),
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
