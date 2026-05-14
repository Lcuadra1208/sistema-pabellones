<?php
/**
 * Funciones de sesión y utilidades globales
 */

// ── Sesión ────────────────────────────────────────────────

function session_iniciar(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_name(SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path'     => '/',
            'secure'   => false,   // true en producción con HTTPS
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function usuario_logueado(): bool
{
    return isset($_SESSION['usuario_id']) && !empty($_SESSION['usuario_id']);
}

function usuario_actual(): ?array
{
    if (!usuario_logueado()) return null;
    return $_SESSION['usuario'] ?? null;
}

function usuario_rol(): int
{
    return (int)($_SESSION['usuario']['id_rol'] ?? 0);
}

function usuario_institucion(): ?int
{
    $id = $_SESSION['usuario']['id_institucion'] ?? null;
    return $id ? (int)$id : null;
}

function es_superadmin(): bool   { return usuario_rol() === ROL_SUPERADMIN; }
function es_admin(): bool        { return usuario_rol() === ROL_ADMIN_HOSPITAL; }
function es_medico(): bool       { return usuario_rol() === ROL_MEDICO; }


function es_mantencion(): bool
{
    return usuario_rol() === ROL_MANTENCION;
}

/**
 * Redirige si no está logueado. Opcionalmente exige un rol mínimo.
 */
// Tiempo máximo de inactividad según rol
define('INACTIVIDAD_MEDICO', 600);   // 10 min para médicos
define('INACTIVIDAD_ADMIN',  1800);  // 30 min para admin y superadmin

function get_inactividad_max(): int
{
    $rol = usuario_rol();
    return ($rol === ROL_MEDICO) ? INACTIVIDAD_MEDICO : INACTIVIDAD_ADMIN;
}

function requiere_login(int $rol_minimo = 0): void
{
    session_iniciar();
    if (!usuario_logueado()) {
        header('Location: ' . APP_URL . '/index.php?msg=sesion_expirada');
        exit;
    }

    // ── Control de inactividad según rol ──────────────────
    $ahora        = time();
    $ultima_act   = $_SESSION['ultima_actividad'] ?? $ahora;
    $inactivo_por = $ahora - $ultima_act;
    $max          = get_inactividad_max();

    if ($inactivo_por > $max) {
        logout_usuario();
        header('Location: ' . APP_URL . '/index.php?msg=sesion_expirada&inactivo=1');
        exit;
    }

    // Actualizar timestamp de última actividad
    $_SESSION['ultima_actividad'] = $ahora;
    $_SESSION['inactividad_max']  = $max; // pasar al JS

    if ($rol_minimo > 0 && usuario_rol() > $rol_minimo) {
        header('Location: ' . APP_URL . '/dashboard.php?error=acceso_denegado');
        exit;
    }
}

function login_usuario(array $datos_usuario): void
{
    session_regenerate_id(true);
    $_SESSION['usuario_id'] = $datos_usuario['id'];
    $_SESSION['usuario']    = $datos_usuario;
}

function logout_usuario(): void
{
    session_iniciar();
    $_SESSION = [];
    session_destroy();
    setcookie(SESSION_NAME, '', time() - 3600, '/');
}

// ── Seguridad ─────────────────────────────────────────────

function sanitizar(string $valor): string
{
    return htmlspecialchars(trim($valor), ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_validar(): bool
{
    $token = $_POST['csrf_token'] ?? '';
    return hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

function csrf_campo(): string
{
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}

// ── Redirecciones ─────────────────────────────────────────

function redirigir(string $ruta, array $params = []): void
{
    $url = APP_URL . '/' . ltrim($ruta, '/');
    if ($params) {
        $url .= '?' . http_build_query($params);
    }
    if (headers_sent()) {
        echo '<script>window.location.href="'.addslashes($url).'";</script>';
    } else {
        header('Location: ' . $url);
    }
    exit;
}

// ── Auditoría ─────────────────────────────────────────────

function audit(string $accion, string $tabla = '', int $registro_id = 0, string $detalle = ''): void
{
    try {
        Database::insert(
            "INSERT INTO audit_log (id_usuario, accion, tabla, registro_id, detalle, ip)
             VALUES (?, ?, ?, ?, ?, ?)",
            [
                $_SESSION['usuario_id'] ?? null,
                $accion,
                $tabla ?: null,
                $registro_id ?: null,
                $detalle ?: null,
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]
        );
    } catch (Exception $e) {
        // No interrumpir el flujo por error de auditoría
    }
}

// ── Formato ───────────────────────────────────────────────

function formato_fecha(string $fecha): string
{
    if (!$fecha) return '';
    return date('d/m/Y', strtotime($fecha));
}

function formato_hora(string $hora): string
{
    if (!$hora) return '';
    return substr($hora, 0, 5); // HH:MM
}

function formato_fecha_hora(string $dt): string
{
    if (!$dt) return '';
    return date('d/m/Y H:i', strtotime($dt));
}

function formato_peso(float $monto): string
{
    return '$ ' . number_format($monto, 0, ',', '.');
}

// ── Mensajes flash ────────────────────────────────────────

function flash_set(string $tipo, string $mensaje): void
{
    $_SESSION['flash'] = ['tipo' => $tipo, 'mensaje' => $mensaje];
}

function flash_get(): ?array
{
    if (!isset($_SESSION['flash'])) return null;
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function flash_html(): string
{
    $flash = flash_get();
    if (!$flash) return '';
    $mapa = [
        'exito'    => 'success',
        'error'    => 'danger',
        'info'     => 'info',
        'alerta'   => 'warning',
    ];
    $clase = $mapa[$flash['tipo']] ?? 'info';
    return sprintf(
        '<div class="alert alert-dismissible alert--%s fade show" role="alert">
            %s
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
         </div>',
        $clase,
        sanitizar($flash['mensaje'])
    );
}
