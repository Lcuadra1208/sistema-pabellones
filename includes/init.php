<?php
/**
 * init.php — punto de entrada global
 * Incluir al inicio de cada página PHP
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/helpers.php';

session_iniciar();
