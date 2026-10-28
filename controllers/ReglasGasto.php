<?php

namespace Controllers;

use Model\ActiveRecord;
use Model\Reporte;

// Reglas estrictas que comparten los gastos y las compras de filamento.
// Requiere que la clase use también el trait RespuestaApi.
trait ReglasGasto
{
    // Desde este monto (Q) el comprobante es obligatorio
    private static function comprobanteDesde(): float
    {
        return 100.0;
    }

    private static function origenValido($valor, bool $permiteInicial = false): string
    {
        $origen = trim((string) $valor) ?: 'caja';
        $validos = $permiteInicial ? ['caja', 'bolsillo', 'inicial'] : ['caja', 'bolsillo'];
        if (!in_array($origen, $validos, true)) {
            self::responder(0, 'Elige de dónde salió el dinero: de la caja del negocio o de tu bolsillo');
        }
        return $origen;
    }

    private static function fechaNoFutura(string $fecha): void
    {
        $f = \DateTime::createFromFormat('Y-m-d', $fecha);
        if (!$f || $f->format('Y-m-d') !== $fecha) {
            self::responder(0, 'La fecha no es válida');
        }
        if ($fecha > date('Y-m-d')) {
            self::responder(0, 'La fecha no puede ser futura');
        }
    }

    // Si sale de la caja, tiene que alcanzar.
    //  · Compras y gastos generales: contra lo DISPONIBLE (caja menos lo que debes pagar de las piezas)
    //  · Pagos de pintora/envíos (tipo directo): contra la caja, porque eso ya está restado del disponible
    // $yaGastado = lo que este mismo gasto ya sacaba de la caja (al editarlo), que se libera.
    private static function exigirCaja(float $monto, string $tipoCategoria, float $yaGastado = 0.0): void
    {
        $caja = Reporte::caja();
        $base = $tipoCategoria === 'directo' ? (float) $caja['caja'] : (float) $caja['disponible'];
        $libre = round($base + $yaGastado, 2);

        if ($monto > $libre + 0.005) {
            $que = $tipoCategoria === 'directo' ? 'en la caja' : 'disponible para gastar (la caja menos lo que debes pagar de las piezas)';
            self::responder(
                0,
                'No alcanza: vas a sacar Q' . number_format($monto, 2) . ' de la caja y solo hay Q' . number_format(max($libre, 0), 2)
                . ' ' . $que . '. Si lo pagaste con tu dinero, elige “De mi bolsillo”.'
            );
        }
    }

    // El dinero de tu bolsillo entra a la caja como aporte ligado al gasto (para que la caja cuadre).
    // Con origen "caja" borra el aporte ligado, si existía.
    private static function sincronizarAporte(int $gastoId, string $origen, float $monto, string $fecha, string $descripcion): void
    {
        $db = ActiveRecord::getDB();

        if ($origen !== 'bolsillo') {
            $db->prepare('DELETE FROM aportes WHERE gasto_id = ?')->execute([$gastoId]);
            return;
        }

        $texto = mb_substr('Pagado de mi bolsillo: ' . $descripcion, 0, 250);
        $existe = $db->prepare('SELECT id FROM aportes WHERE gasto_id = ?');
        $existe->execute([$gastoId]);

        if ($existe->fetchColumn()) {
            $db->prepare('UPDATE aportes SET fecha = ?, monto = ?, descripcion = ? WHERE gasto_id = ?')
                ->execute([$fecha, $monto, $texto, $gastoId]);
        } else {
            $db->prepare("INSERT INTO aportes (fecha, tipo, monto, descripcion, gasto_id) VALUES (?, 'aporte', ?, ?, ?)")
                ->execute([$fecha, $monto, $texto, $gastoId]);
        }
    }

    // Guarda cómo estaba algo ANTES de editarlo
    private static function guardarHistorial(string $tipo, int $refId, array $anterior): void
    {
        ActiveRecord::getDB()
            ->prepare('INSERT INTO gastos_historial (tipo, ref_id, anterior) VALUES (?, ?, ?)')
            ->execute([$tipo, $refId, json_encode($anterior, JSON_UNESCAPED_UNICODE)]);
    }
}
