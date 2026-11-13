<?php

namespace Model;

// Cola de impresión: estima cuándo se libera la impresora.
// Solo cuenta lo que ya está en "Imprimiendo" (restando lo que ya avanzó) y lo "Solicitado" que sigue.
// Las impresiones nuevas únicamente arrancan dentro de tu horario (hora_inicio a hora_limite):
// si una termina a las 2 a. m., la siguiente arranca a las 7 a. m. y esas horas son tiempo muerto.
class Cola
{
    public static function instalada(): bool
    {
        static $hay = null;
        if ($hay === null) {
            $hay = count(ActiveRecord::fetchArray("SHOW TABLES LIKE 'cola_impresion_config'")) > 0
                && count(ActiveRecord::fetchArray("SHOW COLUMNS FROM pedidos LIKE 'imprimiendo_desde'")) > 0;
        }
        return $hay;
    }

    public static function config(): array
    {
        $c = ['hora_inicio' => 7, 'hora_limite' => 22, 'fin_semana' => 1, 'impresoras' => 1];
        if (!self::instalada()) {
            return $c;
        }
        $f = ActiveRecord::fetchArray('SELECT * FROM cola_impresion_config WHERE id = 1');
        if ($f) {
            foreach ($c as $k => $_) {
                $c[$k] = (int)$f[0][$k];
            }
        }
        return $c;
    }

    // Primer momento >= $ts en que se puede arrancar una impresión
    public static function ajustar(int $ts, array $cfg): int
    {
        for ($i = 0; $i < 400; $i++) {
            $dia = strtotime(date('Y-m-d', $ts));
            $ini = $dia + $cfg['hora_inicio'] * 3600;
            $lim = $dia + $cfg['hora_limite'] * 3600;
            $finde = in_array((int)date('N', $ts), [6, 7], true);
            if (!$cfg['fin_semana'] && $finde) {
                $ts = strtotime(date('Y-m-d', $ts) . ' +1 day') + $cfg['hora_inicio'] * 3600;
                continue;
            }
            if ($ts < $ini) {
                return $ini;
            }
            if ($ts > $lim) {
                $ts = strtotime(date('Y-m-d', $ts) . ' +1 day') + $cfg['hora_inicio'] * 3600;
                continue;
            }
            return $ts;
        }
        return $ts;
    }

    public static function simular(?int $ahora = null): array
    {
        $ahora = $ahora ?? time();
        $cfg = self::config();
        $f = fn(int $t) => date('Y-m-d H:i:s', $t);

        $filas = ActiveRecord::fetchArray(
            "SELECT p.id, p.nombre_mascota, p.estado, p.fecha_pedido, p.imprimiendo_desde,
                    COALESCE(qp.total_minutos, q.total_minutos, 0) AS minutos
             FROM pedidos p
             LEFT JOIN ventas v ON v.pedido_id = p.id
             LEFT JOIN cotizaciones q ON q.id = v.cotizacion_id
             LEFT JOIN cotizaciones qp ON qp.id = p.cotizacion_id
             WHERE p.estado IN ('imprimiendo', 'solicitado')
             ORDER BY p.fecha_pedido ASC, p.id ASC"
        );

        $imprimiendo = [];
        $solicitados = [];
        $sinTiempo = [];
        foreach ($filas as $r) {
            if ((float)$r['minutos'] <= 0) {
                $sinTiempo[] = ['id' => (int)$r['id'], 'nombre' => $r['nombre_mascota'], 'estado' => $r['estado']];
                continue;
            }
            if ($r['estado'] === 'imprimiendo') {
                $imprimiendo[] = $r;
            } else {
                $solicitados[] = $r;
            }
        }
        usort($imprimiendo, fn($a, $b) => strcmp((string)$a['imprimiendo_desde'], (string)$b['imprimiendo_desde']));

        $cola = [];
        $carriles = [];
        $totalMin = 0;
        foreach ($imprimiendo as $r) {
            $dur = (int)round((float)$r['minutos'] * 60);
            // Lo que ya estaba imprimiéndose sin hora guardada: se fija la hora de hoy para que el estimado no se mueva solo
            if (empty($r['imprimiendo_desde'])) {
                ActiveRecord::getDB()->prepare('UPDATE pedidos SET imprimiendo_desde = ? WHERE id = ? AND imprimiendo_desde IS NULL')->execute([$f($ahora), (int)$r['id']]);
                $r['imprimiendo_desde'] = $f($ahora);
            }
            $tieneInicio = true;
            $ini = $tieneInicio ? min(strtotime($r['imprimiendo_desde']), $ahora) : $ahora;
            $fin = $ini + $dur;
            $pasado = $fin < $ahora;           // ya debería haber terminado
            $restante = max($fin - $ahora, 0);
            $totalMin += $restante / 60;
            $carriles[] = $pasado ? $ahora : $fin;
            $cola[] = [
                'id' => (int)$r['id'], 'nombre' => $r['nombre_mascota'], 'estado' => 'imprimiendo',
                'minutos' => (float)$r['minutos'], 'inicio' => $f($ini), 'fin' => $f($fin),
                'restante_min' => round($restante / 60), 'espera_min' => 0,
                'sin_inicio' => !$tieneInicio, 'pasado' => $pasado,
            ];
        }
        // Las impresoras que no están ocupadas están libres desde ahora
        while (count($carriles) < $cfg['impresoras']) {
            $carriles[] = $ahora;
        }

        $perdidoMin = 0;
        foreach ($solicitados as $r) {
            $dur = (int)round((float)$r['minutos'] * 60);
            asort($carriles);
            $k = array_key_first($carriles);
            $libre = max($carriles[$k], $ahora);
            $ini = self::ajustar($libre, $cfg);
            $espera = $ini - $libre;
            // La espera de la primera impresión no es "tiempo muerto" si hoy simplemente aún no la pones
            $perdidoMin += $espera / 60;
            $fin = $ini + $dur;
            $carriles[$k] = $fin;
            $totalMin += $dur / 60;
            $cola[] = [
                'id' => (int)$r['id'], 'nombre' => $r['nombre_mascota'], 'estado' => 'solicitado',
                'minutos' => (float)$r['minutos'], 'inicio' => $f($ini), 'fin' => $f($fin),
                'restante_min' => round($dur / 60), 'espera_min' => round($espera / 60),
                'sin_inicio' => false, 'pasado' => false,
            ];
        }

        return [
            'ahora' => $f($ahora),
            'cfg' => $cfg,
            'cola' => $cola,
            'sin_tiempo' => $sinTiempo,
            'total_min' => round($totalMin),
            'perdido_min' => round($perdidoMin),
            'libre_desde' => $cola ? $f(max($carriles)) : $f($ahora),
        ];
    }
}
