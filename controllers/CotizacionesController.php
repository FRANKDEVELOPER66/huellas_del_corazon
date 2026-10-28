<?php

namespace Controllers;

use MVC\Router;
use Model\ActiveRecord;
use Model\Calculadora;
use Model\Configuracion;
use Model\Cotizacion;
use Model\Empaque;
use Model\CotizacionParte;
use Model\Material;
use Model\Pintor;

class CotizacionesController
{
    use RespuestaApi;

    public static function index(Router $router): void
    {
        $router->render('cotizaciones/index', []);
    }

    public static function listarAPI(Router $router): void
    {
        getHeadersApi();

        $datos = ActiveRecord::fetchArray(
            'SELECT q.id, q.nombre_pieza, q.alto_cm, q.costo_total, q.precio_sugerido, q.creada_en,
                    c.nombre AS cliente, p.nombre AS pintor,
                    (SELECT COUNT(*) FROM ventas v WHERE v.cotizacion_id = q.id) AS ventas,
                    ROUND(q.precio_sugerido / (1 + COALESCE(cfg.impuesto_pct, 0) / 100) - q.costo_total, 2) AS ganancia
             FROM cotizaciones q
             LEFT JOIN clientes c ON c.id = q.cliente_id
             LEFT JOIN pintores p ON p.id = q.pintor_id
             LEFT JOIN configuraciones cfg ON cfg.id = q.configuracion_id
             ORDER BY q.id DESC
             LIMIT 200'
        );
        self::responder(1, '', ['datos' => $datos]);
    }

    // Vista previa en vivo: calcula sin guardar nada.
    // POST: partes (JSON: [{nombre, material_id, gramos, minutos}]), alto_cm, pintor_id,
    //       costo_pintura (vacío = tarifa del pintor), costo_envio, otros_insumos, margen_pct (opcional),
    //       empaque_id (vacío = estándar de la configuración) o empaque_costo (monto libre)
    public static function calcularAPI(Router $router): void
    {
        getHeadersApi();

        [$cfg, $partes, $r] = self::calcularDesdePost();

        $alternativas = [];
        foreach ([30, 40, 50] as $m) {
            $alternativas[$m] = Calculadora::calcular($cfg, $partes, $r['costo_pintura'], $r['costo_envio'], $r['otros_insumos'], $m, $r['costo_empaque'])['precio_sugerido'];
        }

        self::responder(1, '', ['datos' => $r, 'alternativas' => $alternativas]);
    }

    public static function crearAPI(Router $router): void
    {
        getHeadersApi();

        [$cfg, $partes, $r, $pintorId, $alto] = self::calcularDesdePost();

        $cotizacion = new Cotizacion(array_merge($r, [
            'cliente_id'       => (int)($_POST['cliente_id'] ?? 0) ?: null,
            'configuracion_id' => (int)$cfg->id,
            'pintor_id'        => $pintorId,
            'nombre_pieza'     => trim($_POST['nombre_pieza'] ?? ''),
            'alto_cm'          => $alto
        ]));

        self::errores($cotizacion->validar());

        $db = ActiveRecord::getDB();
        $db->beginTransaction();

        try {
            $resultado = $cotizacion->crear();
            $cotizacionId = (int)$resultado['id'];

            foreach ($partes as $p) {
                $parte = new CotizacionParte([
                    'cotizacion_id' => $cotizacionId,
                    'material_id'   => $p['material_id'],
                    'nombre'        => $p['nombre'],
                    'gramos'        => $p['gramos'],
                    'minutos'       => $p['minutos']
                ]);

                $alertas = $parte->validar();
                if (!empty($alertas['error'])) {
                    throw new \InvalidArgumentException(implode(', ', $alertas['error']));
                }
                $parte->crear();
            }

            $db->commit();
        } catch (\InvalidArgumentException $e) {
            $db->rollBack();
            self::responder(0, $e->getMessage());
        } catch (\Throwable $e) {
            $db->rollBack();
            self::responder(0, 'No se pudo guardar la cotización');
        }

        self::responder(1, 'Cotización guardada', ['id' => $cotizacionId, 'datos' => $r]);
    }

    public static function detalleAPI(Router $router): void
    {
        getHeadersApi();

        $id = (int)($_GET['id'] ?? 0);
        $cotizacion = $id ? Cotizacion::find($id) : null;
        if (!$cotizacion) {
            self::responder(0, 'Cotización no encontrada');
        }

        $partes = ActiveRecord::fetchArray(
            'SELECT cp.*, m.nombre AS material
             FROM cotizacion_partes cp
             JOIN materiales m ON m.id = cp.material_id
             WHERE cp.cotizacion_id = ?
             ORDER BY cp.id',
            [$id]
        );

        $cfg = Configuracion::find((int)$cotizacion->configuracion_id);

        self::responder(1, '', ['datos' => [
            'cotizacion'   => $cotizacion,
            'partes'       => $partes,
            'impuesto_pct' => $cfg->impuesto_pct ?? 0
        ]]);
    }

    public static function eliminarAPI(Router $router): void
    {
        getHeadersApi();

        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            self::responder(0, 'ID inválido');
        }

