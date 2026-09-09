-- ============================================================================
--  SAGA v2 · Migración: Ocupación del ambiente por el vocero vía SOLICITUD
--  Flujo A (inicio): el VOCERO solicita ocupar AHORA el ambiente fijo de su ficha
--                    (cuando el instructor se demora). El CELADOR o el ADMIN
--                    aprueban/rechazan; al aprobar se crea la ocupación.
--  Flujo B (fin):    el VOCERO solicita finalizar; el CELADOR (presente) aprueba.
--  Importar con:  mysql --default-character-set=utf8mb4 -u root saga_v2 < sql/migracion_recepcion_vocero.sql
-- ============================================================================
USE `saga_v2`;
SET NAMES utf8mb4;

-- Limpieza de un diseño anterior (recepción por SMS/link), si existiera.
DROP TABLE IF EXISTS `recepciones`;

-- ---------------------------------------------------------------------------
-- SOLICITUDES_OCUPACION: el vocero pide ocupar el ambiente de su ficha; el
-- celador o el admin aprueban. Al aprobar se inserta la ocupación real.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `solicitudes_ocupacion` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `ficha_id`      INT      NOT NULL,
  `vocero_id`     INT      NOT NULL,                          -- usuarios.id (quien solicita)
  `ambiente_id`   INT      NOT NULL,                          -- ambiente fijo de la ficha
  `jornada`       ENUM('mañana','tarde','noche','personalizado') NULL,
  `fecha_inicio`  DATETIME NOT NULL,
  `fecha_fin`     DATETIME NOT NULL,
  `motivo`        VARCHAR(500) NULL,                          -- por qué el vocero solicita ocupar
  `estado`        ENUM('pendiente','aprobada','rechazada') NOT NULL DEFAULT 'pendiente',
  `resuelto_por`  INT      NULL,                              -- usuarios.id (celador o admin)
  `resuelto_en`   DATETIME NULL,
  `ocupacion_id`  INT      NULL,                              -- historial_ocupacion creado al aprobar
  `creado_en`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_solo_estado` (`estado`),
  INDEX `idx_solo_ficha`  (`ficha_id`),
  CONSTRAINT `fk_solo_ficha`    FOREIGN KEY (`ficha_id`)     REFERENCES `fichas`(`id`)               ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_solo_vocero`   FOREIGN KEY (`vocero_id`)    REFERENCES `usuarios`(`id`)             ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_solo_ambiente` FOREIGN KEY (`ambiente_id`)  REFERENCES `ambientes`(`id`)            ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_solo_resuelto` FOREIGN KEY (`resuelto_por`) REFERENCES `usuarios`(`id`)             ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_solo_ocup`     FOREIGN KEY (`ocupacion_id`) REFERENCES `historial_ocupacion`(`id`)  ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- SOLICITUDES_FINALIZACION: el vocero pide finalizar; el celador aprueba/rechaza.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `solicitudes_finalizacion` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `ocupacion_id` INT      NOT NULL,
  `vocero_id`    INT      NOT NULL,
  `estado`       ENUM('pendiente','aprobada','rechazada') NOT NULL DEFAULT 'pendiente',
  `resuelto_por` INT      NULL,
  `creado_en`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `resuelto_en`  DATETIME NULL,
  INDEX `idx_solf_estado` (`estado`),
  INDEX `idx_solf_ocup`   (`ocupacion_id`),
  CONSTRAINT `fk_solf_ocup`    FOREIGN KEY (`ocupacion_id`) REFERENCES `historial_ocupacion`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_solf_vocero`  FOREIGN KEY (`vocero_id`)    REFERENCES `usuarios`(`id`)             ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_solf_celador` FOREIGN KEY (`resuelto_por`) REFERENCES `usuarios`(`id`)             ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
