<?php

namespace Model;

class Pedido extends ModeloBase
{
    protected static $tabla = 'pedidos';
    protected static $idTabla = 'id';
    protected static $columnasDB = ['id', 'cliente_id', 'cotizacion_id', 'nombre_mascota', 'descripcion', 'estado', 'fecha_pedido', 'fecha_entrega', 'notas'];

    public const ESTADOS = ['solicitado', 'imprimiendo', 'impreso', 'pintando', 'listo', 'entregado', 'cancelado'];

    public $id;
    public $cliente_id;
    public $cotizacion_id;
    public $nombre_mascota;
    public $descripcion;
    public $estado;
    public $fecha_pedido;
    public $fecha_entrega;
    public $notas;
    public $creado_en;

    public function __construct($args = [])
    {
        $this->id = $args['id'] ?? null;
        $this->cliente_id = $args['cliente_id'] ?? null;
        $this->cotizacion_id = $args['cotizacion_id'] ?? null;
        $this->nombre_mascota = $args['nombre_mascota'] ?? '';
        $this->descripcion = $args['descripcion'] ?? null;
        $this->estado = $args['estado'] ?? 'solicitado';
        $this->fecha_pedido = $args['fecha_pedido'] ?? date('Y-m-d');
        $this->fecha_entrega = $args['fecha_entrega'] ?? null;
        $this->notas = $args['notas'] ?? null;
        $this->creado_en = $args['creado_en'] ?? null;
    }

    public function validar()
    {
        self::$alertas = [];
        if (!$this->cliente_id) {
            self::$alertas['error'][] = 'Falta el cliente';
        }
        if (!$this->nombre_mascota) {
            self::$alertas['error'][] = 'El nombre de la mascota es obligatorio';
        }
        if (!in_array($this->estado, self::ESTADOS, true)) {
            self::$alertas['error'][] = 'El estado no es válido';
        }
        if (!self::esFecha($this->fecha_pedido)) {
            self::$alertas['error'][] = 'La fecha del pedido no es válida';
        }
        if ($this->fecha_entrega && (!self::esFecha($this->fecha_entrega) || $this->fecha_entrega < $this->fecha_pedido)) {
            self::$alertas['error'][] = 'La fecha de entrega no es válida o es anterior al pedido';
        }
        return self::$alertas;
    }

    public static function porEstado($estado)
    {
        if (!in_array($estado, self::ESTADOS, true)) {
            return [];
        }
        return self::where('estado', $estado);
    }

    public function cambiarEstado($estado)
    {
        $this->estado = $estado;
        if (!empty($this->validar())) {
            return false;
        }
        return $this->guardar();
    }
}
