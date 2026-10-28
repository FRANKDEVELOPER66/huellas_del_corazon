<?php

namespace Model;

class Aporte extends ModeloBase
{
    protected static $tabla = 'aportes';
    protected static $idTabla = 'id';
    protected static $columnasDB = ['id', 'fecha', 'tipo', 'monto', 'descripcion'];

    public const TIPOS = ['aporte', 'retiro'];

    public $id;
    public $fecha;
    public $tipo;
    public $monto;
    public $descripcion;
    public $creado_en;

    public function __construct($args = [])
    {
        $this->id = $args['id'] ?? null;
        $this->fecha = $args['fecha'] ?? date('Y-m-d');
        $this->tipo = $args['tipo'] ?? 'aporte';
        $this->monto = $args['monto'] ?? 0;
        $this->descripcion = $args['descripcion'] ?? '';
        $this->creado_en = $args['creado_en'] ?? null;
    }

    public function validar()
    {
        self::$alertas = [];
        if (!self::esFecha($this->fecha)) {
            self::$alertas['error'][] = 'La fecha no es válida';
        }
        if (!in_array($this->tipo, self::TIPOS, true)) {
            self::$alertas['error'][] = 'El tipo debe ser aporte o retiro';
        }
        if (!is_numeric($this->monto) || $this->monto <= 0) {
            self::$alertas['error'][] = 'El monto debe ser mayor a 0';
        }
        if (!$this->descripcion) {
            self::$alertas['error'][] = 'Escribe de qué es el movimiento';
        }
        return self::$alertas;
    }
}
