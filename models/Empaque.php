<?php

namespace Model;

class Empaque extends ModeloBase
{
    protected static $tabla = 'empaques';
    protected static $idTabla = 'id';
    protected static $columnasDB = ['id', 'nombre', 'costo', 'activo'];

    public $id;
    public $nombre;
    public $costo;
    public $activo;

    public function __construct($args = [])
    {
        $this->id = $args['id'] ?? null;
        $this->nombre = $args['nombre'] ?? '';
        $this->costo = $args['costo'] ?? 0;
        $this->activo = $args['activo'] ?? 1;
    }

    public function validar()
    {
        self::$alertas = [];
        if (!$this->nombre) {
            self::$alertas['error'][] = 'El nombre del empaque es obligatorio';
        }
        if (!is_numeric($this->costo) || $this->costo < 0) {
            self::$alertas['error'][] = 'El costo debe ser un número mayor o igual a 0';
        }
        return self::$alertas;
    }

    public static function activos()
    {
        return self::where('activo', '1');
    }
}
