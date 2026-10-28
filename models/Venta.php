<?php

namespace Model;

class Venta extends ModeloBase
{
    protected static $tabla = 'ventas';
    protected static $idTabla = 'id';
    protected static $columnasDB = ['id', 'pedido_id', 'cotizacion_id', 'cliente_id', 'descripcion', 'fecha_venta', 'precio_venta', 'impuesto_pct', 'notas'];

    public $id;
    public $pedido_id;
    public $cotizacion_id;
    public $cliente_id;
    public $descripcion;
    public $fecha_venta;
    public $precio_venta;
    public $impuesto_pct;
    public $notas;
    public $ingreso_neto;
    public $creado_en;

    public function __construct($args = [])
    {
        $this->id = $args['id'] ?? null;
        $this->pedido_id = $args['pedido_id'] ?? null;
        $this->cotizacion_id = $args['cotizacion_id'] ?? null;
        $this->cliente_id = $args['cliente_id'] ?? null;
        $this->descripcion = $args['descripcion'] ?? '';
        $this->fecha_venta = $args['fecha_venta'] ?? date('Y-m-d');
        $this->precio_venta = $args['precio_venta'] ?? 0;
        $this->impuesto_pct = $args['impuesto_pct'] ?? 0;
        $this->notas = $args['notas'] ?? null;
        $this->ingreso_neto = $args['ingreso_neto'] ?? null;
        $this->creado_en = $args['creado_en'] ?? null;
    }

    public function validar()
    {
        self::$alertas = [];
        if (!$this->cliente_id) {
            self::$alertas['error'][] = 'Falta el cliente';
        }
        if (!$this->descripcion) {
            self::$alertas['error'][] = 'La descripción es obligatoria';
        }
        if (!self::esFecha($this->fecha_venta)) {
            self::$alertas['error'][] = 'La fecha de la venta no es válida';
        }
        if (!is_numeric($this->precio_venta) || $this->precio_venta <= 0) {
            self::$alertas['error'][] = 'El precio de venta debe ser mayor a 0';
        }
        if (!is_numeric($this->impuesto_pct) || $this->impuesto_pct < 0) {
            self::$alertas['error'][] = 'El impuesto no puede ser negativo';
        }
        return self::$alertas;
    }

    public function pagos()
    {
        return Pago::where('venta_id', (int) $this->id);
    }

    public function gastos()
    {
        return Gasto::where('venta_id', (int) $this->id);
    }

    public function totalPagado()
    {
        $r = self::fetchArray('SELECT COALESCE(SUM(monto), 0) AS total FROM pagos WHERE venta_id = ?', [$this->id]);
        return (float) $r[0]['total'];
    }

    public function saldo()
    {
        return (float) $this->precio_venta - $this->totalPagado();
    }
}
