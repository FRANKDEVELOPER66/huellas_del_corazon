<?php
require_once __DIR__ . '/../includes/app.php';


use MVC\Router;
use Controllers\AppController;
use Controllers\ClientesController;
use Controllers\ConfiguracionController;
use Controllers\CotizacionesController;
use Controllers\DashboardController;
use Controllers\GastosController;
use Controllers\MaterialesController;
use Controllers\PedidosController;
use Controllers\PintoresController;
use Controllers\VentasController;
use Controllers\CajaController;
use Controllers\EmpaquesController;
use Controllers\InventarioController;
use Controllers\CierresController;
use Controllers\ProformaController;
use Controllers\AlertasController;
use Controllers\RecordatoriosController;

$router = new Router();
$router->setBaseURL('/' . $_ENV['APP_NAME']);

$router->get('/', [AppController::class, 'index']);

// ================= COTIZADOR 3D =================
// ---- Vistas ----
$router->get('/dashboard',      [DashboardController::class, 'index']);
$router->get('/cotizaciones',   [CotizacionesController::class, 'index']);
$router->get('/ventas',         [VentasController::class, 'index']);
$router->get('/pedidos',        [PedidosController::class, 'index']);
$router->get('/gastos',         [GastosController::class, 'index']);
$router->get('/clientes',       [ClientesController::class, 'index']);
$router->get('/pintores',       [PintoresController::class, 'index']);
$router->get('/materiales',     [MaterialesController::class, 'index']);
$router->get('/configuracion',  [ConfiguracionController::class, 'index']);

// ---- API: dashboard ----
$router->get('/API/dashboard/resumen',   [DashboardController::class, 'resumenAPI']);
$router->get('/API/dashboard/historico', [DashboardController::class, 'historicoAPI']);

// ---- API: cotizaciones ----
$router->get('/API/cotizaciones/listar',    [CotizacionesController::class, 'listarAPI']);
$router->get('/API/cotizaciones/detalle',   [CotizacionesController::class, 'detalleAPI']);
$router->post('/API/cotizaciones/calcular', [CotizacionesController::class, 'calcularAPI']);
$router->post('/API/cotizaciones/crear',    [CotizacionesController::class, 'crearAPI']);
$router->post('/API/cotizaciones/eliminar', [CotizacionesController::class, 'eliminarAPI']);
$router->post('/API/cotizaciones/actualizar', [CotizacionesController::class, 'actualizarAPI']);

// ---- API: ventas y pagos ----
$router->get('/API/ventas/listar',          [VentasController::class, 'listarAPI']);
$router->get('/API/ventas/detalle',         [VentasController::class, 'detalleAPI']);
$router->post('/API/ventas/crear',          [VentasController::class, 'crearAPI']);
$router->post('/API/ventas/actualizar',     [VentasController::class, 'actualizarAPI']);
$router->post('/API/ventas/eliminar',       [VentasController::class, 'eliminarAPI']);
$router->post('/API/ventas/pago',           [VentasController::class, 'pagoAPI']);
$router->post('/API/ventas/pago/eliminar',  [VentasController::class, 'eliminarPagoAPI']);
$router->get('/API/ventas/comprobante', [VentasController::class, 'comprobanteAPI']);

// ---- API: pedidos ----
$router->get('/API/pedidos/listar',         [PedidosController::class, 'listarAPI']);
$router->post('/API/pedidos/crear',         [PedidosController::class, 'crearAPI']);
$router->post('/API/pedidos/actualizar',    [PedidosController::class, 'actualizarAPI']);
$router->post('/API/pedidos/estado',        [PedidosController::class, 'estadoAPI']);
$router->post('/API/pedidos/eliminar',      [PedidosController::class, 'eliminarAPI']);
$router->post('/API/pedidos/finalizar', [PedidosController::class, 'finalizarAPI']);
$router->get('/API/pedidos/cancelacion', [PedidosController::class, 'cancelacionAPI']);
$router->post('/API/pedidos/cancelar',   [PedidosController::class, 'cancelarAPI']);
$router->get('/API/pedidos/filamento',    [PedidosController::class, 'filamentoAPI']);
$router->post('/API/pedidos/filamento-guardar',     [PedidosController::class, 'filamentoGuardarAPI']);
$router->post('/API/pedidos/filamento-restablecer', [PedidosController::class, 'filamentoRestablecerAPI']);
$router->get('/API/pedidos/cola',               [PedidosController::class, 'colaAPI']);
$router->post('/API/pedidos/cola-config',       [PedidosController::class, 'colaConfigAPI']);
$router->post('/API/pedidos/inicio-impresion',  [PedidosController::class, 'inicioImpresionAPI']);





// ---- API: gastos ----
$router->get('/API/gastos/listar',          [GastosController::class, 'listarAPI']);
$router->get('/API/gastos/categorias',      [GastosController::class, 'categoriasAPI']);
$router->post('/API/gastos/crear',          [GastosController::class, 'crearAPI']);
$router->post('/API/gastos/actualizar',     [GastosController::class, 'actualizarAPI']);
$router->post('/API/gastos/eliminar',       [GastosController::class, 'eliminarAPI']);

// ---- API: clientes ----
$router->get('/API/clientes/listar',        [ClientesController::class, 'listarAPI']);
$router->post('/API/clientes/crear',        [ClientesController::class, 'crearAPI']);
$router->post('/API/clientes/actualizar',   [ClientesController::class, 'actualizarAPI']);
$router->post('/API/clientes/eliminar',     [ClientesController::class, 'eliminarAPI']);

