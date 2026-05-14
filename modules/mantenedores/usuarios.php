<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/layout.php';
requiere_login(ROL_ADMIN_HOSPITAL);

$accion    = $_GET['accion'] ?? 'lista';
$id        = (int)($_GET['id'] ?? 0);
$error     = '';
$id_inst   = usuario_institucion();
$es_super  = es_superadmin();

// Instituciones para superadmin
$instituciones = $es_super
    ? Database::fetchAll("SELECT id,nombre FROM instituciones WHERE activo=1 ORDER BY nombre")
    : [];

// ── Guardar usuario ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_validar() && isset($_POST['accion_form'])) {

    if ($_POST['accion_form'] === 'guardar_usuario') {
        $id_post      = (int)($_POST['id'] ?? 0);
        $id_rol       = (int)($_POST['id_rol'] ?? ROL_MEDICO);
        $id_inst_post = $es_super ? (int)($_POST['id_institucion'] ?? 0) : (int)$id_inst;
        // Superadmin no tiene institución
        if ($id_rol === ROL_SUPERADMIN) $id_inst_post = null;

        $rut         = sanitizar($_POST['rut'] ?? '');
        $nombres     = sanitizar($_POST['nombres'] ?? '');
        $apellidos   = sanitizar($_POST['apellidos'] ?? '');
        $email       = strtolower(trim($_POST['email'] ?? ''));
        $especialidad= sanitizar($_POST['especialidad'] ?? '');
        $telefono    = sanitizar($_POST['telefono'] ?? '');
        $clave       = $_POST['password'] ?? '';

        if (!$rut || !$nombres || !$apellidos || !$email) {
            $error = 'Campos obligatorios incompletos.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'El correo electrónico no tiene un formato válido.';
        } else {
            // Verificar RUT duplicado (excluyendo el usuario actual al editar)
            $rut_existe = Database::fetchOne(
                "SELECT id FROM usuarios WHERE rut=? AND id != ?", [$rut, $id_post]
            );
            // Verificar email duplicado
            $email_existe = Database::fetchOne(
                "SELECT id FROM usuarios WHERE email=? AND id != ?", [$email, $id_post]
            );

            if ($rut_existe) {
                $error = 'El RUT '.$rut.' ya está registrado en el sistema.';
            } elseif ($email_existe) {
                $error = 'El correo '.$email.' ya está registrado en el sistema.';
            } elseif ($id_post) {
                // Editar
                $sql = "UPDATE usuarios SET id_rol=?,id_institucion=?,rut=?,nombres=?,apellidos=?,
                        email=?,especialidad=?,telefono=?,updated_at=NOW() WHERE id=?";
                $params = [$id_rol,$id_inst_post,$rut,$nombres,$apellidos,$email,$especialidad,$telefono,$id_post];
                if ($clave) {
                    $sql = "UPDATE usuarios SET id_rol=?,id_institucion=?,rut=?,nombres=?,apellidos=?,
                            email=?,especialidad=?,telefono=?,password_hash=?,updated_at=NOW() WHERE id=?";
                    $params = [$id_rol,$id_inst_post,$rut,$nombres,$apellidos,$email,$especialidad,$telefono,
                               password_hash($clave,PASSWORD_BCRYPT),$id_post];
                }
                Database::query($sql,$params);
                audit('editar_usuario','usuarios',$id_post);
                flash_set('exito','Usuario actualizado correctamente.');
                redirigir('modules/mantenedores/usuarios.php');
            } else {
                if (!$clave) { $error = 'La contraseña es obligatoria para nuevos usuarios.'; goto fin; }
                $new = Database::insert(
                    "INSERT INTO usuarios (id_rol,id_institucion,rut,nombres,apellidos,email,password_hash,especialidad,telefono)
                     VALUES (?,?,?,?,?,?,?,?,?)",
                    [$id_rol,$id_inst_post,$rut,$nombres,$apellidos,$email,
                     password_hash($clave,PASSWORD_BCRYPT),$especialidad,$telefono]
                );
                audit('crear_usuario','usuarios',$new);
                flash_set('exito','Usuario creado correctamente.');
                redirigir('modules/mantenedores/usuarios.php');
            }
        }
    }

    if ($_POST['accion_form'] === 'toggle_activo') {
        $uid = (int)($_POST['uid'] ?? 0);
        if ($uid) {
            Database::query("UPDATE usuarios SET activo = NOT activo WHERE id=?",[$uid]);
            flash_set('info','Estado actualizado.');
        }
        redirigir('modules/mantenedores/usuarios.php');
    }
}
fin:

