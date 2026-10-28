<?php

namespace Model;

class Gasto extends ModeloBase
{
    protected static $tabla = 'gastos';
    protected static $idTabla = 'id';
    protected static $columnasDB = ['id', 'fecha', 'categoria_id', 'venta_id', 'pintor_id', 'descripcion', 'monto', 'comprobante'];

    public $id;
    public $fecha;
    public $categoria_id;
    public $venta_id;
    public $pintor_id;
    public $descripcion;
    public $monto;
    public $comprobante;
    public $creado_en;

    public function __construct($args = [])
    {
        $this->id = $args['id'] ?? null;
        $this->fecha = $args['fecha'] ?? date('Y-m-d');
        $this->categoria_id = $args['categoria_id'] ?? null;
        $this->venta_id = $args['venta_id'] ?? null;
        $this->pintor_id = $args['pintor_id'] ?? null;
        $this->descripcion = $args['descripcion'] ?? '';
        $this->monto = $args['monto'] ?? 0;
        $this->comprobante = $args['comprobante'] ?? null;
        $this->creado_en = $args['creado_en'] ?? null;
    }

    public function validar()
    {
        self::$alertas = [];
        if (!self::esFecha($this->fecha)) {
            self::$alertas['error'][] = 'La fecha del gasto no es válida';
        }
        if (!$this->categoria_id) {
            self::$alertas['error'][] = 'Falta la categoría';
        }
        if (!$this->descripcion) {
            self::$alertas['error'][] = 'La descripción es obligatoria';
        }
        if (!is_numeric($this->monto) || $this->monto <= 0) {
            self::$alertas['error'][] = 'El monto debe ser mayor a 0';
        }
        return self::$alertas;
    }
}
