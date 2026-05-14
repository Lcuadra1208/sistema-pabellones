<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/layout.php';
requiere_login(ROL_SUPERADMIN);

$accion = $_GET['accion'] ?? 'lista';
$id     = (int)($_GET['id'] ?? 0);
$error  = '';

// ── Guardar ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_validar()) {
    $datos = [
        sanitizar($_POST['nombre']    ?? ''),
        sanitizar($_POST['tipo']      ?? 'hospital'),
        sanitizar($_POST['rut']       ?? ''),
        sanitizar($_POST['direccion'] ?? ''),
        sanitizar($_POST['ciudad']    ?? ''),
        sanitizar($_POST['region']    ?? ''),
        sanitizar($_POST['telefono']  ?? ''),
        sanitizar($_POST['email']     ?? ''),
    ];
    if (!$datos[0] || !$datos[2]) {
        $error = 'Nombre y RUT son obligatorios.';
    } else {
        $id_post = (int)($_POST['id'] ?? 0);
        if ($id_post) {
            Database::query(
                "UPDATE instituciones SET nombre=?,tipo=?,rut=?,direccion=?,ciudad=?,region=?,telefono=?,email=?,updated_at=NOW() WHERE id=?",
                array_merge($datos, [$id_post])
            );
            audit('editar_institucion','instituciones',$id_post);
            flash_set('exito','Institución actualizada correctamente.');
        } else {
            $new = Database::insert(
                "INSERT INTO instituciones (nombre,tipo,rut,direccion,ciudad,region,telefono,email) VALUES (?,?,?,?,?,?,?,?)",
                $datos
            );
            audit('crear_institucion','instituciones',$new);
            flash_set('exito','Institución creada correctamente.');
        }
        redirigir('modules/mantenedores/instituciones.php');
    }
}

// ── Activar/desactivar ────────────────────────────────────
if ($accion === 'toggle' && $id) {
    Database::query("UPDATE instituciones SET activo = NOT activo WHERE id=?",[$id]);
    flash_set('info','Estado actualizado.');
    redirigir('modules/mantenedores/instituciones.php');
}

// ── Cargar registro para editar ───────────────────────────
$registro = null;
if (in_array($accion,['editar','nueva']) && $id) {
    $registro = Database::fetchOne("SELECT * FROM instituciones WHERE id=?",[$id]);
}

// ── Lista ─────────────────────────────────────────────────
$lista = Database::fetchAll(
    "SELECT i.*, (SELECT COUNT(*) FROM usuarios u WHERE u.id_institucion=i.id AND u.activo=1) AS total_usuarios,
            (SELECT COUNT(*) FROM pabellones p WHERE p.id_institucion=i.id AND p.activo=1) AS total_pabellones
     FROM instituciones i ORDER BY i.nombre"
);

layout_header('Instituciones');
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div></div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalInstitucion"
            onclick="abrirModal()">
        <i class="bi bi-plus-lg me-1"></i> Nueva Institución
    </button>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= $error ?></div>
<?php endif; ?>

<div class="pab-card">
    <div class="card-header"><i class="bi bi-building"></i> Instituciones registradas</div>
    <div class="table-responsive">
        <table class="table table-pab table-hover align-middle mb-0">
            <thead><tr>
                <th>Nombre</th><th>Tipo</th><th>RUT</th><th>Ciudad</th>
                <th>Usuarios</th><th>Pabellones</th><th>Estado</th><th>Acciones</th>
            </tr></thead>
            <tbody>
            <?php foreach ($lista as $r): ?>
            <tr>
                <td class="fw-semibold"><?= sanitizar($r['nombre']) ?></td>
                <td><span class="badge bg-light text-dark"><?= ucfirst($r['tipo']) ?></span></td>
                <td class="text-muted"><?= sanitizar($r['rut']) ?></td>
                <td><?= sanitizar($r['ciudad']) ?></td>
                <td><span class="badge bg-primary bg-opacity-10 text-primary"><?= $r['total_usuarios'] ?></span></td>
                <td><span class="badge bg-info bg-opacity-10 text-info"><?= $r['total_pabellones'] ?></span></td>
                <td>
                    <?php if ($r['activo']): ?>
                        <span class="badge bg-success">Activa</span>
                    <?php else: ?>
                        <span class="badge bg-secondary">Inactiva</span>
                    <?php endif; ?>
                </td>
                <td>
                    <button title="Editar" class="btn btn-sm btn-outline-primary py-0"
                            onclick="abrirModal(<?= htmlspecialchars(json_encode($r),ENT_QUOTES) ?>)">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <a href="?accion=toggle&id=<?= $r['id'] ?>"
                       title="<?= $r['activo'] ? 'Desactivar' : 'Activar' ?>" class="btn btn-sm btn-outline-<?= $r['activo'] ? 'warning' : 'success' ?> py-0"
                       onclick="return confirm('¿Cambiar estado?')">
                        <i class="bi bi-<?= $r['activo'] ? 'pause' : 'play' ?>"></i>
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal -->
<div class="modal fade" id="modalInstitucion" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <form method="POST" class="modal-content">
        <?= csrf_campo() ?>
        <input type="hidden" name="id" id="fi_id">
        <div class="modal-header">
            <h5 class="modal-title" id="modalTitulo">Nueva Institución</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label">Nombre <span class="text-danger">*</span></label>
                    <input type="text" name="nombre" id="fi_nombre" class="form-control" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Tipo</label>
                    <select name="tipo" id="fi_tipo" class="form-select">
                        <option value="hospital">Hospital</option>
                        <option value="clinica">Clínica</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">RUT <span class="text-danger">*</span></label>
                    <input type="text" name="rut" id="fi_rut" class="form-control" placeholder="61.000.000-0">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Ciudad</label>
                    <input type="text" name="ciudad" id="fi_ciudad" class="form-control">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Región</label>
                    <input type="text" name="region" id="fi_region" class="form-control">
                </div>
                <div class="col-md-8">
                    <label class="form-label">Dirección</label>
                    <input type="text" name="direccion" id="fi_direccion" class="form-control">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Teléfono</label>
                    <input type="text" name="telefono" id="fi_telefono" class="form-control">
                </div>
                <div class="col-12">
                    <label class="form-label">Correo institucional</label>
                    <input type="email" name="email" id="fi_email" class="form-control">
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
    const campos = {fi_id:'',fi_nombre:'',fi_tipo:'hospital',fi_rut:'',fi_ciudad:'',fi_region:'',fi_direccion:'',fi_telefono:'',fi_email:''};
    if (r) {
        document.getElementById('modalTitulo').textContent = 'Editar Institución';
        Object.assign(campos,{fi_id:r.id,fi_nombre:r.nombre,fi_tipo:r.tipo,fi_rut:r.rut,
            fi_ciudad:r.ciudad,fi_region:r.region,fi_direccion:r.direccion,
            fi_telefono:r.telefono,fi_email:r.email});
    } else {
        document.getElementById('modalTitulo').textContent = 'Nueva Institución';
    }
    for (const [k,v] of Object.entries(campos)) {
        const el = document.getElementById(k);
        if (el) el.tagName==='SELECT' ? (el.value=v||el.options[0].value) : (el.value=v||'');
    }
    new bootstrap.Modal(document.getElementById('modalInstitucion')).show();
}
</script>
<?php layout_footer(); ?>
