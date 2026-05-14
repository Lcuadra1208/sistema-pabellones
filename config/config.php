<?php
/**
 * Configuración general del Sistema de Pabellones
 * Ajustar según el entorno XAMPP local o producción
 */

// ── Entorno ──────────────────────────────────────────────
define('APP_NAME',    'SistemaPabellones');
define('APP_VERSION', '1.0.0');
define('APP_URL',     'http://localhost/pabellones');
define('APP_ROOT',    dirname(__DIR__));
define('AMBIENTE',    'desarrollo'); // 'desarrollo' | 'produccion'

// ── Base de datos ─────────────────────────────────────────
define('DB_HOST',    'localhost');
define('DB_PORT',    3306);
define('DB_NAME',    'pabellones_db');
define('DB_USER',    'root');
define('DB_PASS',    '');          // Ajustar si XAMPP tiene clave
define('DB_CHARSET', 'utf8mb4');

// ── Sesión ────────────────────────────────────────────────
define('SESSION_LIFETIME', 3600);  // segundos (1 hora)
define('SESSION_NAME',     'PAB_SESSION');

// ── Correo (para recuperación de clave) ──────────────────
define('MAIL_FROM',     'no-reply@pabellones.cl');
define('MAIL_FROM_NAME','Sistema Pabellones');
define('MAIL_HOST',     'smtp.mailtrap.io');  // Cambiar en producción
define('MAIL_PORT',     587);
define('MAIL_USER',     '');
define('MAIL_PASS',     '');
define('MAIL_SECURE',   'tls');

// ── Roles (IDs según tabla `roles`) ──────────────────────
define('ROL_SUPERADMIN',    1);
define('ROL_ADMIN_HOSPITAL', 2);
define('ROL_MEDICO',         3);
define('ROL_MANTENCION',     4);

// ── Configuración de errores ──────────────────────────────
if (AMBIENTE === 'desarrollo') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', APP_ROOT . '/logs/php_errors.log');
}

// Zona horaria Chile continental
date_default_timezone_set('America/Santiago');