// ── Datos del POST para repoblar modal si hubo error ─────
$post_previo = null;
if ($error && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_previo = [
        'id'             => (int)($_POST['id'] ?? 0),
        'id_rol'         => (int)($_POST['id_rol'] ?? ROL_MEDICO),
        'id_institucion' => (int)($_POST['id_institucion'] ?? 0),
        'rut'            => sanitizar($_POST['rut'] ?? ''),
        'nombres'        => sanitizar($_POST['nombres'] ?? ''),
        'apellidos'      => sanitizar($_POST['apellidos'] ?? ''),
        'email'          => sanitizar($_POST['email'] ?? ''),
        'telefono'       => sanitizar($_POST['telefono'] ?? ''),
        'especialidad'   => sanitizar($_POST['especialidad'] ?? ''),
    ];
}

// ── Lista ─────────────────────────────────────────────────
$where = $es_super ? '' : 'WHERE u.id_institucion = ' . (int)$id_inst;
$lista = Database::fetchAll(
    "SELECT u.*, r.nombre AS rol_nombre, i.nombre AS inst_nombre
     FROM usuarios u
     JOIN roles r ON r.id = u.id_rol
     LEFT JOIN instituciones i ON i.id = u.id_institucion
     $where
     ORDER BY r.id, u.apellidos"
);

// Superadmin ve todos los roles; admin ve solo admin_hospital, medico y mantencion
$roles = Database::fetchAll(
    "SELECT * FROM roles " . ($es_super ? '' : 'WHERE id IN (2,3,4)') . " ORDER BY id"
);

layout_header('Usuarios');
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div></div>
    <button class="btn btn-primary" onclick="abrirModal()">
        <i class="bi bi-person-plus me-1"></i> Nuevo Usuario
    </button>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>

<div class="pab-card">
    <div class="card-header"><i class="bi bi-people"></i> Usuarios del sistema</div>
    <div class="table-responsive">
        <table class="table table-pab table-hover align-middle mb-0">
            <thead><tr>
                <th>Nombre</th><th>RUT</th><th>Correo</th>
                <?php if ($es_super): ?><th>Institución</th><?php endif; ?>
                <th>Rol</th><th>Especialidad</th><th>Estado</th><th>Acciones</th>
            </tr></thead>
            <tbody>
            <?php foreach ($lista as $r): ?>
            <tr>
                <td class="fw-semibold">
                    <i class="bi bi-person-circle text-muted me-1"></i>
                    <?= sanitizar($r['nombres'].' '.$r['apellidos']) ?>
                </td>
                <td class="text-muted small"><?= sanitizar($r['rut']) ?></td>
                <td class="small"><?= sanitizar($r['email']) ?></td>
                <?php if ($es_super): ?>
                <td class="small"><?= sanitizar($r['inst_nombre'] ?? '—') ?></td>
                <?php endif; ?>
                <td>
                    <?php
                    $badge = match($r['id_rol']) {
                        1 => 'danger', 2 => 'primary', 3 => 'success', default => 'secondary'
                    };
                    ?>
                    <span class="badge bg-<?= $badge ?>"><?= sanitizar($r['rol_nombre']) ?></span>
                </td>
                <td class="small text-muted"><?= sanitizar($r['especialidad'] ?? '—') ?></td>
                <td>
                    <span class="badge bg-<?= $r['activo'] ? 'success' : 'secondary' ?>">
                        <?= $r['activo'] ? 'Activo' : 'Inactivo' ?>
                    </span>
                </td>
                <td>
                    <button title="Editar" class="btn btn-sm btn-outline-primary py-0"
                            onclick="abrirModal(<?= htmlspecialchars(json_encode($r),ENT_QUOTES) ?>)">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <form method="POST" class="d-inline">
                        <?= csrf_campo() ?>
                        <input type="hidden" name="accion_form" value="toggle_activo">
                        <input type="hidden" name="uid" value="<?= $r['id'] ?>">
                        <button type="submit" title="<?= $r['activo'] ? 'Desactivar' : 'Activar' ?>" class="btn btn-sm btn-outline-<?= $r['activo']?'warning':'success' ?> py-0"
                                onclick="return confirm('¿Cambiar estado del usuario?')">
                            <i class="bi bi-<?= $r['activo']?'person-slash':'person-check' ?>"></i>
                        </button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal usuario -->
