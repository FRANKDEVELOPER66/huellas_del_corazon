<?php

namespace Model;

class Cotizacion extends ModeloBase
{
    protected static $tabla = 'cotizaciones';
    protected static $idTabla = 'id';
    protected static $columnasDB = ['id', 'cliente_id', 'configuracion_id', 'pintor_id', 'nombre_pieza', 'alto_cm', 'costo_pintura', 'costo_envio', 'envio_pintora', 'envio_cliente', 'otros_insumos', 'total_gramos', 'total_minutos', 'costo_material', 'costo_luz', 'costo_desgaste', 'costo_empaque', 'empaque_nombre', 'costo_total', 'margen_pct_usado', 'precio_sugerido'];

    public $id;
    public $cliente_id;
    public $configuracion_id;
    public $pintor_id;
    public $nombre_pieza;
    public $alto_cm;
    public $costo_pintura;
    public $costo_envio;
    public $envio_pintora;
    public $envio_cliente;
    public $otros_insumos;
    public $total_gramos;
    public $total_minutos;
    public $costo_material;
    public $costo_luz;
    public $costo_desgaste;
    public $costo_empaque;
    public $empaque_nombre;
    public $costo_total;
    public $margen_pct_usado;
    public $precio_sugerido;
    public $creada_en;

    public function __construct($args = [])
    {
        $this->id = $args['id'] ?? null;
        $this->cliente_id = $args['cliente_id'] ?? null;
        $this->configuracion_id = $args['configuracion_id'] ?? null;
        $this->pintor_id = $args['pintor_id'] ?? null;
        $this->nombre_pieza = $args['nombre_pieza'] ?? '';
        $this->alto_cm = $args['alto_cm'] ?? null;
        $this->costo_pintura = $args['costo_pintura'] ?? 0;
        $this->costo_envio = $args['costo_envio'] ?? 0;
        $this->envio_pintora = $args['envio_pintora'] ?? null;
        $this->envio_cliente = $args['envio_cliente'] ?? null;
        $this->otros_insumos = $args['otros_insumos'] ?? 0;
        $this->total_gramos = $args['total_gramos'] ?? 0;
        $this->total_minutos = $args['total_minutos'] ?? 0;
        $this->costo_material = $args['costo_material'] ?? 0;
        $this->costo_luz = $args['costo_luz'] ?? 0;
        $this->costo_desgaste = $args['costo_desgaste'] ?? 0;
        $this->costo_empaque = $args['costo_empaque'] ?? 0;
        $this->empaque_nombre = $args['empaque_nombre'] ?? null;
        $this->costo_total = $args['costo_total'] ?? 0;
        $this->margen_pct_usado = $args['margen_pct_usado'] ?? null;
        $this->precio_sugerido = $args['precio_sugerido'] ?? 0;
        $this->creada_en = $args['creada_en'] ?? null;
    }

    public function validar()
    {
        self::$alertas = [];
        if (!$this->nombre_pieza) {
            self::$alertas['error'][] = 'El nombre de la pieza es obligatorio';
        }
        if (!$this->configuracion_id) {
            self::$alertas['error'][] = 'Falta la configuración de costos';
        }
        if (!is_numeric($this->margen_pct_usado)) {
            self::$alertas['error'][] = 'Falta el margen usado';
        }
        foreach (['costo_pintura', 'costo_envio', 'otros_insumos'] as $campo) {
            if (!is_numeric($this->$campo) || $this->$campo < 0) {
                self::$alertas['error'][] = "El valor de {$campo} debe ser un número mayor o igual a 0";
            }
        }
        return self::$alertas;
    }

    public function partes()
    {
        return CotizacionParte::where('cotizacion_id', (int) $this->id);
    }
}
