-- Descripción breve del celador al registrar la llegada y si se verificó con el carnet.
ALTER TABLE `historial_ocupacion`
  ADD COLUMN `llegada_observacion` VARCHAR(255) NULL AFTER `llegada_por`,
  ADD COLUMN `llegada_carnet` TINYINT(1) NOT NULL DEFAULT 0 AFTER `llegada_observacion`;
