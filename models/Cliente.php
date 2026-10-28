<?php

namespace Model;

class Cliente extends ModeloBase
{
    protected static $tabla = 'clientes';
    protected static $idTabla = 'id';
    protected static $columnasDB = ['id', 'nombre', 'telefono', 'notas'];

    public $id;
    public $nombre;
    public $telefono;
    public $notas;
    public $creado_en;

    public function __construct($args = [])
    {
        $this->id = $args['id'] ?? null;
        $this->nombre = $args['nombre'] ?? '';
        $this->telefono = $args['telefono'] ?? null;
        $this->notas = $args['notas'] ?? null;
        $this->creado_en = $args['creado_en'] ?? null;
    }

    public function validar()
    {
        self::$alertas = [];
        if (!$this->nombre) {
            self::$alertas['error'][] = 'El nombre del cliente es obligatorio';
        }
        return self::$alertas;
    }

    // Para el buscador de clientes (devuelve arrays)
    public static function buscar($texto)
    {
        return self::fetchArray(
            'SELECT * FROM clientes WHERE nombre LIKE ? OR telefono LIKE ? ORDER BY nombre LIMIT 20',
            ["%{$texto}%", "%{$texto}%"]
        );
    }
}
