-- ============================================================================
-- Migración: nombre completo en un solo campo para INSTRUCTORES
-- ----------------------------------------------------------------------------
-- Consolida la columna `apellido` dentro de `nombre` para los usuarios con
-- rol 'instructor' y deja `apellido = NULL`. Solo afecta instructores; los
-- demás roles (admin, celador, vocero) conservan su apellido separado.
--
-- La columna `apellido` NO se elimina porque otros módulos (celadores,
-- voceros, reportes, ocupaciones) la siguen usando.
--
-- Es idempotente: volver a ejecutarla no cambia filas ya migradas.
-- ============================================================================

UPDATE usuarios
SET nombre   = TRIM(CONCAT(nombre, ' ', COALESCE(apellido, ''))),
    apellido = NULL
WHERE rol = 'instructor'
  AND apellido IS NOT NULL
  AND TRIM(apellido) <> '';
