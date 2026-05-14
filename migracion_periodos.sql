-- ============================================================
-- MIGRACIÓN: Programación de Pabellones por Período
-- Ejecutar en phpMyAdmin sobre pabellones_db
-- ============================================================

USE `pabellones_db`;

-- ── Tabla: periodos_pabellon ──────────────────────────────
-- Define un período de disponibilidad programada
CREATE TABLE IF NOT EXISTS `periodos_pabellon` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_institucion`  INT UNSIGNED NOT NULL,
  `nombre`          VARCHAR(120) NOT NULL        COMMENT 'Ej: Semana 18 - Mayo 2026',
  `fecha_inicio`    DATE NOT NULL,
  `fecha_fin`       DATE NOT NULL,
  `estado`          ENUM('borrador','publicado','cerrado') NOT NULL DEFAULT 'borrador',
  `observacion`     VARCHAR(300) DEFAULT NULL,
  `id_admin`        INT UNSIGNED NOT NULL        COMMENT 'Quien creó el período',
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_per_inst`  (`id_institucion`),
  KEY `fk_per_admin` (`id_admin`),
  CONSTRAINT `fk_per_inst`  FOREIGN KEY (`id_institucion`) REFERENCES `instituciones` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_per_admin` FOREIGN KEY (`id_admin`)       REFERENCES `usuarios` (`id`)      ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Tabla: periodo_pabellon_detalle ───────────────────────
-- Pabellones disponibles dentro de un período, con horarios por día
CREATE TABLE IF NOT EXISTS `periodo_pabellon_detalle` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_periodo`      INT UNSIGNED NOT NULL,
  `id_pabellon`     INT UNSIGNED NOT NULL,
  `dia_semana`      TINYINT UNSIGNED NOT NULL    COMMENT '1=Lun 2=Mar 3=Mié 4=Jue 5=Vie 6=Sáb 7=Dom',
  `hora_inicio`     TIME NOT NULL                COMMENT 'Hora de apertura del pabellón',
  `hora_fin`        TIME NOT NULL                COMMENT 'Hora de cierre del pabellón',
  `cupos_max`       TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Máximo de operaciones simultáneas',
  `activo`          TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_periodo_pab_dia` (`id_periodo`, `id_pabellon`, `dia_semana`),
  KEY `fk_ppd_periodo`   (`id_periodo`),
  KEY `fk_ppd_pabellon`  (`id_pabellon`),
  CONSTRAINT `fk_ppd_periodo`  FOREIGN KEY (`id_periodo`)  REFERENCES `periodos_pabellon` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ppd_pabellon` FOREIGN KEY (`id_pabellon`) REFERENCES `pabellones` (`id`)        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'Tablas de programación creadas correctamente' AS resultado;
SHOW TABLES LIKE 'periodo%';
