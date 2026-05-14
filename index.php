<?php
/**
 * index.php — Login del sistema
 */
require_once __DIR__ . '/includes/init.php';

// Si ya está logueado, redirigir al dashboard
if (usuario_logueado()) {
    redirigir('dashboard.php');
}

$error     = '';
$exito     = '';

// Mensajes desde redirección GET
$msg = $_GET['msg'] ?? '';
if ($msg === 'sesion_cerrada')  $exito = 'Ha cerrado sesión correctamente.';
if ($msg === 'sesion_expirada') {
    $error = isset($_GET['inactivo'])
        ? '⏱️ Su sesión expiró por inactividad. Ingrese nuevamente.. Por favor ingrese nuevamente.'
        : 'Su sesión ha expirado. Por favor ingrese nuevamente.';
}
$modo      = $_GET['modo'] ?? 'login';       // 'login' | 'recuperar' | 'nueva_clave'
$token_rec = $_GET['token'] ?? '';

// ── Procesar login ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {

    if (!csrf_validar()) {
        $error = 'Token de seguridad inválido. Recargue la página.';
    } else {

        switch ($_POST['accion']) {

            // ── Login normal ──────────────────────────────
            case 'login':
                $email = strtolower(trim($_POST['email'] ?? ''));
                $clave = $_POST['password'] ?? '';

                if (!$email || !$clave) {
                    $error = 'Ingrese su correo y contraseña.';
                    break;
                }

                $sql = "SELECT u.*, i.nombre AS institucion_nombre
                        FROM usuarios u
                        LEFT JOIN instituciones i ON i.id = u.id_institucion
                        WHERE u.email = ? AND u.activo = 1
                        LIMIT 1";
                $usuario = Database::fetchOne($sql, [$email]);

                if (!$usuario || !password_verify($clave, $usuario['password_hash'])) {
                    $error = 'Correo o contraseña incorrectos.';
                    // Registrar intento fallido
                    audit('login_fallido', 'usuarios', 0, $email);
                    break;
                }

                // Actualizar último acceso
                Database::query(
                    "UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = ?",
                    [$usuario['id']]
                );

                login_usuario($usuario);
                audit('login_exitoso', 'usuarios', (int)$usuario['id']);
                redirigir('dashboard.php');
                break;

            // ── Solicitar recuperación ─────────────────────
            case 'recuperar':
                $email = strtolower(trim($_POST['email'] ?? ''));
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $error = 'Ingrese un correo válido.';
                    break;
                }

                $usuario = Database::fetchOne(
                    "SELECT id, nombres FROM usuarios WHERE email = ? AND activo = 1",
                    [$email]
                );

                // Siempre mostrar éxito (no revelar si el email existe)
                $exito = 'Si el correo está registrado, recibirá un enlace de recuperación en los próximos minutos.';

                if ($usuario) {
                    $token   = bin2hex(random_bytes(32));
                    $expira  = date('Y-m-d H:i:s', strtotime('+2 hours'));
                    Database::query(
                        "UPDATE usuarios SET token_recupera = ?, token_expira = ? WHERE id = ?",
                        [$token, $expira, $usuario['id']]
                    );
                    $enlace = APP_URL . '/index.php?modo=nueva_clave&token=' . $token;
                    // TODO: enviar email con $enlace usando PHPMailer
                    // Por ahora se muestra en desarrollo:
                    if (AMBIENTE === 'desarrollo') {
                        $exito .= '<br><small class="text-muted">DEV — Enlace: <a href="' . $enlace . '">' . $enlace . '</a></small>';
                    }
                    audit('recuperacion_solicitada', 'usuarios', (int)$usuario['id'], $email);
                }
                $modo = 'login';
                break;

            // ── Guardar nueva contraseña ───────────────────
            case 'nueva_clave':
                $token  = $_POST['token'] ?? '';
                $clave1 = $_POST['password1'] ?? '';
                $clave2 = $_POST['password2'] ?? '';

                if (strlen($clave1) < 8) {
                    $error = 'La contraseña debe tener al menos 8 caracteres.';
                    break;
                }
                if ($clave1 !== $clave2) {
                    $error = 'Las contraseñas no coinciden.';
                    break;
                }

                $usuario = Database::fetchOne(
                    "SELECT id FROM usuarios
                     WHERE token_recupera = ? AND token_expira > NOW() AND activo = 1",
                    [$token]
                );

                if (!$usuario) {
                    $error = 'El enlace de recuperación es inválido o ha expirado.';
                    break;
                }

                $hash = password_hash($clave1, PASSWORD_BCRYPT);
                Database::query(
                    "UPDATE usuarios SET password_hash = ?, token_recupera = NULL, token_expira = NULL WHERE id = ?",
                    [$hash, $usuario['id']]
                );
                audit('cambio_clave', 'usuarios', (int)$usuario['id']);
                $exito = 'Contraseña actualizada correctamente. Ya puede iniciar sesión.';
                $modo  = 'login';
                break;
        }
    }
}

