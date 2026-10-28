<?php

namespace Model;

class PintorTarifa extends ModeloBase
{
    protected static $tabla = 'pintor_tarifas';
    protected static $idTabla = 'id';
    protected static $columnasDB = ['id', 'pintor_id', 'alto_min_cm', 'alto_max_cm', 'precio'];

    public $id;
    public $pintor_id;
    public $alto_min_cm;
    public $alto_max_cm;
    public $precio;

    public function __construct($args = [])
    {
        $this->id = $args['id'] ?? null;
        $this->pintor_id = $args['pintor_id'] ?? null;
        $this->alto_min_cm = $args['alto_min_cm'] ?? 0;
        $this->alto_max_cm = $args['alto_max_cm'] ?? 0;
        $this->precio = $args['precio'] ?? 0;
    }

    public function validar()
    {
        self::$alertas = [];
        if (!$this->pintor_id) {
            self::$alertas['error'][] = 'Falta el pintor';
        }
        if (!is_numeric($this->alto_min_cm) || !is_numeric($this->alto_max_cm) || $this->alto_max_cm < $this->alto_min_cm) {
            self::$alertas['error'][] = 'El alto máximo debe ser mayor o igual al mínimo';
        }
        if (!is_numeric($this->precio) || $this->precio <= 0) {
            self::$alertas['error'][] = 'El precio debe ser mayor a 0';
        }
        return self::$alertas;
    }
}
