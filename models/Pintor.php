<?php

namespace Model;

class Pintor extends ModeloBase
{
    protected static $tabla = 'pintores';
    protected static $idTabla = 'id';
    protected static $columnasDB = ['id', 'nombre', 'telefono', 'activo'];

    public $id;
    public $nombre;
    public $telefono;
    public $activo;

    public function __construct($args = [])
    {
        $this->id = $args['id'] ?? null;
        $this->nombre = $args['nombre'] ?? '';
        $this->telefono = $args['telefono'] ?? null;
        $this->activo = $args['activo'] ?? 1;
    }

    public function validar()
    {
        self::$alertas = [];
        if (!$this->nombre) {
            self::$alertas['error'][] = 'El nombre del pintor es obligatorio';
        }
        return self::$alertas;
    }

    public static function activos()
    {
        return self::where('activo', '1');
    }

    public function tarifas()
    {
        return PintorTarifa::where('pintor_id', (int) $this->id);
    }

    // Lo que cobra este pintor por una pieza de $altoCm (null si ningún rango aplica)
    public function tarifaPara($altoCm)
    {
        $r = self::fetchArray(
            'SELECT precio FROM pintor_tarifas WHERE pintor_id = ? AND ? BETWEEN alto_min_cm AND alto_max_cm LIMIT 1',
            [$this->id, $altoCm]
        );
        return $r ? (float) $r[0]['precio'] : null;
    }
}
