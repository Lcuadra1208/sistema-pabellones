-- ============================================================
-- MIGRACIÓN: Tabla de precios por Tipo de Pabellón
-- Ejecutar en phpMyAdmin sobre pabellones_db
-- ============================================================

USE `pabellones_db`;

CREATE TABLE IF NOT EXISTS `precios_tipo_pabellon` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_institucion`    INT UNSIGNED NOT NULL,
  `id_tipo_pabellon`  INT UNSIGNED NOT NULL,
  `precio_hora`       DECIMAL(12,2) NOT NULL        COMMENT 'Precio por hora de uso',
  `precio_media_hora` DECIMAL(12,2) DEFAULT NULL    COMMENT 'Precio por media hora (opcional)',
  `moneda`            VARCHAR(5)  NOT NULL DEFAULT 'CLP',
  `vigente_desde`     DATE NOT NULL,
  `vigente_hasta`     DATE DEFAULT NULL             COMMENT 'NULL = tarifa actualmente vigente',
  `observacion`       VARCHAR(200) DEFAULT NULL,
  `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_ptp_institucion`   (`id_institucion`),
  KEY `fk_ptp_tipo_pabellon` (`id_tipo_pabellon`),
  CONSTRAINT `fk_ptp_institucion`   FOREIGN KEY (`id_institucion`)
      REFERENCES `instituciones` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_ptp_tipo_pabellon` FOREIGN KEY (`id_tipo_pabellon`)
      REFERENCES `tipos_pabellon` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'Tabla precios_tipo_pabellon creada correctamente' AS resultado;
