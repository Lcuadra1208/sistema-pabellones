<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/layout.php';
requiere_login(ROL_ADMIN_HOSPITAL);
$id_inst = es_superadmin() ? null : usuario_institucion();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_validar()) {
    $id_p    = (int)($_POST['id'] ?? 0);
    $id_ip   = es_superadmin() ? (int)($_POST['id_institucion'] ?? 0) : (int)$id_inst;
    $nombre  = sanitizar($_POST['nombre'] ?? '');
    $desc    = sanitizar($_POST['descripcion'] ?? '');
    $color   = sanitizar($_POST['color_hex'] ?? '#3788d8');
    if (!$id_ip) {
        $error = 'No se pudo determinar la institución. Verifique que su usuario tenga una institución asignada.';
        goto fin2;
    }
    if (!$nombre) { $error = 'El nombre es obligatorio.'; goto fin2; }
    if ($id_p) {
        Database::query("UPDATE tipos_pabellon SET id_institucion=?,nombre=?,descripcion=?,color_hex=? WHERE id=?",
            [$id_ip,$nombre,$desc,$color,$id_p]);
        flash_set('exito','Tipo actualizado.');
    } else {
        Database::insert("INSERT INTO tipos_pabellon (id_institucion,nombre,descripcion,color_hex) VALUES (?,?,?,?)",
            [$id_ip,$nombre,$desc,$color]);
        flash_set('exito','Tipo creado.');
    }
    redirigir('modules/mantenedores/tipos_pabellon.php');
}
fin2:
if (($toggle_id = (int)($_GET['toggle'] ?? 0))) {
    Database::query("UPDATE tipos_pabellon SET activo=NOT activo WHERE id=?",[$toggle_id]);
    redirigir('modules/mantenedores/tipos_pabellon.php');
}

$instituciones = es_superadmin()
    ? Database::fetchAll("SELECT id,nombre FROM instituciones WHERE activo=1 ORDER BY nombre") : [];
$lista = Database::fetchAll(
    "SELECT tp.*, i.nombre AS inst_nombre FROM tipos_pabellon tp
     JOIN instituciones i ON i.id=tp.id_institucion
     " . ($id_inst ? "WHERE tp.id_institucion=$id_inst" : '') . " ORDER BY tp.nombre"
);

layout_header('Tipos de Pabellón');
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <a href="pabellones.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i> Pabellones</a>
    <button class="btn btn-primary" onclick="abrirModal()"><i class="bi bi-plus-lg me-1"></i> Nuevo Tipo</button>
</div>
<?php if ($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>
<div class="pab-card">
    <div class="card-header"><i class="bi bi-tags"></i> Tipos de Pabellón</div>
    <div class="table-responsive">
        <table class="table table-pab table-hover align-middle mb-0">
            <thead><tr><th>Color</th><th>Nombre</th><th>Descripción</th><?php if (es_superadmin()): ?><th>Institución</th><?php endif; ?><th>Estado</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($lista as $r): ?>
            <tr>
                <td><span style="display:inline-block;width:24px;height:24px;border-radius:6px;background:<?= sanitizar($r['color_hex']) ?>"></span></td>
                <td class="fw-semibold"><?= sanitizar($r['nombre']) ?></td>
                <td class="small text-muted"><?= sanitizar($r['descripcion'] ?? '—') ?></td>
                <?php if (es_superadmin()): ?><td class="small"><?= sanitizar($r['inst_nombre']) ?></td><?php endif; ?>
                <td><span class="badge bg-<?= $r['activo']?'success':'secondary' ?>"><?= $r['activo']?'Activo':'Inactivo' ?></span></td>
                <td>
                    <button title="Editar" class="btn btn-sm btn-outline-primary py-0" onclick="abrirModal(<?= htmlspecialchars(json_encode($r),ENT_QUOTES) ?>)"><i class="bi bi-pencil"></i></button>
                    <a href="?toggle=<?= $r['id'] ?>" title="Cambiar estado" class="btn btn-sm btn-outline-warning py-0" onclick="return confirm('¿Cambiar estado?')"><i class="bi bi-toggle-on"></i></a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<div class="modal fade" id="modalTipo" tabindex="-1">
  <div class="modal-dialog"><form method="POST" class="modal-content">
    <?= csrf_campo() ?><input type="hidden" name="id" id="ft_id">
    <div class="modal-header"><h5 class="modal-title" id="ft_titulo">Nuevo Tipo</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body row g-3">
        <?php if (es_superadmin()): ?>
        <div class="col-12"><label class="form-label">Institución</label>
        <select name="id_institucion" id="ft_inst" class="form-select">
            <?php foreach ($instituciones as $i): ?><option value="<?= $i['id'] ?>"><?= sanitizar($i['nombre']) ?></option><?php endforeach; ?>
        </select></div>
        <?php else: ?>
        <input type="hidden" name="id_institucion" value="<?= (int)$id_inst ?>">
        <?php endif; ?>
        <div class="col-8"><label class="form-label">Nombre <span class="text-danger">*</span></label><input type="text" name="nombre" id="ft_nombre" class="form-control"></div>
        <div class="col-4"><label class="form-label">Color</label><input type="color" name="color_hex" id="ft_color" class="form-control form-control-color w-100" value="#3788d8"></div>
        <div class="col-12"><label class="form-label">Descripción</label><textarea name="descripcion" id="ft_desc" class="form-control" rows="2"></textarea></div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i> Guardar</button>
    </div>
  </form></div>
</div>
<script>
function abrirModal(r) {
    document.getElementById('ft_titulo').textContent = r ? 'Editar Tipo' : 'Nuevo Tipo';
    document.getElementById('ft_id').value    = r ? r.id : '';
    document.getElementById('ft_nombre').value= r ? r.nombre : '';
    document.getElementById('ft_color').value = r ? r.color_hex : '#3788d8';
    document.getElementById('ft_desc').value  = r ? (r.descripcion||'') : '';
    new bootstrap.Modal(document.getElementById('modalTipo')).show();
}
</script>
<?php layout_footer(); ?>
