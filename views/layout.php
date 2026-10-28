<?php
$base = !empty($_ENV['APP_NAME']) ? '/' . $_ENV['APP_NAME'] : '';
$rutaActual = '/' . trim(substr((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), strlen($base)), '/');
$activo = fn(string $ruta): string => $rutaActual === $ruta ? 'active' : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="<?= asset('build/js/app.js') ?>"></script>
    <link rel="shortcut icon" href="<?= asset('images/HUELLA_SIN.png') ?>" type="image/x-icon">
    <link rel="stylesheet" href="<?= asset('build/styles.css') ?>">
    <title>Huellas del Corazón</title>
    <style>
        /* Paleta del logo: negro y dorado */
        :root {
            --hc-negro: #111111;
            --hc-dorado: #f6c33b;
            --hc-dorado-osc: #c99a14;
            --hc-gris: #f4f4f1;
            --hc-borde: #e4e1d8;
        }

        .navbar .nav-link.active {
            color: var(--hc-dorado) !important;
            font-weight: 600;
        }
    </style>
</head>

<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid">
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarToggler" aria-controls="navbarToggler" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <a class="navbar-brand" href="<?= $base ?>/">
                <img src="<?= asset('./images/HUELLA_SIN.png') ?>" width="50px" alt="Huellas del Corazón">
                Aplicaciones
            </a>
            <div class="collapse navbar-collapse" id="navbarToggler">

                <ul class="navbar-nav me-auto mb-2 mb-lg-0" style="margin: 0;">
                    <li class="nav-item">
                        <a class="nav-link <?= $activo('/') ?>" aria-current="page" href="<?= $base ?>/"><i class="bi bi-house-fill me-2"></i>Inicio</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $activo('/cotizaciones') ?>" href="<?= $base ?>/cotizaciones"><i class="bi bi-calculator-fill me-2"></i>Cotizar</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $activo('/pedidos') ?>" href="<?= $base ?>/pedidos"><i class="bi bi-box-seam me-2"></i>Pedidos</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $activo('/ventas') ?>" href="<?= $base ?>/ventas"><i class="bi bi-cash-coin me-2"></i>Ventas</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $activo('/gastos') ?>" href="<?= $base ?>/gastos"><i class="bi bi-receipt me-2"></i>Gastos</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $activo('/dashboard') ?>" href="<?= $base ?>/dashboard"><i class="bi bi-graph-up-arrow me-2"></i>Balance</a>
                    </li>

                    <div class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                            <i class="bi bi-gear me-2"></i>Catálogos
                        </a>
                        <ul class="dropdown-menu dropdown-menu-dark" id="dropwdownRevision" style="margin: 0;">
                            <li><a class="dropdown-item nav-link text-white" href="<?= $base ?>/clientes"><i class="ms-lg-0 ms-2 bi bi-people me-2"></i>Clientes</a></li>
                            <li><a class="dropdown-item nav-link text-white" href="<?= $base ?>/pintores"><i class="ms-lg-0 ms-2 bi bi-palette me-2"></i>Pintores y tarifas</a></li>
                            <li><a class="dropdown-item nav-link text-white" href="<?= $base ?>/materiales"><i class="ms-lg-0 ms-2 bi bi-layers me-2"></i>Materiales</a></li>
                            <li><a class="dropdown-item nav-link text-white" href="<?= $base ?>/configuracion"><i class="ms-lg-0 ms-2 bi bi-sliders me-2"></i>Costos y margen</a></li>
                        </ul>
                    </div>

                </ul>
                <div class="col-lg-1 d-grid mb-lg-0 mb-2">
                    <!-- Ruta relativa desde el archivo donde se incluye menu.php -->
                    <a href="/menu/" class="btn btn-danger"><i class="bi bi-arrow-bar-left"></i>MENÚ</a>
                </div>

            </div>
        </div>
    </nav>
    <div class="progress fixed-bottom" style="height: 6px;">
        <div class="progress-bar progress-bar-animated bg-danger" id="bar" role="progressbar" aria-valuemin="0" aria-valuemax="100"></div>
    </div>
    <div class="container-fluid pt-5 mb-4" style="min-height: 85vh">

        <?php echo $contenido; ?>
    </div>
    <div class="container-fluid ">
        <div class="row justify-content-center text-center">
            <div class="col-12">
                <p style="font-size:xx-small; font-weight: bold;">
                    Comando de Informática y Tecnología, <?= date('Y') ?> &copy;
                </p>
            </div>
        </div>
    </div>
</body>

</html>
