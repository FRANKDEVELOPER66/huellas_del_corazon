<?php

namespace Controllers;

// Guardado y borrado de comprobantes de pago (imagen o PDF). Lo usan Ventas y Pedidos.
// Requiere que la clase use también el trait RespuestaApi.
trait Comprobantes
{
    private static function carpetaComprobantes(bool $crear = true): string
    {
        $carpeta = __DIR__ . '/../storage/comprobantes';
        if ($crear && !is_dir($carpeta)) {
            if (!@mkdir($carpeta, 0775, true)) {
                self::responder(0, 'No se pudo crear la carpeta storage/comprobantes (revisa los permisos)');
            }
            // Bloquea el acceso directo por URL; se ven solo con comprobanteAPI
            @file_put_contents($carpeta . '/.htaccess', "Require all denied\n");
        }
        return $carpeta;
    }

    // Guarda el archivo subido (imagen o PDF, máx. 5 MB) y devuelve su nombre, o null si no se envió ninguno
    private static function guardarComprobante(): ?string
    {
        if (empty($_FILES['comprobante']['name'])) {
            return null;
        }

        $archivo = $_FILES['comprobante'];
        if ($archivo['error'] === UPLOAD_ERR_INI_SIZE || $archivo['error'] === UPLOAD_ERR_FORM_SIZE) {
            self::responder(0, 'El comprobante pesa más de lo que permite el servidor (' . ini_get('upload_max_filesize') . ')');
        }
        if ($archivo['error'] !== UPLOAD_ERR_OK) {
            self::responder(0, 'No se pudo subir el comprobante');
        }
        if ($archivo['size'] > 5 * 1024 * 1024) {
            self::responder(0, 'El comprobante pesa más de 5 MB');
        }

        $tipos = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($archivo['tmp_name']);
        if (!isset($tipos[$mime])) {
            self::responder(0, 'El comprobante debe ser una imagen (JPG, PNG, WEBP) o un PDF');
        }

        $nombre = bin2hex(random_bytes(16)) . '.' . $tipos[$mime];
        if (!move_uploaded_file($archivo['tmp_name'], self::carpetaComprobantes() . '/' . $nombre)) {
            self::responder(0, 'No se pudo guardar el comprobante');
        }
        return $nombre;
    }

    private static function borrarComprobante(?string $nombre): void
    {
        if ($nombre) {
            @unlink(self::carpetaComprobantes(false) . '/' . basename($nombre));
        }
    }
}
