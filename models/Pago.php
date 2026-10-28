<?php

namespace Model;

class Pago extends ModeloBase
{
    protected static $tabla = 'pagos';
    protected static $idTabla = 'id';
    protected static $columnasDB = ['id', 'venta_id', 'monto', 'tipo', 'fecha', 'notas', 'comprobante'];

    public const TIPOS = ['anticipo', 'saldo', 'otro'];

    public $id;
    public $venta_id;
    public $monto;
    public $tipo;
    public $fecha;
    public $notas;
    public $comprobante;

    public function __construct($args = [])
    {
        $this->id = $args['id'] ?? null;
        $this->venta_id = $args['venta_id'] ?? null;
        $this->monto = $args['monto'] ?? 0;
        $this->tipo = $args['tipo'] ?? 'anticipo';
        $this->fecha = $args['fecha'] ?? date('Y-m-d');
        $this->notas = $args['notas'] ?? null;
        $this->comprobante = $args['comprobante'] ?? null;
    }

    public function validar()
    {
        self::$alertas = [];
        if (!$this->venta_id) {
            self::$alertas['error'][] = 'Falta la venta';
        }
        if (!is_numeric($this->monto) || $this->monto <= 0) {
            self::$alertas['error'][] = 'El monto debe ser mayor a 0';
        }
        if (!in_array($this->tipo, self::TIPOS, true)) {
            self::$alertas['error'][] = 'El tipo de pago no es válido';
        }
        if (!self::esFecha($this->fecha)) {
            self::$alertas['error'][] = 'La fecha del pago no es válida';
        }
        return self::$alertas;
    }
}
