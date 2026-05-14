<?php
// logout.php
require_once __DIR__ . '/../../includes/init.php';
audit('logout', 'usuarios', (int)($_SESSION['usuario_id'] ?? 0));
logout_usuario();
header('Location: ' . APP_URL . '/index.php?msg=sesion_cerrada');
exit;
