<?php

namespace Model;

// Capa entre tu ActiveRecord y los modelos del cotizador. No modifica ActiveRecord.php.
class ModeloBase extends ActiveRecord
{
    // ActiveRecord::sanitizarAtributos() usa PDO::quote(), que convierte null en ''.
    // MySQL 8 (modo estricto) rechaza '' en columnas INT y DATE, así que aquí
    // null y '' se guardan como NULL real (pedido_id, pintor_id, fecha_entrega, etc.).
    public function sanitizarAtributos()
    {
        $sanitizado = [];
        foreach ($this->atributos() as $key => $value) {
            $sanitizado[$key] = ($value === null || $value === '') ? 'NULL' : self::$db->quote($value);
        }
        return $sanitizado;
    }

    // Valida formato Y-m-d (lo que envía <input type="date">)
    protected static function esFecha($valor)
    {
        $fecha = \DateTime::createFromFormat('Y-m-d', (string) $valor);
        return $fecha && $fecha->format('Y-m-d') === $valor;
    }
}
