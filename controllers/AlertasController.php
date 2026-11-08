<?php

namespace Controllers;

use MVC\Router;
use Model\ActiveRecord;
use Model\Inventario;
use Model\Reporte;

// Campana de avisos: se calculan al momento con lo que hay en el sistema.
// No se guardan: un aviso desaparece solo cuando lo que lo causó ya se resolvió (pagaste, cobraste, compraste filamento).
class AlertasController
{
    use RespuestaApi;

    private const ETAPAS = [
        'solicitado' => 'Solicitado', 'imprimiendo' => 'Imprimiendo', 'impreso' => 'Impreso',
        'pintando' => 'En pintura', 'listo' => 'Listo para entregar', 'entregado' => 'Entregado',
    ];

    public static function listarAPI(Router $router): void
    {
        getHeadersApi();

        $alertas = [];

        // 1) Pagos pendientes de las piezas (pintora, envíos, otros insumos)
        $pend = Reporte::pendientesPiezas()['pendientes'] ?? [];
        foreach ($pend as $p) {
            $trabajo = in_array($p['etapa'], ['pintando', 'listo', 'entregado'], true);
            $titulo = match ($p['concepto']) {
                'pintura' => 'Pagar a la pintora' . ($p['pintor'] ? ' ' . $p['pintor'] : ''),
                'envio'   => 'Pagar el envío',
                default   => 'Pagar otros insumos',
            };
            $alertas[] = [
                'tipo'    => 'pago',
                'nivel'   => $trabajo ? 'alta' : 'media',
                'icono'   => $p['concepto'] === 'pintura' ? 'bi-palette' : ($p['concepto'] === 'envio' ? 'bi-truck' : 'bi-bag'),
                'titulo'  => $titulo,
                'detalle' => 'Pieza ' . $p['descripcion'] . (!empty($p['cliente']) ? ' · ' . $p['cliente'] : '') . ($p['etapa'] ? ' · ' . (self::ETAPAS[$p['etapa']] ?? $p['etapa']) : ''),
                'monto'   => (float) $p['pendiente'],
                'url'     => '/gastos#pagos-pendientes',
                'orden'   => $trabajo ? 1 : 3,
            ];
        }

        // 2) Pedidos listos para entregar: cobrar el saldo y finalizar
        $listos = ActiveRecord::fetchArray(
            "SELECT p.nombre_mascota, c.nombre AS cliente, v.precio_venta - COALESCE(pg.pagado, 0) AS saldo
             FROM pedidos p
             JOIN clientes c ON c.id = p.cliente_id
             JOIN ventas v ON v.pedido_id = p.id
             LEFT JOIN (SELECT venta_id, SUM(monto) AS pagado FROM pagos GROUP BY venta_id) pg ON pg.venta_id = v.id
             WHERE p.estado = 'listo'
             ORDER BY p.fecha_pedido, p.id"
        );
        foreach ($listos as $l) {
            $saldo = (float) $l['saldo'];
            $alertas[] = [
                'tipo'    => 'entrega',
                'nivel'   => $saldo > 0.005 ? 'alta' : 'media',
                'icono'   => 'bi-box-seam',
                'titulo'  => $saldo > 0.005 ? 'Cobrar y entregar' : 'Entregar y finalizar la venta',
                'detalle' => $l['nombre_mascota'] . ' · ' . $l['cliente'] . ($saldo > 0.005 ? ' · te debe' : ' · ya está pagado'),
                'monto'   => $saldo > 0.005 ? $saldo : null,
                'url'     => '/pedidos',
                'orden'   => 2,
            ];
        }

        // 3) Filamento por acabarse o agotado (que algún pedido necesita)
        if (Inventario::hayCarretes()) {
            foreach (Inventario::alertasColores() as $a) {
                $alertas[] = [
                    'tipo'    => 'filamento',
                    'nivel'   => $a['agotado'] ? 'alta' : 'media',
                    'icono'   => 'bi-boxes',
                    'titulo'  => $a['agotado'] ? 'Se acabó el ' . $a['color'] : 'Por acabarse: ' . $a['color'],
                    'detalle' => $a['material'] . ($a['agotado'] ? '' : ' · quedan ' . number_format($a['gramos'], 0) . ' g')
                        . ($a['pedidos'] ? ' · lo espera ' . implode(', ', $a['pedidos']) : ''),
                    'monto'   => null,
                    'url'     => '/inventario',
                    'orden'   => $a['agotado'] ? 1 : 4,
                ];
            }
        }

        // 4) Recordatorios de trabajo: los urgentes, los vencidos y los que vencen hoy o mañana
        if (RecordatoriosController::hayTabla()) {
            $tipos = ['material' => 'Comprar material', 'diseno' => 'Diseño / STL', 'cliente' => 'Cliente', 'pintura' => 'Pintura', 'envio' => 'Envío', 'otro' => 'Otro'];
            $recs = ActiveRecord::fetchArray(
                "SELECT r.id, r.texto, r.tipo, r.prioridad, r.fecha_limite, c.nombre AS cliente, q.nombre_pieza AS cotizacion,
                        DATEDIFF(r.fecha_limite, CURDATE()) AS dias_para
                 FROM recordatorios r
                 LEFT JOIN clientes c ON c.id = r.cliente_id
                 LEFT JOIN cotizaciones q ON q.id = r.cotizacion_id
                 WHERE r.estado = 'pendiente'
                   AND (r.prioridad = 'urgente' OR (r.fecha_limite IS NOT NULL AND r.fecha_limite <= DATE_ADD(CURDATE(), INTERVAL 1 DAY)))
                 ORDER BY r.fecha_limite IS NULL, r.fecha_limite, r.id"
            );
            foreach ($recs as $r) {
                $dias = $r['dias_para'] === null ? null : (int) $r['dias_para'];
                $etiqueta = null;
                if ($dias !== null) {
                    $etiqueta = $dias < 0
                        ? ['texto' => 'Venció hace ' . abs($dias) . ($dias === -1 ? ' día' : ' días'), 'clase' => 'vencido']
                        : ($dias === 0 ? ['texto' => 'Vence HOY', 'clase' => 'hoy'] : ['texto' => 'Vence mañana', 'clase' => 'manana']);
                }
                $alertas[] = [
                    'tipo'    => 'recordatorio',
                    'nivel'   => ($r['prioridad'] === 'urgente' || ($dias !== null && $dias <= 0)) ? 'alta' : 'media',
                    'icono'   => 'bi-journal-check',
                    'titulo'  => $r['texto'],
                    'detalle' => ($tipos[$r['tipo']] ?? 'Recordatorio') . ($r['cotizacion'] ? ' · ' . $r['cotizacion'] : '') . ($r['cliente'] ? ' · ' . $r['cliente'] : ''),
                    'etiqueta' => $etiqueta,
                    'monto'   => null,
                    'url'     => '/recordatorios',
                    'orden'   => $dias === null ? 2 : 0,
                    'dias'    => $dias ?? 0,
                ];
            }
        }

        // Pedidos en curso y en qué etapa van (para el botón de pedidos junto a la campana)
        $orden = ['solicitado' => 1, 'imprimiendo' => 2, 'impreso' => 3, 'pintando' => 4, 'listo' => 5];
        $pedidos = [];
        $filas = ActiveRecord::fetchArray(
            "SELECT p.id, p.nombre_mascota, p.estado, p.fecha_pedido, c.nombre AS cliente,
                    DATEDIFF(CURDATE(), p.fecha_pedido) AS dias,
                    v.precio_venta - COALESCE(pg.pagado, 0) AS saldo
             FROM pedidos p
             JOIN clientes c ON c.id = p.cliente_id
             LEFT JOIN ventas v ON v.pedido_id = p.id
             LEFT JOIN (SELECT venta_id, SUM(monto) AS pagado FROM pagos GROUP BY venta_id) pg ON pg.venta_id = v.id
             WHERE p.estado NOT IN ('entregado', 'cancelado')
             ORDER BY p.fecha_pedido, p.id"
        );
        foreach ($filas as $f) {
            $pedidos[] = [
                'id'      => (int) $f['id'],
                'nombre'  => $f['nombre_mascota'],
                'cliente' => $f['cliente'],
                'estado'  => $f['estado'],
                'etapa'   => self::ETAPAS[$f['estado']] ?? $f['estado'],
                'paso'    => $orden[$f['estado']] ?? 1,
                'dias'    => max((int) $f['dias'], 0),
                'saldo'   => max((float) $f['saldo'], 0),
            ];
        }
        usort($pedidos, fn($a, $b) => [-$a['paso'], $a['id']] <=> [-$b['paso'], $b['id']]);

        // Primero lo que tiene fecha (vencidos, hoy, mañana), luego el resto por importancia
        usort($alertas, fn($a, $b) => [$a['orden'], $a['dias'] ?? 0, -($a['monto'] ?? 0)] <=> [$b['orden'], $b['dias'] ?? 0, -($b['monto'] ?? 0)]);

        self::responder(1, '', [
            'datos'  => $alertas,
            'total'  => count($alertas),
            'urgentes' => count(array_filter($alertas, fn($a) => $a['nivel'] === 'alta')),
            'pedidos'  => $pedidos,
        ]);
    }
}
