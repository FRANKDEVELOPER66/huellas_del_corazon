<?php

namespace Model;

// La fórmula del precio. No toca la base de datos: recibe datos y devuelve números.
//
// costo  = material (con merma) + luz + desgaste de la máquina + empaque + pintora + envíos + otros insumos
// precio = costo / (1 - margen) * (1 + impuesto), redondeado hacia arriba al múltiplo de "redondeo", nunca menor al mínimo
class Calculadora
{
    /**
     * @param Configuracion $cfg    Configuración vigente
     * @param array         $partes Cada una con: gramos, minutos, costo_kg (del material)
     * @param float|null    $margen Margen en % (si es null usa el de la configuración)
     * @param float|null    $empaqueFijo Costo del empaque elegido para esta cotización (si es null usa el estándar de la configuración)
     */
    public static function calcular(
        Configuracion $cfg,
        array $partes,
        float $costoPintura = 0,
        float $costoEnvio = 0,
        float $otros = 0,
        $margen = null,
        $empaqueFijo = null
    ): array {
        $gramos = 0.0;
        $minutos = 0;
        $material = 0.0;

        foreach ($partes as $p) {
            $g = (float) $p['gramos'];
            $gramos += $g;
            $minutos += (int) $p['minutos'];
            $material += $g / 1000 * (float) $p['costo_kg'];
        }

        $material *= 1 + (float) $cfg->merma_pct / 100;
        $horas = $minutos / 60;

        $luz = $horas * (float) $cfg->consumo_watts / 1000 * (float) $cfg->tarifa_kwh;
        $desgaste = $horas * (float) $cfg->desgaste_hora;
        $empaque = $empaqueFijo === null ? (float) $cfg->empaque : max(0.0, (float) $empaqueFijo);

        $costoTotal = $material + $luz + $desgaste + $empaque + $costoPintura + $costoEnvio + $otros;

        $margen = $margen === null ? (float) $cfg->margen_pct : (float) $margen;
        $impuesto = (float) $cfg->impuesto_pct;
        $bruto = $costoTotal / (1 - $margen / 100) * (1 + $impuesto / 100);

        $paso = max(1.0, (float) $cfg->redondeo);
        $precio = max((float) $cfg->precio_minimo, ceil(($bruto - 1e-9) / $paso) * $paso);

        return [
            'total_gramos'     => round($gramos, 2),
            'total_minutos'    => $minutos,
            'costo_material'   => round($material, 2),
            'costo_luz'        => round($luz, 2),
            'costo_desgaste'   => round($desgaste, 2),
            'costo_empaque'    => round($empaque, 2),
            'costo_pintura'    => round($costoPintura, 2),
            'costo_envio'      => round($costoEnvio, 2),
            'otros_insumos'    => round($otros, 2),
            'costo_total'      => round($costoTotal, 2),
            'margen_pct_usado' => round($margen, 2),
            'precio_sugerido'  => round($precio, 2),
            'ganancia'         => round($precio / (1 + $impuesto / 100) - $costoTotal, 2),
        ];
    }
}
