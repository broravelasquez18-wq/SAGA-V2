-- ============================================================================
--  SAGA v2 · Migración: Fichas SENA + sincronización de calendario
--  Importar con:  mysql --default-character-set=utf8mb4 -u root saga_v2 < sql/migracion_fichas.sql
-- ============================================================================
USE `saga_v2`;
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- FICHAS: cada formación del SENA (una por Google Sheet), con su AMBIENTE FIJO.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `fichas` (
  `id`               INT AUTO_INCREMENT PRIMARY KEY,
  `codigo_ficha`     VARCHAR(20)  NOT NULL,
  `programa`         VARCHAR(255) NULL,
  `ambiente_id`      INT          NULL,          -- ambiente fijo de la ficha
  `sede_id`          INT          NULL,
  `vocero_id`        INT          NULL,           -- vocero/a de la ficha (usuarios.id, rol vocero)
  `jornada`          VARCHAR(20)  NULL,           -- referencia (mañana/tarde/noche)
  `google_sheet_id`  VARCHAR(120) NULL,           -- ID del documento de Google
  `google_sheet_url` VARCHAR(500) NULL,           -- URL completa (referencia)
  `activa`           TINYINT(1)   NOT NULL DEFAULT 1,
  `ultima_sync`      DATETIME     NULL,
  `creado_en`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_fichas_codigo` (`codigo_ficha`),
  CONSTRAINT `fk_fichas_ambiente` FOREIGN KEY (`ambiente_id`)
      REFERENCES `ambientes`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_fichas_sede` FOREIGN KEY (`sede_id`)
      REFERENCES `sedes`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_fichas_vocero` FOREIGN KEY (`vocero_id`)
      REFERENCES `usuarios`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  INDEX `idx_fichas_ambiente` (`ambiente_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- FICHA_INSTRUCTORES: tabla de equivalencias  nombre_en_excel -> usuario SAGA.
-- El enlace confiable se hace por la cédula del usuario.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ficha_instructores` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `ficha_id`     INT          NOT NULL,
  `nombre_excel` VARCHAR(150) NOT NULL,           -- "OSCAR YANGUAS" tal cual el Sheet
  `cedula`       VARCHAR(20)  NULL,               -- cédula que se teclea para enlazar
  `usuario_id`   INT          NULL,               -- usuario resuelto por cédula
  `origen`       ENUM('sync','manual') NOT NULL DEFAULT 'sync', -- sync=detectado del calendario; manual=asignado por el admin
  `creado_en`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_ficha_nombre` (`ficha_id`, `nombre_excel`),
  CONSTRAINT `fk_fi_ficha` FOREIGN KEY (`ficha_id`)
      REFERENCES `fichas`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_fi_usuario` FOREIGN KEY (`usuario_id`)
      REFERENCES `usuarios`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- INSTRUCTOR_ALIAS: memoria global  nombre_del_sheet (normalizado) -> usuario.
-- Una vez que un nombre del calendario se enlaza a un usuario, se recuerda para
-- TODAS las fichas: elimina el mapeo manual repetido de los instructores que se
-- repiten entre fichas. La rellenan MapearInstructorFicha.php (enlace manual) y
-- el auto-match de instructor_match.php (coincidencia única por nombre).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `instructor_alias` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `nombre_norm` VARCHAR(150) NOT NULL,           -- "CARLOS QUINTERO" en MAYÚSCULAS y sin acentos
  `usuario_id`  INT          NOT NULL,
  `creado_en`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_alias_nombre` (`nombre_norm`),
  CONSTRAINT `fk_alias_usuario` FOREIGN KEY (`usuario_id`)
      REFERENCES `usuarios`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- HISTORIAL_OCUPACION: columnas nuevas para distinguir el origen y guardar
-- la ficha y la competencia. Las ocupaciones manuales existentes no se tocan.
-- ---------------------------------------------------------------------------
-- (Se usan sentencias tolerantes: si la columna ya existe, ignora el error al re-ejecutar.)
ALTER TABLE `historial_ocupacion`
  ADD COLUMN `ficha_id`    INT          NULL AFTER `usuario_id`,
  ADD COLUMN `competencia` VARCHAR(255) NULL AFTER `observaciones`,
  ADD COLUMN `origen`      ENUM('manual','sync') NOT NULL DEFAULT 'manual' AFTER `competencia`;

ALTER TABLE `historial_ocupacion`
  ADD CONSTRAINT `fk_ocup_ficha` FOREIGN KEY (`ficha_id`)
      REFERENCES `fichas`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `historial_ocupacion`
  ADD INDEX `idx_ocup_ficha`  (`ficha_id`),
  ADD INDEX `idx_ocup_origen` (`origen`);
