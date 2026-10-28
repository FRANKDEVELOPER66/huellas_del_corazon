<?php

namespace Model;

class CotizacionParte extends ModeloBase
{
    protected static $tabla = 'cotizacion_partes';
    protected static $idTabla = 'id';
    protected static $columnasDB = ['id', 'cotizacion_id', 'material_id', 'nombre', 'gramos', 'minutos'];

    public $id;
    public $cotizacion_id;
    public $material_id;
    public $nombre;
    public $gramos;
    public $minutos;

    public function __construct($args = [])
    {
        $this->id = $args['id'] ?? null;
        $this->cotizacion_id = $args['cotizacion_id'] ?? null;
        $this->material_id = $args['material_id'] ?? null;
        $this->nombre = $args['nombre'] ?? '';
        $this->gramos = $args['gramos'] ?? 0;
        $this->minutos = $args['minutos'] ?? 0;
    }

    public function validar()
    {
        self::$alertas = [];
        if (!$this->cotizacion_id || !$this->material_id) {
            self::$alertas['error'][] = 'Falta la cotización o el material de la parte';
        }
        if (!$this->nombre) {
            self::$alertas['error'][] = 'El nombre de la parte es obligatorio';
        }
        if (!is_numeric($this->gramos) || $this->gramos <= 0) {
            self::$alertas['error'][] = 'Los gramos deben ser mayores a 0';
        }
        if (!is_numeric($this->minutos) || $this->minutos < 0) {
            self::$alertas['error'][] = 'Los minutos no pueden ser negativos';
        }
        return self::$alertas;
    }
}
