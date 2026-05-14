<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/layout.php';
requiere_login(ROL_ADMIN_HOSPITAL);
$id_inst = es_superadmin() ? null : usuario_institucion();
$error   = '';
$tab     = $_GET['tab'] ?? 'insumos'; // 'insumos' | 'tipos'

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_validar()) {
    $af = $_POST['accion_form'] ?? '';
    $id_ip = es_superadmin() ? (int)($_POST['id_institucion'] ?? 0) : (int)$id_inst;

    if ($af === 'guardar_tipo') {
        $id_p   = (int)($_POST['id'] ?? 0);
        $nombre = sanitizar($_POST['nombre'] ?? '');
        $desc   = sanitizar($_POST['descripcion'] ?? '');
        if (!$nombre || !$id_ip) { $error='Nombre obligatorio.'; goto fin4; }
        if ($id_p) {
            Database::query("UPDATE tipos_insumo SET id_institucion=?,nombre=?,descripcion=? WHERE id=?",[$id_ip,$nombre,$desc,$id_p]);
        } else {
            Database::insert("INSERT INTO tipos_insumo (id_institucion,nombre,descripcion) VALUES (?,?,?)",[$id_ip,$nombre,$desc]);
        }
        flash_set('exito','Tipo de insumo guardado.');
        redirigir('modules/mantenedores/insumos.php?tab=tipos');
    }

    if ($af === 'guardar_insumo') {
        $id_p  = (int)($_POST['id'] ?? 0);
        $id_ti = (int)($_POST['id_tipo_insumo'] ?? 0);
        $nombre= sanitizar($_POST['nombre'] ?? '');
        $desc  = sanitizar($_POST['descripcion'] ?? '');
        $um    = sanitizar($_POST['unidad_medida'] ?? '');
        if (!$nombre || !$id_ti) { $error='Nombre y tipo son obligatorios.'; goto fin4; }
        if ($id_p) {
            Database::query("UPDATE insumos SET id_institucion=?,id_tipo_insumo=?,nombre=?,descripcion=?,unidad_medida=? WHERE id=?",
                [$id_ip,$id_ti,$nombre,$desc,$um,$id_p]);
        } else {
            Database::insert("INSERT INTO insumos (id_institucion,id_tipo_insumo,nombre,descripcion,unidad_medida) VALUES (?,?,?,?,?)",
                [$id_ip,$id_ti,$nombre,$desc,$um]);
        }
        flash_set('exito','Insumo guardado.');
        redirigir('modules/mantenedores/insumos.php');
    }
}
fin4:

$instituciones = es_superadmin()
    ? Database::fetchAll("SELECT id,nombre FROM instituciones WHERE activo=1 ORDER BY nombre") : [];
$tipos_insumo = Database::fetchAll(
    "SELECT * FROM tipos_insumo WHERE activo=1" . ($id_inst ? " AND id_institucion=$id_inst" : '') . " ORDER BY nombre"
);
$lista_insumos = Database::fetchAll(
    "SELECT ins.*, ti.nombre AS tipo_nombre, i.nombre AS inst_nombre
     FROM insumos ins
     JOIN tipos_insumo ti ON ti.id=ins.id_tipo_insumo
     JOIN instituciones i ON i.id=ins.id_institucion
     WHERE ins.activo=1" . ($id_inst ? " AND ins.id_institucion=$id_inst" : '') . " ORDER BY ti.nombre,ins.nombre"
);
$lista_tipos = Database::fetchAll(
    "SELECT ti.*, i.nombre AS inst_nombre FROM tipos_insumo ti
     JOIN instituciones i ON i.id=ti.id_institucion
     " . ($id_inst ? "WHERE ti.id_institucion=$id_inst" : '') . " ORDER BY ti.nombre"
);

layout_header('Insumos');
?>
<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link <?= $tab==='insumos'?'active':'' ?>" href="?tab=insumos"><i class="bi bi-box-seam me-1"></i>Insumos</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab==='tipos'?'active':'' ?>" href="?tab=tipos"><i class="bi bi-tags me-1"></i>Tipos de Insumo</a>
    </li>
</ul>

