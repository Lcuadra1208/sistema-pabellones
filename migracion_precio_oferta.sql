-- ============================================================
-- MIGRACIÓN: Campos de precio en ofertas_pabellon
-- Ejecutar en phpMyAdmin sobre pabellones_db
-- ============================================================

USE `pabellones_db`;

ALTER TABLE `ofertas_pabellon`
    ADD COLUMN `precio_normal`    DECIMAL(12,2) DEFAULT NULL COMMENT 'Precio normal del tipo de pabellón (tarifa vigente al crear la oferta)',
    ADD COLUMN `precio_oferta`    DECIMAL(12,2) DEFAULT NULL COMMENT 'Precio final de la oferta',
    ADD COLUMN `descuento_pct`    DECIMAL(5,2)  DEFAULT NULL COMMENT 'Porcentaje de descuento aplicado',
    ADD COLUMN `modo_precio`      ENUM('porcentaje','valor') DEFAULT NULL COMMENT 'Cómo se calculó el precio de oferta';

SELECT 'Campos de precio agregados a ofertas_pabellon' AS resultado;