<div class="modal fade" id="modalUsuario" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <form method="POST" class="modal-content">
        <?= csrf_campo() ?>
        <input type="hidden" name="accion_form" value="guardar_usuario">
        <input type="hidden" name="id" id="fu_id">
        <div class="modal-header">
            <h5 class="modal-title" id="fu_titulo">Nuevo Usuario</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
            <div id="modal_error_container"></div>
            <div class="row g-3">
                <?php if ($es_super): ?>
                <div class="col-md-6">
                    <label class="form-label">Institución</label>
                    <select name="id_institucion" id="fu_institucion" class="form-select">
                        <option value="">— Ninguna (superadmin) —</option>
                        <?php foreach ($instituciones as $inst): ?>
                        <option value="<?= $inst['id'] ?>"><?= sanitizar($inst['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-md-<?= $es_super?'6':'12' ?>">
                    <label class="form-label">Rol <span class="text-danger">*</span></label>
                    <select name="id_rol" id="fu_rol" class="form-select">
                        <?php foreach ($roles as $rol): ?>
                        <option value="<?= $rol['id'] ?>"><?= sanitizar($rol['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">RUT <span class="text-danger">*</span></label>
                    <input type="text" name="rut" id="fu_rut" class="form-control"
                           placeholder="12.345.678-9" oninput="formatearRut(this)">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Nombres <span class="text-danger">*</span></label>
                    <input type="text" name="nombres" id="fu_nombres" class="form-control">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Apellidos <span class="text-danger">*</span></label>
                    <input type="text" name="apellidos" id="fu_apellidos" class="form-control">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Correo <span class="text-danger">*</span></label>
                    <input type="email" name="email" id="fu_email" class="form-control">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Teléfono</label>
                    <input type="text" name="telefono" id="fu_telefono" class="form-control">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Especialidad <span class="text-muted small">(médicos)</span></label>
                    <input type="text" name="especialidad" id="fu_especialidad" class="form-control"
                           placeholder="Cirugía General, Traumatología...">
                </div>
                <div class="col-md-6">
                    <label class="form-label" id="fu_lbl_clave">Contraseña <span class="text-danger">*</span></label>
                    <input type="password" name="password" id="fu_password" class="form-control"
                           placeholder="Mínimo 8 caracteres">
                    <div class="form-text" id="fu_clave_hint"></div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i> Guardar</button>
        </div>
    </form>
  </div>
</div>
<script>
function abrirModal(r) {
    const esNuevo = !r;
    document.getElementById('fu_titulo').textContent = esNuevo ? 'Nuevo Usuario' : 'Editar Usuario';
    document.getElementById('fu_id').value           = r ? r.id : '';
    document.getElementById('fu_rut').value          = r ? r.rut : '';
    document.getElementById('fu_nombres').value      = r ? r.nombres : '';
    document.getElementById('fu_apellidos').value    = r ? r.apellidos : '';
    document.getElementById('fu_email').value        = r ? r.email : '';
    document.getElementById('fu_telefono').value     = r ? (r.telefono||'') : '';
    document.getElementById('fu_especialidad').value = r ? (r.especialidad||'') : '';
    document.getElementById('fu_password').value     = '';
    document.getElementById('fu_password').required  = esNuevo;
    // Limpiar error anterior del modal
    document.getElementById('modal_error_container').innerHTML = '';
    const hint = document.getElementById('fu_clave_hint');
    hint.textContent = esNuevo ? '' : 'Dejar vacío para mantener la contraseña actual.';
    const selRol = document.getElementById('fu_rol');
    if (r && selRol) selRol.value = r.id_rol;
    <?php if ($es_super): ?>
    const selInst = document.getElementById('fu_institucion');
    if (r && selInst) selInst.value = r.id_institucion || '';
    <?php endif; ?>
    new bootstrap.Modal(document.getElementById('modalUsuario')).show();
}
</script>

<?php if ($error && $post_previo): ?>
<script>
// Reabrir modal automáticamente con los datos ingresados
document.addEventListener('DOMContentLoaded', function() {
    const datos = <?= json_encode($post_previo) ?>;
    // Rellenar campos
    document.getElementById('fu_id').value           = datos.id || '';
    document.getElementById('fu_rut').value          = datos.rut;
    document.getElementById('fu_nombres').value      = datos.nombres;
    document.getElementById('fu_apellidos').value    = datos.apellidos;
    document.getElementById('fu_email').value        = datos.email;
    document.getElementById('fu_telefono').value     = datos.telefono;
    document.getElementById('fu_especialidad').value = datos.especialidad;
    document.getElementById('fu_password').required  = !datos.id;
    document.getElementById('fu_titulo').textContent = datos.id ? 'Editar Usuario' : 'Nuevo Usuario';

    <?php if ($es_super && $post_previo): ?>
    const selInst = document.getElementById('fu_institucion');
    if (selInst) selInst.value = datos.id_institucion || '';
    <?php endif; ?>

    const selRol = document.getElementById('fu_rol');
    if (selRol) selRol.value = datos.id_rol;

    // Mostrar error dentro del modal
    document.getElementById('modal_error_container').innerHTML =
        '<div class="alert alert-danger alert-dismissible mb-3">' +
        '<i class="bi bi-exclamation-triangle me-2"></i>' +
        '<?= addslashes(sanitizar($error)) ?>' +
        '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>' +
        '</div>';

    // Abrir el modal
    new bootstrap.Modal(document.getElementById('modalUsuario')).show();
});
</script>
<?php endif; ?>

<?php layout_footer(); ?>