// Verificar token para modo nueva_clave
$token_valido = false;
if ($modo === 'nueva_clave' && $token_rec) {
    $reg = Database::fetchOne(
        "SELECT id FROM usuarios WHERE token_recupera = ? AND token_expira > NOW()",
        [$token_rec]
    );
    $token_valido = (bool)$reg;
    if (!$token_valido) {
        $error = 'El enlace de recuperación ha expirado o ya fue utilizado.';
        $modo  = 'login';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= APP_NAME ?> — Acceso</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css">
</head>
<body>

<div class="login-wrapper">
    <div class="login-card">
        <div class="login-logo">
            <i class="bi bi-hospital-fill"></i>
            <h5 class="mt-2 fw-bold" style="color:var(--pab-dark)"><?= APP_NAME ?></h5>
            <p class="text-muted small mb-0">Administración de Pabellones Quirúrgicos</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle me-2"></i><?= $error ?></div>
        <?php endif; ?>
        <?php if ($exito): ?>
            <div class="alert alert-success py-2"><i class="bi bi-check-circle me-2"></i><?= $exito ?></div>
        <?php endif; ?>

        <?php if ($modo === 'login'): ?>
        <!-- ── FORMULARIO LOGIN ── -->
        <form method="POST" autocomplete="off" novalidate>
            <?= csrf_campo() ?>
            <input type="hidden" name="accion" value="login">

            <div class="mb-3">
                <label class="form-label fw-semibold">Correo electrónico</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                    <input type="email" name="email" class="form-control"
                           placeholder="usuario@hospital.cl" required autofocus>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold">Contraseña</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-lock"></i></span>
                    <input type="password" name="password" id="campo-clave" class="form-control"
                           placeholder="••••••••" required>
                    <button type="button" title="Ver detalle" class="btn btn-outline-secondary"
                            onclick="toggleClave()"><i class="bi bi-eye" id="ico-ojo"></i></button>
                </div>
            </div>

            <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold">
                <i class="bi bi-box-arrow-in-right me-2"></i>Ingresar
            </button>

            <div class="text-center mt-3">
                <a href="?modo=recuperar" class="text-muted small">¿Olvidó su contraseña?</a>
            </div>
        </form>

        <?php elseif ($modo === 'recuperar'): ?>
        <!-- ── FORMULARIO RECUPERAR ── -->
        <p class="text-muted small mb-3">
            Ingrese su correo y le enviaremos un enlace para restablecer su contraseña.
        </p>
        <form method="POST" novalidate>
            <?= csrf_campo() ?>
            <input type="hidden" name="accion" value="recuperar">
            <div class="mb-3">
                <label class="form-label fw-semibold">Correo electrónico</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                    <input type="email" name="email" class="form-control"
                           placeholder="usuario@hospital.cl" required autofocus>
                </div>
            </div>
            <button type="submit" class="btn btn-warning w-100 py-2 fw-semibold">
                <i class="bi bi-send me-2"></i>Enviar enlace de recuperación
            </button>
            <div class="text-center mt-3">
                <a href="?" class="text-muted small"><i class="bi bi-arrow-left me-1"></i>Volver al login</a>
            </div>
        </form>

        <?php elseif ($modo === 'nueva_clave' && $token_valido): ?>
        <!-- ── FORMULARIO NUEVA CLAVE ── -->
        <p class="text-muted small mb-3">Ingrese su nueva contraseña (mínimo 8 caracteres).</p>
        <form method="POST" novalidate>
            <?= csrf_campo() ?>
            <input type="hidden" name="accion" value="nueva_clave">
            <input type="hidden" name="token"  value="<?= sanitizar($token_rec) ?>">
            <div class="mb-3">
                <label class="form-label fw-semibold">Nueva contraseña</label>
                <input type="password" name="password1" class="form-control"
                       placeholder="Mínimo 8 caracteres" required autofocus>
            </div>
            <div class="mb-4">
                <label class="form-label fw-semibold">Confirmar contraseña</label>
                <input type="password" name="password2" class="form-control"
                       placeholder="Repita la contraseña" required>
            </div>
            <button type="submit" class="btn btn-success w-100 py-2 fw-semibold">
                <i class="bi bi-shield-check me-2"></i>Guardar nueva contraseña
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleClave() {
    const campo = document.getElementById('campo-clave');
    const ico   = document.getElementById('ico-ojo');
    if (campo.type === 'password') {
        campo.type = 'text';
        ico.className = 'bi bi-eye-slash';
    } else {
        campo.type = 'password';
        ico.className = 'bi bi-eye';
    }
}
</script>
</body>
</html>
