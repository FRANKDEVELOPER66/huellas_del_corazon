<?php

namespace Model;

class Configuracion extends ModeloBase
{
    protected static $tabla = 'configuraciones';
    protected static $idTabla = 'id';
    protected static $columnasDB = ['id', 'merma_pct', 'consumo_watts', 'tarifa_kwh', 'desgaste_hora', 'empaque', 'margen_pct', 'impuesto_pct', 'precio_minimo', 'redondeo', 'activa'];

    public $id;
    public $merma_pct;
    public $consumo_watts;
    public $tarifa_kwh;
    public $desgaste_hora;
    public $empaque;
    public $margen_pct;
    public $impuesto_pct;
    public $precio_minimo;
    public $redondeo;
    public $activa;
    public $creada_en;

    public function __construct($args = [])
    {
        $this->id = $args['id'] ?? null;
        $this->merma_pct = $args['merma_pct'] ?? 10;
        $this->consumo_watts = $args['consumo_watts'] ?? 180;
        $this->tarifa_kwh = $args['tarifa_kwh'] ?? 2.28;
        $this->desgaste_hora = $args['desgaste_hora'] ?? 3;
        $this->empaque = $args['empaque'] ?? 50;
        $this->margen_pct = $args['margen_pct'] ?? 40;
        $this->impuesto_pct = $args['impuesto_pct'] ?? 0;
        $this->precio_minimo = $args['precio_minimo'] ?? 100;
        $this->redondeo = $args['redondeo'] ?? 5;
        $this->activa = $args['activa'] ?? 0;
        $this->creada_en = $args['creada_en'] ?? null;
    }

    public function validar()
    {
        self::$alertas = [];
        foreach (['merma_pct', 'consumo_watts', 'tarifa_kwh', 'desgaste_hora', 'empaque', 'impuesto_pct', 'precio_minimo'] as $campo) {
            if (!is_numeric($this->$campo) || $this->$campo < 0) {
                self::$alertas['error'][] = "El valor de {$campo} debe ser un número mayor o igual a 0";
            }
        }
        // El margen se divide entre (1 - margen): 100% o más rompería la fórmula del precio
        if (!is_numeric($this->margen_pct) || $this->margen_pct < 0 || $this->margen_pct >= 100) {
            self::$alertas['error'][] = 'El margen debe estar entre 0 y 99.99';
        }
        if (!is_numeric($this->redondeo) || $this->redondeo < 1) {
            self::$alertas['error'][] = 'El redondeo debe ser al menos 1';
        }
        return self::$alertas;
    }

    // Configuración vigente (la única con activa = 1)
    public static function vigente()
    {
        $resultado = self::where('activa', '1');
        return array_shift($resultado);
    }

    // Guarda esta configuración como una fila NUEVA y la deja vigente.
    // Las cotizaciones viejas conservan la configuración con la que se calcularon.
    public function activar()
    {
        self::$db->beginTransaction();
        try {
            self::$db->exec('UPDATE configuraciones SET activa = 0');
            $this->id = null;
            $this->activa = 1;
            $resultado = $this->crear();
            self::$db->commit();
            return $resultado;
        } catch (\Throwable $e) {
            self::$db->rollBack();
            throw $e;
        }
    }
}
