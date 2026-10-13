-- ============================================================================
--  SAGA v2 · Migración: registro de llegada del instructor (check-in)
--  El celador confirma que el instructor llegó a su ambiente (escaneando el
--  carnet o con un botón). Se guarda la hora, quién la confirmó y si ya se
--  avisó al admin de que no había llegado.
--  Importar con:  mysql --default-character-set=utf8mb4 -u root saga_v2 < sql/migracion_llegada.sql
-- ============================================================================
SET NAMES utf8mb4;

ALTER TABLE `historial_ocupacion`
  ADD COLUMN `llegada_en`      DATETIME   NULL AFTER `creado_en`,
  ADD COLUMN `llegada_por`     INT        NULL AFTER `llegada_en`,
  ADD COLUMN `alerta_no_llego` TINYINT(1) NOT NULL DEFAULT 0 AFTER `llegada_por`,
  ADD INDEX `idx_ocup_llegada` (`llegada_en`);

-- Las clases que ya empezaron antes de esta función no pudieron registrar
-- llegada: se marcan como "ya avisadas" para no enviar alertas falsas el
-- primer día. Solo se vigilan las clases que empiecen después del despliegue.
-- (Ajustar la fecha/hora al momento real de aplicar la migración.)
UPDATE historial_ocupacion SET alerta_no_llego = 2   -- 2 = anterior a la función (no es una alerta real)
 WHERE llegada_en IS NULL AND fecha_inicio <= '2026-10-13 14:43:47';
