-- ============================================================
-- MIGRACIÓN: Configuración email Brevo, logos y historial
-- Ejecutar en phpMyAdmin sobre pabellones_db
-- ============================================================

USE `pabellones_db`;

-- ── Configuración global del sistema (solo superadmin) ────
CREATE TABLE IF NOT EXISTS `configuracion_sistema` (
  `clave`       VARCHAR(80)  NOT NULL,
  `valor`       TEXT         DEFAULT NULL,
  `descripcion` VARCHAR(200) DEFAULT NULL,
  `updated_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`clave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Valores por defecto para Brevo
INSERT IGNORE INTO `configuracion_sistema` (`clave`, `valor`, `descripcion`) VALUES
  ('brevo_api_key',    '',                    'API Key de Brevo para envío de emails'),
  ('brevo_from_email', 'no-reply@sistema.cl', 'Correo remitente'),
  ('brevo_from_name',  'SistemaPabellones',   'Nombre del remitente'),
  ('email_activo',     '0',                   '1 = envío de email habilitado');

-- ── Historial de cambios en solicitudes ───────────────────
CREATE TABLE IF NOT EXISTS `solicitudes_historial` (
  `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `id_solicitud`  INT UNSIGNED  NOT NULL,
  `id_usuario`    INT UNSIGNED  NOT NULL,
  `estado_antes`  VARCHAR(20)   DEFAULT NULL,
  `estado_despues`VARCHAR(20)   DEFAULT NULL,
  `id_pabellon_antes`  INT UNSIGNED DEFAULT NULL,
  `id_pabellon_despues`INT UNSIGNED DEFAULT NULL,
  `observacion`   VARCHAR(400)  DEFAULT NULL,
  `created_at`    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_hist_solicitud` (`id_solicitud`),
  KEY `fk_hist_usuario`   (`id_usuario`),
  CONSTRAINT `fk_hist_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitudes_pabellon` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_hist_usuario`   FOREIGN KEY (`id_usuario`)   REFERENCES `usuarios` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Logo por institución ──────────────────────────────────
-- (columna logo ya existe en instituciones, solo verificar)
ALTER TABLE `instituciones`
  MODIFY COLUMN `logo` VARCHAR(255) DEFAULT NULL COMMENT 'Ruta relativa al logo subido';

SELECT 'Migración completada' AS resultado;