        // La venta usa los costos de la cotización para el cuadro estimado vs real
        $uso = ActiveRecord::fetchArray('SELECT COUNT(*) AS total FROM ventas WHERE cotizacion_id = ?', [$id]);
        if ((int)($uso[0]['total'] ?? 0) > 0) {
            self::responder(0, 'No se puede eliminar, la cotización ya tiene una venta registrada');
        }

        $cotizacion = Cotizacion::find($id);
        if (!$cotizacion) {
            self::responder(0, 'Cotización no encontrada');
        }

        $cotizacion->eliminar();
        self::responder(1, 'Cotización eliminada correctamente');
    }

    // Lee y valida el POST y calcula con la configuración vigente.
    // Devuelve [config, partes, resultado, pintor_id, alto_cm] o responde con el error.
    private static function calcularDesdePost(): array
    {
        $cfg = Configuracion::vigente();
        if (!$cfg) {
            self::responder(0, 'No hay configuración de costos vigente');
        }

        $partesPost = json_decode($_POST['partes'] ?? '[]', true);
        if (!is_array($partesPost) || !$partesPost) {
            self::responder(0, 'Agrega al menos una parte de la pieza');
        }

        $partes = [];
        foreach ($partesPost as $i => $p) {
            $n = $i + 1;
            $material = Material::find((int)($p['material_id'] ?? 0));
            if (!$material) {
                self::responder(0, "Material inválido en la parte {$n}");
            }

            $gramos = (float)($p['gramos'] ?? 0);
            $minutos = (int)round((float)($p['minutos'] ?? 0));
            if ($gramos <= 0 || $minutos < 0) {
                self::responder(0, "Revisa los gramos y minutos de la parte {$n}");
            }

            $partes[] = [
                'nombre'      => trim($p['nombre'] ?? '') ?: "Parte {$n}",
                'material_id' => (int)$material->id,
                'costo_kg'    => (float)$material->costo_kg,
                'gramos'      => $gramos,
                'minutos'     => $minutos
            ];
        }

        $alto = (float)($_POST['alto_cm'] ?? 0) ?: null;
        $pintorId = (int)($_POST['pintor_id'] ?? 0) ?: null;

        // Si no escribió lo que cobra la pintora, se toma la tarifa del pintor según el alto
        $costoPintura = $_POST['costo_pintura'] ?? '';
        if ($costoPintura === '' && $pintorId && $alto) {
            $pintor = Pintor::find($pintorId);
            $costoPintura = $pintor ? ($pintor->tarifaPara($alto) ?? 0) : 0;
        }
        $costoPintura = (float)$costoPintura;
        // Envío en dos tramos: Cobán → pintora y pintora → cliente. El costo de envío es la suma.
        // (Si solo llega costo_envio, como antes, se usa tal cual y no se separa en tramos.)
        $envioPintora = trim((string)($_POST['envio_pintora'] ?? ''));
        $envioCliente = trim((string)($_POST['envio_cliente'] ?? ''));
        $conTramos = $envioPintora !== '' || $envioCliente !== '';
        $envioPintora = $conTramos ? (float)$envioPintora : null;
        $envioCliente = $conTramos ? (float)$envioCliente : null;
        $costoEnvio = $conTramos ? $envioPintora + $envioCliente : (float)($_POST['costo_envio'] ?? 0);
        $otros = (float)($_POST['otros_insumos'] ?? 0);
        if (min($costoPintura, $costoEnvio, $otros, $envioPintora ?? 0, $envioCliente ?? 0) < 0) {
            self::responder(0, 'Los costos no pueden ser negativos');
        }

        $margen = ($_POST['margen_pct'] ?? '') !== '' ? (float)$_POST['margen_pct'] : null;
        if ($margen !== null && ($margen < 0 || $margen >= 100)) {
            self::responder(0, 'El margen debe estar entre 0 y 99.99');
        }

        // Empaque: del catálogo, un monto libre, o (vacío) el estándar de la configuración
        $empaqueFijo = null;
        $empaqueNombre = 'Estándar';
        $empaqueId = (int)($_POST['empaque_id'] ?? 0);
        if ($empaqueId) {
            $empaque = Empaque::find($empaqueId);
            if (!$empaque) {
                self::responder(0, 'Empaque inválido');
            }
            $empaqueFijo = (float)$empaque->costo;
            $empaqueNombre = $empaque->nombre;
        } elseif (($_POST['empaque_costo'] ?? '') !== '') {
            $empaqueFijo = (float)$_POST['empaque_costo'];
            if ($empaqueFijo < 0) {
                self::responder(0, 'El empaque no puede ser negativo');
            }
            $empaqueNombre = 'Otro monto';
        }

        $resultado = Calculadora::calcular($cfg, $partes, $costoPintura, $costoEnvio, $otros, $margen, $empaqueFijo);
        $resultado['empaque_nombre'] = $empaqueNombre;
        $resultado['envio_pintora'] = $envioPintora;
        $resultado['envio_cliente'] = $envioCliente;

        return [$cfg, $partes, $resultado, $pintorId, $alto];
    }
}
