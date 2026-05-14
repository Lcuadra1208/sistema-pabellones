-- ============================================================
-- MIGRACIÓN: Rol Mantención y control de sanitización
-- Ejecutar en phpMyAdmin sobre pabellones_db
-- ============================================================

USE `pabellones_db`;

-- ── Nuevo rol: mantención ─────────────────────────────────
INSERT IGNORE INTO `roles` (`nombre`, `descripcion`) VALUES
  ('mantencion', 'Personal de mantención: marca cirugías completadas y libera pabellones tras sanitización.');

-- ── Columnas adicionales en solicitudes_pabellon ─────────
ALTER TABLE `solicitudes_pabellon`
  ADD COLUMN IF NOT EXISTS `hora_real_fin`     TIME     DEFAULT NULL COMMENT 'Hora real de término de la operación',
  ADD COLUMN IF NOT EXISTS `sanitizacion_ini`  DATETIME DEFAULT NULL COMMENT 'Inicio sanitización pabellón',
  ADD COLUMN IF NOT EXISTS `sanitizacion_fin`  DATETIME DEFAULT NULL COMMENT 'Fin sanitización — pabellón libre',
  ADD COLUMN IF NOT EXISTS `id_mantencion`     INT UNSIGNED DEFAULT NULL COMMENT 'Quién registró la sanitización',
  ADD COLUMN IF NOT EXISTS `obs_mantencion`    VARCHAR(300) DEFAULT NULL;

-- FK solo si no existe ya (compatible con MariaDB/MySQL 5.7+)
SET @fk_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'solicitudes_pabellon'
      AND CONSTRAINT_NAME = 'fk_sol_mantencion'
);
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE solicitudes_pabellon ADD CONSTRAINT fk_sol_mantencion FOREIGN KEY (id_mantencion) REFERENCES usuarios (id) ON UPDATE CASCADE',
    'SELECT "FK ya existe" AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT 'Migración mantención completada' AS resultado;
