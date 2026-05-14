-- ============================================================
-- SISTEMA DE ADMINISTRACIÓN DE PABELLONES QUIRÚRGICOS
-- Base de Datos: pabellones_db
-- Motor: MySQL 5.7+ | Compatible XAMPP
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";
SET NAMES utf8mb4;

CREATE DATABASE IF NOT EXISTS `pabellones_db`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `pabellones_db`;

-- ============================================================
-- TABLA: instituciones
-- Hospitales o clínicas registradas en el sistema
-- ============================================================
CREATE TABLE `instituciones` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre`       VARCHAR(150) NOT NULL,
  `tipo`         ENUM('hospital','clinica') NOT NULL DEFAULT 'hospital',
  `rut`          VARCHAR(20) NOT NULL,
  `direccion`    VARCHAR(200) DEFAULT NULL,
  `ciudad`       VARCHAR(100) DEFAULT NULL,
  `region`       VARCHAR(100) DEFAULT NULL,
  `telefono`     VARCHAR(20) DEFAULT NULL,
  `email`        VARCHAR(120) DEFAULT NULL,
  `logo`         VARCHAR(255) DEFAULT NULL,
  `activo`       TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_institucion_rut` (`rut`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: roles
-- Roles del sistema
-- ============================================================
CREATE TABLE `roles` (
  `id`          TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre`      VARCHAR(60) NOT NULL,
  `descripcion` VARCHAR(200) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rol_nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `roles` (`nombre`, `descripcion`) VALUES
  ('superadmin',     'Acceso total al sistema. Gestiona todas las instituciones.'),
  ('admin_hospital', 'Administrador de pabellones de su institución.'),
  ('medico',         'Cirujano que solicita y ve su agenda de pabellones.');

-- ============================================================
-- TABLA: usuarios
-- Todos los usuarios del sistema
-- ============================================================
CREATE TABLE `usuarios` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_institucion`   INT UNSIGNED DEFAULT NULL COMMENT 'NULL solo para superadmin',
  `id_rol`           TINYINT UNSIGNED NOT NULL,
  `rut`              VARCHAR(12) NOT NULL,
  `nombres`          VARCHAR(100) NOT NULL,
  `apellidos`        VARCHAR(100) NOT NULL,
  `email`            VARCHAR(120) NOT NULL,
  `password_hash`    VARCHAR(255) NOT NULL,
  `especialidad`     VARCHAR(120) DEFAULT NULL COMMENT 'Solo para médicos',
  `telefono`         VARCHAR(20) DEFAULT NULL,
  `activo`           TINYINT(1) NOT NULL DEFAULT 1,
  `token_recupera`   VARCHAR(100) DEFAULT NULL,
  `token_expira`     DATETIME DEFAULT NULL,
  `ultimo_acceso`    DATETIME DEFAULT NULL,
  `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_usuario_rut` (`rut`),
  UNIQUE KEY `uq_usuario_email` (`email`),
  KEY `fk_usuario_institucion` (`id_institucion`),
  KEY `fk_usuario_rol` (`id_rol`),
  CONSTRAINT `fk_usuario_institucion` FOREIGN KEY (`id_institucion`) REFERENCES `instituciones` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_usuario_rol`         FOREIGN KEY (`id_rol`)         REFERENCES `roles` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: acreditaciones
-- Médico externo acreditado en una institución
-- ============================================================
CREATE TABLE `acreditaciones` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_medico`      INT UNSIGNED NOT NULL,
  `id_institucion` INT UNSIGNED NOT NULL,
  `fecha_inicio`   DATE NOT NULL,
  `fecha_fin`      DATE DEFAULT NULL COMMENT 'NULL = indefinida',
  `activo`         TINYINT(1) NOT NULL DEFAULT 1,
  `observacion`    VARCHAR(255) DEFAULT NULL,
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_acreditacion` (`id_medico`, `id_institucion`),
  KEY `fk_acred_institucion` (`id_institucion`),
  CONSTRAINT `fk_acred_medico`      FOREIGN KEY (`id_medico`)      REFERENCES `usuarios` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_acred_institucion` FOREIGN KEY (`id_institucion`) REFERENCES `instituciones` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: tipos_pabellon
-- Clasificación de pabellones quirúrgicos
-- ============================================================
CREATE TABLE `tipos_pabellon` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_institucion` INT UNSIGNED NOT NULL,
  `nombre`         VARCHAR(100) NOT NULL,
  `descripcion`    VARCHAR(255) DEFAULT NULL,
  `color_hex`      VARCHAR(7) NOT NULL DEFAULT '#3788d8' COMMENT 'Color en calendario',
  `activo`         TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_tpab_institucion` (`id_institucion`),
  CONSTRAINT `fk_tpab_institucion` FOREIGN KEY (`id_institucion`) REFERENCES `instituciones` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: pabellones
-- Pabellones físicos por institución
-- ============================================================
CREATE TABLE `pabellones` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_institucion`   INT UNSIGNED NOT NULL,
  `id_tipo_pabellon` INT UNSIGNED NOT NULL,
  `nombre`           VARCHAR(80) NOT NULL,
  `numero`           VARCHAR(10) DEFAULT NULL,
  `piso`             VARCHAR(20) DEFAULT NULL,
  `capacidad_desc`   VARCHAR(150) DEFAULT NULL,
  `activo`           TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_pab_institucion`  (`id_institucion`),
  KEY `fk_pab_tipo`         (`id_tipo_pabellon`),
  CONSTRAINT `fk_pab_institucion` FOREIGN KEY (`id_institucion`)   REFERENCES `instituciones` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_pab_tipo`        FOREIGN KEY (`id_tipo_pabellon`) REFERENCES `tipos_pabellon` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: tipos_cirugia
-- Clasificación de cirugías
-- ============================================================
CREATE TABLE `tipos_cirugia` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_institucion`   INT UNSIGNED NOT NULL,
  `id_tipo_pabellon` INT UNSIGNED NOT NULL COMMENT 'Pabellón requerido para esta cirugía',
  `nombre`           VARCHAR(150) NOT NULL,
  `descripcion`      VARCHAR(300) DEFAULT NULL,
  `duracion_min`     SMALLINT UNSIGNED NOT NULL DEFAULT 60 COMMENT 'Duración estimada en minutos',
  `activo`           TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_tcirug_institucion`  (`id_institucion`),
  KEY `fk_tcirug_tipo_pabellon` (`id_tipo_pabellon`),
  CONSTRAINT `fk_tcirug_institucion`   FOREIGN KEY (`id_institucion`)   REFERENCES `instituciones` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_tcirug_tipo_pabellon` FOREIGN KEY (`id_tipo_pabellon`) REFERENCES `tipos_pabellon` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: tipos_insumo
-- Categorías de insumos quirúrgicos
-- ============================================================
CREATE TABLE `tipos_insumo` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_institucion` INT UNSIGNED NOT NULL,
  `nombre`         VARCHAR(100) NOT NULL,
  `descripcion`    VARCHAR(200) DEFAULT NULL,
  `activo`         TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_tinsumo_institucion` (`id_institucion`),
  CONSTRAINT `fk_tinsumo_institucion` FOREIGN KEY (`id_institucion`) REFERENCES `instituciones` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: insumos
-- Insumos individuales
-- ============================================================
CREATE TABLE `insumos` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_institucion`  INT UNSIGNED NOT NULL,
  `id_tipo_insumo`  INT UNSIGNED NOT NULL,
  `nombre`          VARCHAR(150) NOT NULL,
  `descripcion`     VARCHAR(255) DEFAULT NULL,
  `unidad_medida`   VARCHAR(30) DEFAULT NULL,
  `activo`          TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_insumo_institucion` (`id_institucion`),
  KEY `fk_insumo_tipo`        (`id_tipo_insumo`),
  CONSTRAINT `fk_insumo_institucion` FOREIGN KEY (`id_institucion`) REFERENCES `instituciones` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_insumo_tipo`        FOREIGN KEY (`id_tipo_insumo`) REFERENCES `tipos_insumo` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: cirugia_insumos
-- Insumos asociados a un tipo de cirugía (con cantidad)
-- ============================================================
CREATE TABLE `cirugia_insumos` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_tipo_cirugia` INT UNSIGNED NOT NULL,
  `id_insumo`       INT UNSIGNED NOT NULL,
  `cantidad`        DECIMAL(8,2) NOT NULL DEFAULT 1,
  `observacion`     VARCHAR(200) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cirugia_insumo` (`id_tipo_cirugia`, `id_insumo`),
  KEY `fk_ci_insumo` (`id_insumo`),
  CONSTRAINT `fk_ci_tipo_cirugia` FOREIGN KEY (`id_tipo_cirugia`) REFERENCES `tipos_cirugia` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ci_insumo`       FOREIGN KEY (`id_insumo`)       REFERENCES `insumos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: precios_cirugia
-- Tarifas vigentes por tipo de cirugía
-- ============================================================
CREATE TABLE `precios_cirugia` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_institucion`  INT UNSIGNED NOT NULL,
  `id_tipo_cirugia` INT UNSIGNED NOT NULL,
  `precio_neto`     DECIMAL(12,2) NOT NULL,
  `moneda`          VARCHAR(5) NOT NULL DEFAULT 'CLP',
  `vigente_desde`   DATE NOT NULL,
  `vigente_hasta`   DATE DEFAULT NULL COMMENT 'NULL = vigente actualmente',
  `observacion`     VARCHAR(200) DEFAULT NULL,
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_precio_institucion`  (`id_institucion`),
  KEY `fk_precio_tipo_cirugia` (`id_tipo_cirugia`),
  CONSTRAINT `fk_precio_institucion`  FOREIGN KEY (`id_institucion`)  REFERENCES `instituciones` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_precio_tipo_cirugia` FOREIGN KEY (`id_tipo_cirugia`) REFERENCES `tipos_cirugia` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: equipo_quirurgico
-- Integrantes del equipo de un cirujano
-- ============================================================
CREATE TABLE `equipo_quirurgico` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_medico`    INT UNSIGNED NOT NULL COMMENT 'Cirujano principal',
  `id_miembro`   INT UNSIGNED NOT NULL COMMENT 'Usuario del equipo',
  `rol_equipo`   VARCHAR(80) NOT NULL COMMENT 'Ej: anestesista, arsenalera, ayudante',
  `activo`       TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_equipo` (`id_medico`, `id_miembro`),
  KEY `fk_eq_miembro` (`id_miembro`),
  CONSTRAINT `fk_eq_medico`   FOREIGN KEY (`id_medico`)  REFERENCES `usuarios` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_eq_miembro`  FOREIGN KEY (`id_miembro`) REFERENCES `usuarios` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: solicitudes_pabellon
-- Solicitudes de asignación de pabellón
-- ============================================================
CREATE TABLE `solicitudes_pabellon` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_institucion`   INT UNSIGNED NOT NULL,
  `id_medico`        INT UNSIGNED NOT NULL,
  `id_tipo_cirugia`  INT UNSIGNED NOT NULL,
  `id_pabellon`      INT UNSIGNED DEFAULT NULL COMMENT 'Asignado por el admin',
  `fecha_solicitada` DATE NOT NULL,
  `hora_inicio`      TIME NOT NULL,
  `hora_fin`         TIME NOT NULL,
  `paciente_nombre`  VARCHAR(150) DEFAULT NULL,
  `paciente_rut`     VARCHAR(12) DEFAULT NULL,
  `diagnostico`      VARCHAR(300) DEFAULT NULL,
  `estado`           ENUM('pendiente','aprobada','rechazada','cancelada','completada') NOT NULL DEFAULT 'pendiente',
  `observacion`      VARCHAR(400) DEFAULT NULL,
  `id_admin_gestion` INT UNSIGNED DEFAULT NULL COMMENT 'Quien aprobó/rechazó',
  `fecha_gestion`    DATETIME DEFAULT NULL,
  `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_sol_institucion`  (`id_institucion`),
  KEY `fk_sol_medico`       (`id_medico`),
  KEY `fk_sol_tipo_cirugia` (`id_tipo_cirugia`),
  KEY `fk_sol_pabellon`     (`id_pabellon`),
  KEY `fk_sol_admin`        (`id_admin_gestion`),
  CONSTRAINT `fk_sol_institucion`  FOREIGN KEY (`id_institucion`)  REFERENCES `instituciones` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_sol_medico`       FOREIGN KEY (`id_medico`)       REFERENCES `usuarios` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_sol_tipo_cirugia` FOREIGN KEY (`id_tipo_cirugia`) REFERENCES `tipos_cirugia` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_sol_pabellon`     FOREIGN KEY (`id_pabellon`)     REFERENCES `pabellones` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_sol_admin`        FOREIGN KEY (`id_admin_gestion`) REFERENCES `usuarios` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: bloqueos_pabellon
-- Bloqueos temporales de pabellón
-- ============================================================
CREATE TABLE `bloqueos_pabellon` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_pabellon`     INT UNSIGNED NOT NULL,
  `id_admin`        INT UNSIGNED NOT NULL,
  `fecha_inicio`    DATETIME NOT NULL,
  `fecha_fin`       DATETIME NOT NULL,
  `motivo`          VARCHAR(255) NOT NULL,
  `activo`          TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_bloqueo_pabellon` (`id_pabellon`),
  KEY `fk_bloqueo_admin`    (`id_admin`),
  CONSTRAINT `fk_bloqueo_pabellon` FOREIGN KEY (`id_pabellon`) REFERENCES `pabellones` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_bloqueo_admin`    FOREIGN KEY (`id_admin`)    REFERENCES `usuarios` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: ofertas_pabellon
-- Espacios disponibles publicados por el administrador
-- ============================================================
CREATE TABLE `ofertas_pabellon` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_pabellon`    INT UNSIGNED NOT NULL,
  `id_admin`       INT UNSIGNED NOT NULL,
  `fecha_oferta`   DATE NOT NULL,
  `hora_inicio`    TIME NOT NULL,
  `hora_fin`       TIME NOT NULL,
  `descripcion`    VARCHAR(300) DEFAULT NULL,
  `estado`         ENUM('disponible','reservada','vencida') NOT NULL DEFAULT 'disponible',
  `id_solicitud`   INT UNSIGNED DEFAULT NULL COMMENT 'Solicitud que la tomó',
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_oferta_pabellon`  (`id_pabellon`),
  KEY `fk_oferta_admin`     (`id_admin`),
  KEY `fk_oferta_solicitud` (`id_solicitud`),
  CONSTRAINT `fk_oferta_pabellon`  FOREIGN KEY (`id_pabellon`)  REFERENCES `pabellones` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_oferta_admin`     FOREIGN KEY (`id_admin`)     REFERENCES `usuarios` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_oferta_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitudes_pabellon` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: audit_log
-- Registro de acciones críticas del sistema
-- ============================================================
CREATE TABLE `audit_log` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_usuario`   INT UNSIGNED DEFAULT NULL,
  `accion`       VARCHAR(100) NOT NULL,
  `tabla`        VARCHAR(60) DEFAULT NULL,
  `registro_id`  INT UNSIGNED DEFAULT NULL,
  `detalle`      TEXT DEFAULT NULL,
  `ip`           VARCHAR(45) DEFAULT NULL,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_usuario` (`id_usuario`),
  KEY `idx_audit_fecha`   (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- DATOS INICIALES
-- Institución y superadmin por defecto
-- ============================================================

INSERT INTO `instituciones` (`nombre`, `tipo`, `rut`, `ciudad`, `region`) VALUES
  ('Hospital Central Demo', 'hospital', '61.000.000-0', 'Santiago', 'Región Metropolitana');

-- Superadmin: usuario=admin@sistema.cl  clave=Admin1234*
-- Hash generado con password_hash('Admin1234*', PASSWORD_BCRYPT)
INSERT INTO `usuarios`
  (`id_institucion`, `id_rol`, `rut`, `nombres`, `apellidos`, `email`, `password_hash`, `activo`)
VALUES
  (NULL, 1, '11.111.111-1', 'Super', 'Administrador', 'admin@sistema.cl',
   '$2y$12$wq3S7D1OgDL.wkFRqHOcBuH6F8P5l4bkh3VJZ7XmQY9c6rE5T8OTu', 1);

-- Admin del hospital demo: usuario=adminhospital@demo.cl  clave=Hospital2024*
INSERT INTO `usuarios`
  (`id_institucion`, `id_rol`, `rut`, `nombres`, `apellidos`, `email`, `password_hash`, `activo`)
VALUES
  (1, 2, '22.222.222-2', 'Admin', 'Hospital', 'adminhospital@demo.cl',
   '$2y$12$K2xLmN8P3qRsTuV5wXyZaO1bCdEfGhIjJkLmNoPqRsTuVwXyZaObCd', 1);

-- Médico demo: usuario=medico@demo.cl  clave=Medico2024*
INSERT INTO `usuarios`
  (`id_institucion`, `id_rol`, `rut`, `nombres`, `apellidos`, `email`, `password_hash`, `especialidad`, `activo`)
VALUES
  (1, 3, '33.333.333-3', 'Carlos', 'González Rojas', 'medico@demo.cl',
   '$2y$12$A1bCdEfGhIjJkLmNoPqRsTuVwXyZaObCdEfGhIjJkLmNoPqRsTuVw', 'Cirugía General', 1);

-- ============================================================
-- NOTA: Para regenerar los hashes de contraseña, ejecutar en PHP:
--   echo password_hash('Admin1234*',   PASSWORD_BCRYPT);
--   echo password_hash('Hospital2024*', PASSWORD_BCRYPT);
--   echo password_hash('Medico2024*',   PASSWORD_BCRYPT);
-- Y actualizar los registros en la tabla usuarios.
-- ============================================================