// ---- API: pintores y tarifas ----
$router->get('/API/pintores/listar',            [PintoresController::class, 'listarAPI']);
$router->get('/API/pintores/tarifa',            [PintoresController::class, 'tarifaAPI']);
$router->post('/API/pintores/crear',            [PintoresController::class, 'crearAPI']);
$router->post('/API/pintores/actualizar',       [PintoresController::class, 'actualizarAPI']);
$router->post('/API/pintores/eliminar',         [PintoresController::class, 'eliminarAPI']);
$router->post('/API/pintores/tarifa/guardar',   [PintoresController::class, 'guardarTarifaAPI']);
$router->post('/API/pintores/tarifa/eliminar',  [PintoresController::class, 'eliminarTarifaAPI']);

// ---- API: materiales ----
$router->get('/API/materiales/listar',      [MaterialesController::class, 'listarAPI']);
$router->post('/API/materiales/crear',      [MaterialesController::class, 'crearAPI']);
$router->post('/API/materiales/actualizar', [MaterialesController::class, 'actualizarAPI']);
$router->post('/API/materiales/eliminar',   [MaterialesController::class, 'eliminarAPI']);



// ---- API: configuración de costos ----
$router->get('/API/configuracion/vigente',  [ConfiguracionController::class, 'vigenteAPI']);
$router->post('/API/configuracion/guardar', [ConfiguracionController::class, 'guardarAPI']);
// ---- API: reserva para repuestos y equipo (usa ConfiguracionController, ya importado) ----
$router->get('/API/configuracion/reserva',   [ConfiguracionController::class, 'reservaAPI']);
$router->post('/API/configuracion/reserva',  [ConfiguracionController::class, 'guardarReservaAPI']);


$router->get('/API/caja/resumen',   [CajaController::class, 'resumenAPI']);
$router->post('/API/caja/crear',    [CajaController::class, 'crearAPI']);
$router->post('/API/caja/eliminar', [CajaController::class, 'eliminarAPI']);


$router->post('/API/ventas/pago/actualizar', [VentasController::class, 'actualizarPagoAPI']);
$router->get('/API/gastos/comprobante',      [GastosController::class, 'comprobanteAPI']);
$router->get('/API/gastos/pintora',          [GastosController::class, 'pintoraAPI']);

$router->post('/API/gastos/cerrar', [GastosController::class, 'cerrarAPI']);

$router->get('/empaques', [EmpaquesController::class, 'index']);
$router->get('/API/empaques/listar',      [EmpaquesController::class, 'listarAPI']);
$router->post('/API/empaques/crear',      [EmpaquesController::class, 'crearAPI']);
$router->post('/API/empaques/actualizar', [EmpaquesController::class, 'actualizarAPI']);
$router->post('/API/empaques/eliminar',   [EmpaquesController::class, 'eliminarAPI']);


$router->get('/API/inventario/resumen',     [InventarioController::class, 'resumenAPI']);
$router->post('/API/inventario/comprar',    [InventarioController::class, 'comprarAPI']);
$router->post('/API/inventario/actualizar', [InventarioController::class, 'actualizarAPI']);
$router->get('/API/gastos/historial',       [GastosController::class, 'historialAPI']);
$router->post('/API/inventario/carrete-peso',  [InventarioController::class, 'carretePesoAPI']);
$router->post('/API/inventario/carrete-color', [InventarioController::class, 'carreteColorAPI']);
$router->get('/inventario',     [InventarioController::class, 'index']);

$router->post('/API/inventario/carrete-peso',  [InventarioController::class, 'carretePesoAPI']);
$router->post('/API/inventario/carrete-color', [InventarioController::class, 'carreteColorAPI']);


// ---- Carretes · paso 2 (InventarioController y PedidosController ya están importados) ----
$router->get('/API/inventario/colores',   [InventarioController::class, 'coloresAPI']);
$router->get('/API/inventario/alertas',   [InventarioController::class, 'alertasAPI']);
$router->post('/API/inventario/devolver-compra', [InventarioController::class, 'devolverCompraAPI']);


$router->post('/API/inventario/prueba', [InventarioController::class, 'pruebaAPI']);
$router->get('/API/pedidos/carretes',     [PedidosController::class, 'carretesAPI']);
$router->post('/API/pedidos/falla',       [PedidosController::class, 'fallaAPI']);



// ---- Cierre de período (reemplaza a "Conciliar con el banco") ----
// Agrega arriba:  use Controllers\CierresController;
$router->get('/API/cierres/preparar',   [CierresController::class, 'prepararAPI']);
$router->post('/API/cierres/cerrar',    [CierresController::class, 'cerrarAPI']);
$router->post('/API/cierres/reabrir',   [CierresController::class, 'reabrirAPI']);
$router->get('/API/cierres/evidencia',  [CierresController::class, 'evidenciaAPI']);
// Agrega arriba:  use Controllers\ProformaController;
$router->get('/cotizaciones/proforma',  [ProformaController::class, 'documento']);   // página para imprimir (no es /API)
$router->get('/API/proforma/datos',     [ProformaController::class, 'datosAPI']);
$router->post('/API/proforma/guardar',  [ProformaController::class, 'guardarAPI']);
//SISTEMA DE ALERTAS
$router->get('/API/alertas', [AlertasController::class, 'listarAPI']);

//Recordatorios
$router->get('/recordatorios',                  [RecordatoriosController::class, 'index']);
$router->get('/API/recordatorios/listar',       [RecordatoriosController::class, 'listarAPI']);
$router->post('/API/recordatorios/crear',       [RecordatoriosController::class, 'crearAPI']);
$router->post('/API/recordatorios/actualizar',  [RecordatoriosController::class, 'actualizarAPI']);
$router->post('/API/recordatorios/hecho',       [RecordatoriosController::class, 'hechoAPI']);
$router->post('/API/recordatorios/eliminar',    [RecordatoriosController::class, 'eliminarAPI']);



$router->comprobarRutas();
