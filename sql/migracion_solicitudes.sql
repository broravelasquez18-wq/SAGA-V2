-- ============================================================================
--  SAGA v2 · Migración: Solicitudes de cancelación de ocupaciones
--  El instructor SOLICITA cancelar una ocupación futura; el admin aprueba/rechaza.
--  Importar con:  mysql --default-character-set=utf8mb4 -u root saga_v2 < sql/migracion_solicitudes.sql
-- ============================================================================
USE `saga_v2`;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `solicitudes_cancelacion` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `ocupacion_id`  INT          NOT NULL,                 -- historial_ocupacion.id
  `instructor_id` INT          NOT NULL,                 -- usuarios.id (quien solicita)
  `motivo`        VARCHAR(500) NOT NULL,                 -- obligatorio
  `adjunto`       VARCHAR(255) NULL,
  `estado`        ENUM('pendiente','aprobada','rechazada') NOT NULL DEFAULT 'pendiente',
  `respuesta`     VARCHAR(500) NULL,                     -- nota opcional del admin
  `resuelto_por`  INT          NULL,                     -- usuarios.id (admin)
  `creado_en`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `resuelto_en`   DATETIME     NULL,
  CONSTRAINT `fk_solc_ocup`  FOREIGN KEY (`ocupacion_id`)  REFERENCES `historial_ocupacion`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_solc_instr` FOREIGN KEY (`instructor_id`) REFERENCES `usuarios`(`id`)            ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_solc_admin` FOREIGN KEY (`resuelto_por`)  REFERENCES `usuarios`(`id`)            ON DELETE SET NULL ON UPDATE CASCADE,
  INDEX `idx_solc_estado` (`estado`),
  INDEX `idx_solc_ocup` (`ocupacion_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
