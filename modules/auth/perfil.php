<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/layout.php';
requiere_login();

$usuario = usuario_actual();
$error = '';
$exito = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_validar()) {
    $af = $_POST['accion_form'] ?? '';

    if ($af === 'actualizar_perfil') {
        $nombres  = sanitizar($_POST['nombres'] ?? '');
        $apells   = sanitizar($_POST['apellidos'] ?? '');
        $telefono = sanitizar($_POST['telefono'] ?? '');
        $espec    = sanitizar($_POST['especialidad'] ?? '');
        if (!$nombres || !$apells) { $error = 'Nombres y apellidos son obligatorios.'; }
        else {
            Database::query(
                "UPDATE usuarios SET nombres=?,apellidos=?,telefono=?,especialidad=? WHERE id=?",
                [$nombres,$apells,$telefono,$espec,$usuario['id']]
            );
            // Actualizar sesión
            $_SESSION['usuario']['nombres']     = $nombres;
            $_SESSION['usuario']['apellidos']   = $apells;
            $_SESSION['usuario']['telefono']    = $telefono;
            $_SESSION['usuario']['especialidad']= $espec;
            $exito = 'Perfil actualizado correctamente.';
        }
    }

    if ($af === 'cambiar_clave') {
        $actual   = $_POST['clave_actual'] ?? '';
        $nueva    = $_POST['clave_nueva'] ?? '';
        $confirma = $_POST['clave_confirma'] ?? '';
        $reg = Database::fetchOne("SELECT password_hash FROM usuarios WHERE id=?",[$usuario['id']]);
        if (!password_verify($actual,$reg['password_hash'])) {
            $error = 'La contraseña actual es incorrecta.';
        } elseif (strlen($nueva) < 8) {
            $error = 'La nueva contraseña debe tener al menos 8 caracteres.';
        } elseif ($nueva !== $confirma) {
            $error = 'Las contraseñas no coinciden.';
        } else {
            Database::query("UPDATE usuarios SET password_hash=? WHERE id=?",
                [password_hash($nueva,PASSWORD_BCRYPT),$usuario['id']]);
            audit('cambio_clave','usuarios',(int)$usuario['id']);
            $exito = 'Contraseña actualizada correctamente.';
        }
    }
}

// Acreditaciones del médico
$acreditaciones = [];
if (es_medico()) {
    $acreditaciones = Database::fetchAll(
        "SELECT a.*,i.nombre AS inst_nombre FROM acreditaciones a
         JOIN instituciones i ON i.id=a.id_institucion
         WHERE a.id_medico=? ORDER BY i.nombre",[(int)$usuario['id']]
    );
}

layout_header('Mi Perfil');
?>
<?php if ($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>
<?php if ($exito): ?><div class="alert alert-success"><?= $exito ?></div><?php endif; ?>

<div class="row g-4">
    <div class="col-md-7">
        <div class="pab-card p-4">
            <h6 class="fw-bold mb-4"><i class="bi bi-person-circle me-2"></i>Datos personales</h6>
            <form method="POST">
                <?= csrf_campo() ?>
                <input type="hidden" name="accion_form" value="actualizar_perfil">
                <div class="row g-3">
                    <div class="col-6"><label class="form-label">Nombres</label><input type="text" name="nombres" class="form-control" value="<?= sanitizar($usuario['nombres']) ?>" required></div>
                    <div class="col-6"><label class="form-label">Apellidos</label><input type="text" name="apellidos" class="form-control" value="<?= sanitizar($usuario['apellidos']) ?>" required></div>
                    <div class="col-6"><label class="form-label">RUT</label><input type="text" class="form-control" value="<?= sanitizar($usuario['rut']) ?>" disabled></div>
                    <div class="col-6"><label class="form-label">Correo</label><input type="email" class="form-control" value="<?= sanitizar($usuario['email']) ?>" disabled></div>
                    <div class="col-6"><label class="form-label">Teléfono</label><input type="text" name="telefono" class="form-control" value="<?= sanitizar($usuario['telefono'] ?? '') ?>"></div>
                    <?php if (es_medico()): ?>
                    <div class="col-6"><label class="form-label">Especialidad</label><input type="text" name="especialidad" class="form-control" value="<?= sanitizar($usuario['especialidad'] ?? '') ?>"></div>
                    <?php endif; ?>
                </div>
                <button type="submit" class="btn btn-primary mt-3"><i class="bi bi-save me-1"></i>Actualizar</button>
            </form>
        </div>
    </div>
    <div class="col-md-5">
        <div class="pab-card p-4 mb-3">
            <h6 class="fw-bold mb-4"><i class="bi bi-shield-lock me-2"></i>Cambiar contraseña</h6>
            <form method="POST">
                <?= csrf_campo() ?>
                <input type="hidden" name="accion_form" value="cambiar_clave">
                <div class="mb-3"><label class="form-label">Contraseña actual</label><input type="password" name="clave_actual" class="form-control" required></div>
                <div class="mb-3"><label class="form-label">Nueva contraseña</label><input type="password" name="clave_nueva" class="form-control" minlength="8" required></div>
                <div class="mb-3"><label class="form-label">Confirmar contraseña</label><input type="password" name="clave_confirma" class="form-control" required></div>
                <button type="submit" class="btn btn-warning"><i class="bi bi-key me-1"></i>Cambiar contraseña</button>
            </form>
        </div>

        <?php if ($acreditaciones): ?>
        <div class="pab-card p-4">
            <h6 class="fw-bold mb-3"><i class="bi bi-building-check me-2"></i>Instituciones acreditadas</h6>
            <?php foreach ($acreditaciones as $ac): ?>
            <div class="d-flex align-items-center gap-2 mb-2 p-2 bg-light rounded">
                <i class="bi bi-hospital text-primary"></i>
                <div class="flex-grow-1">
                    <div class="small fw-semibold"><?= sanitizar($ac['inst_nombre']) ?></div>
                    <div class="text-muted" style="font-size:.75rem">
                        Desde <?= formato_fecha($ac['fecha_inicio']) ?>
                        <?= $ac['fecha_fin'] ? ' hasta '.formato_fecha($ac['fecha_fin']) : '' ?>
                    </div>
                </div>
                <span class="badge bg-<?= $ac['activo']?'success':'secondary' ?>"><?= $ac['activo']?'Vigente':'Vencida' ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php layout_footer(); ?>
