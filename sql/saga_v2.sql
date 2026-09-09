-- ============================================================================
--  SAGA v2 — Base de datos completa e INDEPENDIENTE de la versión 1
--  Motor: MySQL / MariaDB (XAMPP) · InnoDB · utf8mb4
--
--  Uso (IMPORTANTE: forzar utf8mb4 para no corromper la ñ de `contraseña`):
--    mysql --default-character-set=utf8mb4 -u root  <  sql/saga_v2.sql
--  o importar este archivo desde phpMyAdmin (que ya usa utf8mb4).
--
--  IMPORTANTE: esta BD se llama `saga_v2` para NO chocar con la v1 (`saga_local`).
--  Recuerda dejar en el .env de este proyecto:  DB_NAME=saga_v2
-- ============================================================================

DROP DATABASE IF EXISTS `saga_v2`;
CREATE DATABASE `saga_v2`
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;
USE `saga_v2`;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------------
-- 1. SEDES
-- ---------------------------------------------------------------------------
CREATE TABLE `sedes` (
  `id`        INT AUTO_INCREMENT PRIMARY KEY,
  `nombre`    VARCHAR(150)                 NOT NULL,
  `ciudad`    VARCHAR(100)                 NULL,
  `direccion` VARCHAR(255)                 NULL,
  `telefono`  VARCHAR(30)                  NULL,
  `email`     VARCHAR(150)                 NULL,
  `estado`    ENUM('activa','inactiva')    NOT NULL DEFAULT 'activa',
  `creado_en` DATETIME                     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_sedes_estado` (`estado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 2. PISOS  (pertenecen a una sede)
-- ---------------------------------------------------------------------------
CREATE TABLE `pisos` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `sede_id`     INT           NOT NULL,
  `nombre`      VARCHAR(100)  NOT NULL,
  `descripcion` VARCHAR(255)  NULL,
  `creado_en`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_pisos_sede`
    FOREIGN KEY (`sede_id`) REFERENCES `sedes`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  INDEX `idx_pisos_sede` (`sede_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 3. AMBIENTES  (pertenecen a un piso)
-- ---------------------------------------------------------------------------
CREATE TABLE `ambientes` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `nombre`      VARCHAR(100)  NOT NULL,
  `descripcion` VARCHAR(255)  NULL,
  `piso_id`     INT           NOT NULL,
  `estado`      ENUM('disponible','ocupado','proximo_a_desocupar')
                              NOT NULL DEFAULT 'disponible',
  `creado_en`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_ambientes_piso`
    FOREIGN KEY (`piso_id`) REFERENCES `pisos`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  INDEX `idx_ambientes_piso`   (`piso_id`),
  INDEX `idx_ambientes_estado` (`estado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 4. USUARIOS  (admin, instructor, celador, vocero)
--    Nota: la columna se llama `contraseña` (con ñ) tal como la usa el código.
-- ---------------------------------------------------------------------------
CREATE TABLE `usuarios` (
  `id`                    INT AUTO_INCREMENT PRIMARY KEY,
  `cedula`                VARCHAR(20)   NOT NULL,
  `email`                 VARCHAR(150)  NULL,
  `nombre`                VARCHAR(100)  NOT NULL,
  `apellido`              VARCHAR(100)  NULL,
  `contraseña`            VARCHAR(255)  NOT NULL,
  `telefono`              VARCHAR(30)   NULL,
  `nivel_estudio`         VARCHAR(100)  NULL,
  `programa_estudio`      VARCHAR(150)  NULL,
  `rol`                   ENUM('admin','instructor','celador','vocero') NOT NULL,
  `tipo_contrato`         ENUM('planta','contratista') NULL,
  `fecha_inicio_contrato` DATE          NULL,
  `fecha_fin_contrato`    DATE          NULL,
  `estado`                ENUM('activo','inactivo') NOT NULL DEFAULT 'activo',
  `sede_id`               INT           NULL,
  `creado_en`             DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_usuarios_cedula` (`cedula`),
  CONSTRAINT `fk_usuarios_sede`
    FOREIGN KEY (`sede_id`) REFERENCES `sedes`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  INDEX `idx_usuarios_rol`    (`rol`),
  INDEX `idx_usuarios_sede`   (`sede_id`),
  INDEX `idx_usuarios_estado` (`estado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 5. HISTORIAL DE OCUPACIÓN  (reservas / uso de ambientes)
-- ---------------------------------------------------------------------------
CREATE TABLE `historial_ocupacion` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `ambiente_id`   INT       NOT NULL,
  `usuario_id`    INT       NOT NULL,
  `fecha_inicio`  DATETIME  NOT NULL,
  `fecha_fin`     DATETIME  NOT NULL,
  `estado`        ENUM('ocupado','proximo_a_desocupar','disponible','finalizado','cancelado')
                            NOT NULL DEFAULT 'ocupado',
  `observaciones` VARCHAR(255) NULL,
  `jornada`       ENUM('mañana','tarde','noche','personalizado') NULL,
  `creado_en`     DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_ocup_ambiente`
    FOREIGN KEY (`ambiente_id`) REFERENCES `ambientes`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ocup_usuario`
    FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  INDEX `idx_ocup_ambiente` (`ambiente_id`),
  INDEX `idx_ocup_usuario`  (`usuario_id`),
  INDEX `idx_ocup_estado`   (`estado`),
  INDEX `idx_ocup_inicio`   (`fecha_inicio`),
  INDEX `idx_ocup_rango`    (`ambiente_id`, `fecha_inicio`, `fecha_fin`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 6. RECORDATORIOS ENVIADOS  (control del cron de recordatorios)
-- ---------------------------------------------------------------------------
CREATE TABLE `recordatorios_enviados` (
  `ocupacion_id` INT PRIMARY KEY,
  `enviado_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_rec_ocup`
    FOREIGN KEY (`ocupacion_id`) REFERENCES `historial_ocupacion`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
--  DATOS INICIALES (semilla mínima para poder entrar)
-- ============================================================================

-- Sede principal
INSERT INTO `sedes` (`nombre`, `ciudad`, `direccion`, `telefono`, `email`, `estado`)
VALUES ('Sede Principal', 'Bogotá', 'Dirección por definir', '0000000', 'sede@saga.local', 'activa');

-- Usuario administrador por defecto
--   Cédula:      admin
--   Contraseña:  admin123     (CÁMBIALA después del primer ingreso)
INSERT INTO `usuarios`
  (`cedula`, `email`, `nombre`, `apellido`, `contraseña`, `rol`, `estado`, `sede_id`)
VALUES
  ('admin', 'admin@saga.local', 'Administrador', 'SAGA',
   '$2y$10$udl9CSdeuWsSf2KT41WYj.KoZfYOOshrUDmmh9o2qPupHnSbF2DV6',
   'admin', 'activo', 1);

-- ============================================================================
--  FIN — SAGA v2
-- ============================================================================
