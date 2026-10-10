-- Ejecutar sobre la base de datos configurada para SAGA.
-- Crea la tabla faltante sin reemplazar tablas ni registros existentes.
CREATE TABLE IF NOT EXISTS `notificaciones` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `usuario_id` INT NOT NULL,
  `tipo` VARCHAR(50) NOT NULL DEFAULT 'info',
  `titulo` VARCHAR(150) NOT NULL,
  `mensaje` VARCHAR(500) NOT NULL,
  `url` VARCHAR(255) DEFAULT NULL,
  `leida` TINYINT(1) NOT NULL DEFAULT 0,
  `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_usuario` (`usuario_id`),
  KEY `idx_leida` (`usuario_id`, `leida`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
