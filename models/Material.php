<?php

namespace Model;

class Material extends ModeloBase
{
    protected static $tabla = 'materiales';
    protected static $idTabla = 'id';
    protected static $columnasDB = ['id', 'nombre', 'costo_kg', 'activo'];

    public $id;
    public $nombre;
    public $costo_kg;
    public $activo;

    public function __construct($args = [])
    {
        $this->id = $args['id'] ?? null;
        $this->nombre = $args['nombre'] ?? '';
        $this->costo_kg = $args['costo_kg'] ?? 0;
        $this->activo = $args['activo'] ?? 1;
    }

    public function validar()
    {
        self::$alertas = [];
        if (!$this->nombre) {
            self::$alertas['error'][] = 'El nombre del material es obligatorio';
        }
        if (!is_numeric($this->costo_kg) || $this->costo_kg <= 0) {
            self::$alertas['error'][] = 'El costo por kg debe ser mayor a 0';
        }
        return self::$alertas;
    }

    public static function activos()
    {
        return self::where('activo', '1');
    }
}
