<?php

namespace Model;

class CategoriaGasto extends ModeloBase
{
    protected static $tabla = 'categorias_gasto';
    protected static $idTabla = 'id';
    protected static $columnasDB = ['id', 'nombre', 'directo', 'tipo'];

    public $id;
    public $nombre;
    public $directo;
    public $tipo;

    public function __construct($args = [])
    {
        $this->id = $args['id'] ?? null;
        $this->nombre = $args['nombre'] ?? '';
        $this->directo = $args['directo'] ?? 0;
        $this->tipo = $args['tipo'] ?? 'operativo';
    }

    public function validar()
    {
        self::$alertas = [];
        if (!$this->nombre) {
            self::$alertas['error'][] = 'El nombre de la categoría es obligatorio';
        }
        return self::$alertas;
    }

    // Categorías que se pueden ligar a una venta (pintora, envíos)
    public static function directas()
    {
        return self::where('directo', '1');
    }
}
