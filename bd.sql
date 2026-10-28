-- Cotizador de figuras 3D v2: cotizar, vender, gastos y balance mensual
-- MySQL 8 / InnoDB / utf8mb4
CREATE DATABASE IF NOT EXISTS cotizador_3d CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE cotizador_3d;

-- ============ CATÁLOGOS ============

-- Costos globales, versionados: cada cotización guarda qué config usó.
CREATE TABLE configuraciones (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  merma_pct       DECIMAL(5,2)  NOT NULL DEFAULT 10.00,
  consumo_watts   DECIMAL(7,2)  NOT NULL DEFAULT 180.00,
  tarifa_kwh      DECIMAL(8,4)  NOT NULL DEFAULT 2.2800,
  desgaste_hora   DECIMAL(8,2)  NOT NULL DEFAULT 3.00,
  empaque         DECIMAL(8,2)  NOT NULL DEFAULT 50.00,
  margen_pct      DECIMAL(5,2)  NOT NULL DEFAULT 40.00,
  impuesto_pct    DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
  precio_minimo   DECIMAL(10,2) NOT NULL DEFAULT 100.00,
  redondeo        SMALLINT UNSIGNED NOT NULL DEFAULT 5,
  activa          TINYINT(1)    NOT NULL DEFAULT 0,
  creada_en       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE materiales (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre    VARCHAR(80)   NOT NULL,
  costo_kg  DECIMAL(10,2) NOT NULL,
  activo    TINYINT(1)    NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE clientes (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre    VARCHAR(120) NOT NULL,
  telefono  VARCHAR(30)  NULL,
  notas     TEXT         NULL,
  creado_en TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE pintores (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre    VARCHAR(120) NOT NULL,
  telefono  VARCHAR(30)  NULL,
  activo    TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB;

-- Lo que cobra cada pintor según el alto (Ej. 0-20 cm = Q180, 20.1-30 cm = Q300)
CREATE TABLE pintor_tarifas (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pintor_id   INT UNSIGNED  NOT NULL,
  alto_min_cm DECIMAL(5,1)  NOT NULL,
  alto_max_cm DECIMAL(5,1)  NOT NULL,
  precio      DECIMAL(10,2) NOT NULL,
  CONSTRAINT fk_tarifa_pintor FOREIGN KEY (pintor_id) REFERENCES pintores(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- directo = 1: se puede ligar a una venta (pintora, envío). directo = 0: gasto general del negocio.
CREATE TABLE categorias_gasto (
  id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre  VARCHAR(60) NOT NULL UNIQUE,
  directo TINYINT(1)  NOT NULL DEFAULT 0
) ENGINE=InnoDB;

-- ============ COTIZACIÓN ============

-- Cada cálculo guarda una "foto" de los costos, no solo el precio final.
CREATE TABLE cotizaciones (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cliente_id        INT UNSIGNED NULL,
  configuracion_id  INT UNSIGNED NOT NULL,
  pintor_id         INT UNSIGNED NULL,
  nombre_pieza      VARCHAR(120) NOT NULL,
  alto_cm           DECIMAL(5,1) NULL,
  costo_pintura     DECIMAL(10,2) NOT NULL DEFAULT 0,
  costo_envio       DECIMAL(10,2) NOT NULL DEFAULT 0,
  otros_insumos     DECIMAL(10,2) NOT NULL DEFAULT 0,
  total_gramos      DECIMAL(10,2) NOT NULL DEFAULT 0,
  total_minutos     INT UNSIGNED  NOT NULL DEFAULT 0,
  costo_material    DECIMAL(10,2) NOT NULL DEFAULT 0,
  costo_luz         DECIMAL(10,2) NOT NULL DEFAULT 0,
  costo_desgaste    DECIMAL(10,2) NOT NULL DEFAULT 0,
  costo_empaque     DECIMAL(10,2) NOT NULL DEFAULT 0,
  costo_total       DECIMAL(10,2) NOT NULL DEFAULT 0,
  margen_pct_usado  DECIMAL(5,2)  NOT NULL,
  precio_sugerido   DECIMAL(10,2) NOT NULL DEFAULT 0,
  creada_en         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cot_cliente FOREIGN KEY (cliente_id)       REFERENCES clientes(id) ON DELETE SET NULL,
  CONSTRAINT fk_cot_config  FOREIGN KEY (configuracion_id) REFERENCES configuraciones(id),
  CONSTRAINT fk_cot_pintor  FOREIGN KEY (pintor_id)        REFERENCES pintores(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Una pieza se imprime en partes, cada una con sus gramos y tiempo.
CREATE TABLE cotizacion_partes (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cotizacion_id  INT UNSIGNED  NOT NULL,
  material_id    INT UNSIGNED  NOT NULL,
  nombre         VARCHAR(80)   NOT NULL,
  gramos         DECIMAL(10,2) NOT NULL,
  minutos        INT UNSIGNED  NOT NULL,
  CONSTRAINT fk_parte_cot FOREIGN KEY (cotizacion_id) REFERENCES cotizaciones(id) ON DELETE CASCADE,
  CONSTRAINT fk_parte_mat FOREIGN KEY (material_id)   REFERENCES materiales(id)
) ENGINE=InnoDB;

-- ============ PRODUCCIÓN ============

-- El seguimiento del trabajo (sin dinero): en qué etapa va cada figura.
CREATE TABLE pedidos (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cliente_id       INT UNSIGNED NOT NULL,
  cotizacion_id    INT UNSIGNED NULL,
  nombre_mascota   VARCHAR(120) NOT NULL,
  descripcion      TEXT NULL,
  estado           ENUM('cotizado','confirmado','imprimiendo','pintando','listo','entregado','cancelado')
                   NOT NULL DEFAULT 'confirmado',
  fecha_pedido     DATE NOT NULL,
  fecha_entrega    DATE NULL,
  notas            TEXT NULL,
  creado_en        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_estado (estado),
  CONSTRAINT fk_ped_cliente FOREIGN KEY (cliente_id)    REFERENCES clientes(id),
  CONSTRAINT fk_ped_cot     FOREIGN KEY (cotizacion_id) REFERENCES cotizaciones(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============ DINERO ============

-- La venta: lo que realmente cobraste (Ej. Q858) y en qué fecha. Base del balance mensual.
CREATE TABLE ventas (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pedido_id      INT UNSIGNED NULL UNIQUE,
  cotizacion_id  INT UNSIGNED NULL,
  cliente_id     INT UNSIGNED NOT NULL,
  descripcion    VARCHAR(255) NOT NULL,
  fecha_venta    DATE NOT NULL,
  precio_venta   DECIMAL(10,2) NOT NULL,
  impuesto_pct   DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
  ingreso_neto   DECIMAL(10,2) AS (ROUND(precio_venta / (1 + impuesto_pct / 100), 2)) STORED,
  notas          TEXT NULL,
  creado_en      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_fecha_venta (fecha_venta),
  CONSTRAINT fk_venta_pedido  FOREIGN KEY (pedido_id)     REFERENCES pedidos(id)      ON DELETE SET NULL,
  CONSTRAINT fk_venta_cot     FOREIGN KEY (cotizacion_id) REFERENCES cotizaciones(id) ON DELETE SET NULL,
  CONSTRAINT fk_venta_cliente FOREIGN KEY (cliente_id)    REFERENCES clientes(id)
) ENGINE=InnoDB;

-- Cobros de una venta (anticipo, saldo). Base del flujo de caja.
CREATE TABLE pagos (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  venta_id  INT UNSIGNED  NOT NULL,
  monto     DECIMAL(10,2) NOT NULL,
  tipo      ENUM('anticipo','saldo','otro') NOT NULL DEFAULT 'anticipo',
  fecha     DATE NOT NULL,
  notas     VARCHAR(255) NULL,
  INDEX idx_pago_fecha (fecha),
  CONSTRAINT fk_pago_venta FOREIGN KEY (venta_id) REFERENCES ventas(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Todo lo que sale: pintora, envíos, compra de filamento, luz, repuestos, equipo...
-- venta_id solo se llena cuando el gasto es de una pieza concreta (pintora, envío).
CREATE TABLE gastos (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fecha         DATE NOT NULL,
  categoria_id  INT UNSIGNED NOT NULL,
  venta_id      INT UNSIGNED NULL,
  pintor_id     INT UNSIGNED NULL,
  descripcion   VARCHAR(255) NOT NULL,
  monto         DECIMAL(10,2) NOT NULL,
  creado_en     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_gasto_fecha (fecha),
  CONSTRAINT fk_gasto_cat    FOREIGN KEY (categoria_id) REFERENCES categorias_gasto(id),
  CONSTRAINT fk_gasto_venta  FOREIGN KEY (venta_id)     REFERENCES ventas(id)   ON DELETE SET NULL,
  CONSTRAINT fk_gasto_pintor FOREIGN KEY (pintor_id)    REFERENCES pintores(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============ VISTAS PARA EL DASHBOARD ============

-- Cuadro real por venta: estimado (cotización) vs real (gastos directos registrados)
CREATE VIEW v_ventas AS
SELECT t.*, ROUND(t.ganancia_real / t.ingreso_neto * 100, 1) AS margen_real_pct
FROM (
  SELECT
    v.id, v.fecha_venta, c.nombre AS cliente, v.descripcion,
    v.precio_venta, v.ingreso_neto,
    COALESCE(pg.pagado, 0)                      AS pagado,
    v.precio_venta - COALESCE(pg.pagado, 0)     AS saldo,
    q.costo_total                               AS costo_estimado,
    COALESCE(gd.total, 0)                       AS gastos_directos_reales,
    v.ingreso_neto - q.costo_total              AS ganancia_estimada,
    v.ingreso_neto - (
      COALESCE(q.costo_material + q.costo_luz + q.costo_desgaste + q.costo_empaque, 0)
      + COALESCE(gd.total, 0)
    )                                           AS ganancia_real
  FROM ventas v
  JOIN clientes c                ON c.id = v.cliente_id
  LEFT JOIN cotizaciones q       ON q.id = v.cotizacion_id
  LEFT JOIN (SELECT venta_id, SUM(monto) AS pagado FROM pagos  GROUP BY venta_id) pg ON pg.venta_id = v.id
  LEFT JOIN (SELECT venta_id, SUM(monto) AS total  FROM gastos WHERE venta_id IS NOT NULL GROUP BY venta_id) gd ON gd.venta_id = v.id
) t;

-- Balance mensual (resultados): ventas del mes menos gastos del mes
CREATE VIEW v_balance_mensual AS
SELECT mes,
       SUM(ingresos) AS ingresos,
       SUM(gastos)   AS gastos,
       SUM(ingresos) - SUM(gastos) AS utilidad,
       ROUND((SUM(ingresos) - SUM(gastos)) / NULLIF(SUM(ingresos), 0) * 100, 1) AS margen_pct
FROM (
  SELECT DATE_FORMAT(fecha_venta, '%Y-%m') AS mes, ingreso_neto AS ingresos, 0 AS gastos FROM ventas
  UNION ALL
  SELECT DATE_FORMAT(fecha, '%Y-%m'), 0, monto FROM gastos
) u
GROUP BY mes;

-- Flujo de caja mensual: dinero que entró (cobros) y salió (gastos), con acumulado
CREATE VIEW v_flujo_caja_mensual AS
SELECT mes, cobros, salidas, neto, SUM(neto) OVER (ORDER BY mes) AS acumulado
FROM (
  SELECT mes, SUM(cobros) AS cobros, SUM(salidas) AS salidas, SUM(cobros) - SUM(salidas) AS neto
  FROM (
    SELECT DATE_FORMAT(fecha, '%Y-%m') AS mes, monto AS cobros, 0 AS salidas FROM pagos
    UNION ALL
    SELECT DATE_FORMAT(fecha, '%Y-%m'), 0, monto FROM gastos
  ) u
  GROUP BY mes
) m;

-- En qué se va el dinero cada mes
CREATE VIEW v_gastos_por_categoria AS
SELECT DATE_FORMAT(g.fecha, '%Y-%m') AS mes, cg.nombre AS categoria, SUM(g.monto) AS total
FROM gastos g
JOIN categorias_gasto cg ON cg.id = g.categoria_id
GROUP BY DATE_FORMAT(g.fecha, '%Y-%m'), cg.nombre;

-- ============ DATOS INICIALES ============
INSERT INTO configuraciones (activa) VALUES (1);
INSERT INTO materiales (nombre, costo_kg) VALUES ('Generic PLA', 239.00);
INSERT INTO categorias_gasto (nombre, directo) VALUES
  ('Pintura (pintora)', 1), ('Envíos', 1),
  ('Filamento', 0), ('Electricidad', 0), ('Mantenimiento y repuestos', 0),
  ('Empaque', 0), ('Equipo y herramientas', 0), ('Publicidad', 0), ('Otros', 0);