-- ============================================================================
--  SAGA v2 · Migración: hash del Google Sheet para acelerar la sincronización
--  Guarda una huella (sha1) del último .xlsx importado por ficha. Si al
--  sincronizar el archivo no cambió, se salta el parseo y la reinserción.
--  Importar con:  mysql --default-character-set=utf8mb4 -u root saga_v2 < sql/migracion_sheet_hash.sql
-- ============================================================================
USE `saga_v2`;
SET NAMES utf8mb4;

ALTER TABLE `fichas` ADD COLUMN `sheet_hash` CHAR(40) NULL AFTER `ultima_sync`;
