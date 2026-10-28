<?php

namespace Controllers;

// Respuesta JSON con el formato de tus APIs: codigo (1 ok / 0 error), mensaje y datos extra.
// Termina la ejecución, igual que el echo + exit de tus controladores.
trait RespuestaApi
{
    protected static function responder(int $codigo, string $mensaje = '', array $extra = []): void
    {
        echo json_encode(array_merge(['codigo' => $codigo, 'mensaje' => $mensaje], $extra), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Recibe lo que devuelve validar(); si hay errores responde y termina
    protected static function errores(array $alertas): void
    {
        if (!empty($alertas['error'])) {
            self::responder(0, implode(', ', $alertas['error']));
        }
    }

    // 'YYYY-MM' válido o '' (para filtros por mes)
    protected static function mesValido($mes): string
    {
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $mes) ? (string) $mes : '';
    }
}