<?php if ($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>

<?php if ($tab === 'insumos'): ?>
<!-- INSUMOS -->
<div class="d-flex justify-content-end mb-3">
    <button class="btn btn-primary" onclick="abrirModalInsumo()"><i class="bi bi-plus-lg me-1"></i> Nuevo Insumo</button>
</div>
<div class="pab-card">
    <div class="card-header"><i class="bi bi-box-seam"></i> Insumos</div>
    <div class="table-responsive">
        <table class="table table-pab table-hover align-middle mb-0">
            <thead><tr><th>Nombre</th><th>Tipo</th><th>Unidad</th><?php if (es_superadmin()): ?><th>Institución</th><?php endif; ?><th>Acciones</th></tr></thead>
            <tbody>
            <?php foreach ($lista_insumos as $r): ?>
            <tr>
                <td class="fw-semibold"><?= sanitizar($r['nombre']) ?></td>
                <td><span class="badge bg-light text-dark"><?= sanitizar($r['tipo_nombre']) ?></span></td>
                <td class="text-muted small"><?= sanitizar($r['unidad_medida'] ?? '—') ?></td>
                <?php if (es_superadmin()): ?><td class="small"><?= sanitizar($r['inst_nombre']) ?></td><?php endif; ?>
                <td>
                    <button title="Editar" class="btn btn-sm btn-outline-primary py-0" onclick="abrirModalInsumo(<?= htmlspecialchars(json_encode($r),ENT_QUOTES) ?>)"><i class="bi bi-pencil"></i></button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php else: ?>
<!-- TIPOS DE INSUMO -->
<div class="d-flex justify-content-end mb-3">
    <button class="btn btn-primary" onclick="abrirModalTipo()"><i class="bi bi-plus-lg me-1"></i> Nuevo Tipo</button>
</div>
<div class="pab-card">
    <div class="card-header"><i class="bi bi-tags"></i> Tipos de Insumo</div>
    <div class="table-responsive">
        <table class="table table-pab table-hover align-middle mb-0">
            <thead><tr><th>Nombre</th><th>Descripción</th><?php if (es_superadmin()): ?><th>Institución</th><?php endif; ?><th></th></tr></thead>
            <tbody>
            <?php foreach ($lista_tipos as $r): ?>
            <tr>
                <td class="fw-semibold"><?= sanitizar($r['nombre']) ?></td>
                <td class="small text-muted"><?= sanitizar($r['descripcion'] ?? '—') ?></td>
                <?php if (es_superadmin()): ?><td class="small"><?= sanitizar($r['inst_nombre']) ?></td><?php endif; ?>
                <td><button title="Editar" class="btn btn-sm btn-outline-primary py-0" onclick="abrirModalTipo(<?= htmlspecialchars(json_encode($r),ENT_QUOTES) ?>)"><i class="bi bi-pencil"></i></button></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Modal Insumo -->
<div class="modal fade" id="modalInsumo" tabindex="-1">
  <div class="modal-dialog"><form method="POST" class="modal-content">
    <?= csrf_campo() ?>
    <input type="hidden" name="accion_form" value="guardar_insumo">
    <input type="hidden" name="id" id="fi2_id">
    <div class="modal-header"><h5 class="modal-title" id="fi2_titulo">Nuevo Insumo</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body row g-3">
        <?php if (es_superadmin()): ?>
        <div class="col-12"><label class="form-label">Institución</label>
        <select name="id_institucion" id="fi2_inst" class="form-select">
            <?php foreach ($instituciones as $i): ?><option value="<?= $i['id'] ?>"><?= sanitizar($i['nombre']) ?></option><?php endforeach; ?>
        </select></div>
        <?php else: ?>
        <input type="hidden" name="id_institucion" value="<?= (int)$id_inst ?>">
        <?php endif; ?>
        <div class="col-12"><label class="form-label">Tipo de Insumo <span class="text-danger">*</span></label>
        <select name="id_tipo_insumo" id="fi2_tipo" class="form-select">
            <?php foreach ($tipos_insumo as $ti): ?><option value="<?= $ti['id'] ?>"><?= sanitizar($ti['nombre']) ?></option><?php endforeach; ?>
        </select></div>
        <div class="col-8"><label class="form-label">Nombre <span class="text-danger">*</span></label><input type="text" name="nombre" id="fi2_nombre" class="form-control"></div>
        <div class="col-4"><label class="form-label">Unidad</label><input type="text" name="unidad_medida" id="fi2_um" class="form-control" placeholder="unid, ml, g..."></div>
        <div class="col-12"><label class="form-label">Descripción</label><textarea name="descripcion" id="fi2_desc" class="form-control" rows="2"></textarea></div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i> Guardar</button>
    </div>
  </form></div>
</div>

<!-- Modal Tipo Insumo -->
<div class="modal fade" id="modalTipoInsumo" tabindex="-1">
  <div class="modal-dialog"><form method="POST" class="modal-content">
    <?= csrf_campo() ?>
    <input type="hidden" name="accion_form" value="guardar_tipo">
    <input type="hidden" name="id" id="fti_id">
    <div class="modal-header"><h5 class="modal-title" id="fti_titulo">Nuevo Tipo de Insumo</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body row g-3">
        <?php if (es_superadmin()): ?>
        <div class="col-12"><label class="form-label">Institución</label>
        <select name="id_institucion" id="fti_inst" class="form-select">
            <?php foreach ($instituciones as $i): ?><option value="<?= $i['id'] ?>"><?= sanitizar($i['nombre']) ?></option><?php endforeach; ?>
        </select></div>
        <?php endif; ?>
        <div class="col-12"><label class="form-label">Nombre <span class="text-danger">*</span></label><input type="text" name="nombre" id="fti_nombre" class="form-control"></div>
        <div class="col-12"><label class="form-label">Descripción</label><textarea name="descripcion" id="fti_desc" class="form-control" rows="2"></textarea></div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i> Guardar</button>
    </div>
  </form></div>
</div>
<script>
function abrirModalInsumo(r) {
    document.getElementById('fi2_titulo').textContent = r ? 'Editar Insumo' : 'Nuevo Insumo';
    document.getElementById('fi2_id').value    = r ? r.id : '';
    document.getElementById('fi2_nombre').value= r ? r.nombre : '';
    document.getElementById('fi2_um').value    = r ? (r.unidad_medida||'') : '';
    document.getElementById('fi2_desc').value  = r ? (r.descripcion||'') : '';
    const st = document.getElementById('fi2_tipo');
    if (r && st) st.value = r.id_tipo_insumo;
    new bootstrap.Modal(document.getElementById('modalInsumo')).show();
}
function abrirModalTipo(r) {
    document.getElementById('fti_titulo').textContent = r ? 'Editar Tipo' : 'Nuevo Tipo de Insumo';
    document.getElementById('fti_id').value    = r ? r.id : '';
    document.getElementById('fti_nombre').value= r ? r.nombre : '';
    document.getElementById('fti_desc').value  = r ? (r.descripcion||'') : '';
    new bootstrap.Modal(document.getElementById('modalTipoInsumo')).show();
}
</script>
<?php layout_footer(); ?>
