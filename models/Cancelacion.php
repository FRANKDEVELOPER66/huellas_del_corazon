<?php

namespace Model;

// Pedidos cancelados por el cliente. Lo cobrado se reparte en: devuelto (se regresa) y retenido (trabajo perdido).
// Política por defecto: se devuelve el 50% de lo cobrado y se retiene el otro 50%.
class Cancelacion
{
    public const DEVOLUCION_PCT = 50;

    public static function hayTabla(): bool
    {
        static $hay = null;
        if ($hay === null) {
            $hay = count(ActiveRecord::fetchArray("SHOW TABLES LIKE 'cancelaciones'")) > 0;
        }
        return $hay;
    }

    // JOIN a cancelaciones con alias cn (necesita el alias p de pedidos). Sin tabla: nunca une nada.
    public static function join(): string
    {
        return self::hayTabla()
            ? 'LEFT JOIN cancelaciones cn ON cn.pedido_id = p.id'
            : 'LEFT JOIN (SELECT 0 AS id, 0 AS pedido_id, 0 AS retenido, 0 AS devuelto, 0 AS costo_material, NULL AS fecha, NULL AS etapa, NULL AS motivo FROM DUAL WHERE 1 = 0) cn ON cn.pedido_id = p.id';
    }
}
