<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/layout.php';
requiere_login(ROL_ADMIN_HOSPITAL);
$id_inst = es_superadmin() ? null : usuario_institucion();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_validar()) {
    $accion_f = $_POST['accion_form'] ?? '';

    if ($accion_f === 'guardar') {
        $id_p    = (int)($_POST['id'] ?? 0);
        $id_ip   = es_superadmin() ? (int)($_POST['id_institucion'] ?? 0) : (int)$id_inst;
        $id_tp   = (int)($_POST['id_tipo_pabellon'] ?? 0);
        $nombre  = sanitizar($_POST['nombre'] ?? '');
        $desc    = sanitizar($_POST['descripcion'] ?? '');
        $dur     = (int)($_POST['duracion_min'] ?? 60);
        if (!$nombre || !$id_ip || !$id_tp) { $error = 'Campos obligatorios incompletos.'; goto fin3; }
        if ($id_p) {
            Database::query("UPDATE tipos_cirugia SET id_institucion=?,id_tipo_pabellon=?,nombre=?,descripcion=?,duracion_min=? WHERE id=?",
                [$id_ip,$id_tp,$nombre,$desc,$dur,$id_p]);
            flash_set('exito','Cirugía actualizada.');
        } else {
            Database::insert("INSERT INTO tipos_cirugia (id_institucion,id_tipo_pabellon,nombre,descripcion,duracion_min) VALUES (?,?,?,?,?)",
                [$id_ip,$id_tp,$nombre,$desc,$dur]);
            flash_set('exito','Cirugía creada.');
        }
        redirigir('modules/mantenedores/tipos_cirugia.php');
    }

    // Guardar insumo asociado
    if ($accion_f === 'add_insumo') {
        $id_tc = (int)($_POST['id_tipo_cirugia'] ?? 0);
        $id_in = (int)($_POST['id_insumo'] ?? 0);
        $cant  = (float)($_POST['cantidad'] ?? 1);
        $obs   = sanitizar($_POST['observacion'] ?? '');
        if ($id_tc && $id_in) {
            Database::query(
                "INSERT INTO cirugia_insumos (id_tipo_cirugia,id_insumo,cantidad,observacion)
                 VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE cantidad=VALUES(cantidad),observacion=VALUES(observacion)",
                [$id_tc,$id_in,$cant,$obs]
            );
            flash_set('exito','Insumo asociado.');
        }
        redirigir('modules/mantenedores/tipos_cirugia.php?ver='.$id_tc);
    }

    if ($accion_f === 'del_insumo') {
        $id_ci = (int)($_POST['id_ci'] ?? 0);
        $id_tc = (int)($_POST['id_tc'] ?? 0);
        if ($id_ci) Database::query("DELETE FROM cirugia_insumos WHERE id=?",[$id_ci]);
        redirigir('modules/mantenedores/tipos_cirugia.php?ver='.$id_tc);
    }
}
fin3:
if (($t_id = (int)($_GET['toggle'] ?? 0))) {
    Database::query("UPDATE tipos_cirugia SET activo=NOT activo WHERE id=?",[$t_id]);
    redirigir('modules/mantenedores/tipos_cirugia.php');
}

$instituciones = es_superadmin()
    ? Database::fetchAll("SELECT id,nombre FROM instituciones WHERE activo=1 ORDER BY nombre") : [];
$tipos_pabellon = Database::fetchAll(
    "SELECT id,nombre FROM tipos_pabellon WHERE activo=1" . ($id_inst ? " AND id_institucion=$id_inst" : '') . " ORDER BY nombre"
);
$lista = Database::fetchAll(
    "SELECT tc.*, tp.nombre AS tipo_pab, i.nombre AS inst_nombre
     FROM tipos_cirugia tc
     JOIN tipos_pabellon tp ON tp.id=tc.id_tipo_pabellon
     JOIN instituciones i ON i.id=tc.id_institucion
     " . ($id_inst ? "WHERE tc.id_institucion=$id_inst" : '') . " ORDER BY tc.nombre"
);

// Ver insumos de una cirugía
$ver_id = (int)($_GET['ver'] ?? 0);
$cirugia_ver = null;
$insumos_cirugia = [];
$insumos_disponibles = [];
if ($ver_id) {
    $cirugia_ver = Database::fetchOne("SELECT * FROM tipos_cirugia WHERE id=?",[$ver_id]);
    $insumos_cirugia = Database::fetchAll(
        "SELECT ci.id, ci.cantidad, ci.observacion, ins.nombre AS insumo_nombre, ti.nombre AS tipo_nombre
         FROM cirugia_insumos ci
         JOIN insumos ins ON ins.id=ci.id_insumo
         JOIN tipos_insumo ti ON ti.id=ins.id_tipo_insumo
         WHERE ci.id_tipo_cirugia=? ORDER BY ti.nombre, ins.nombre",[$ver_id]
    );
    $ids_ya = array_column($insumos_cirugia,'id') ? implode(',',array_map('intval',array_column($insumos_cirugia,'id'))) : '0';
    $insumos_disponibles = Database::fetchAll(
        "SELECT ins.id, ins.nombre, ti.nombre AS tipo FROM insumos ins
         JOIN tipos_insumo ti ON ti.id=ins.id_tipo_insumo
         WHERE ins.activo=1 AND ins.id NOT IN (SELECT id_insumo FROM cirugia_insumos WHERE id_tipo_cirugia=?)
         " . ($id_inst ? "AND ins.id_institucion=$id_inst" : '') . " ORDER BY ti.nombre,ins.nombre",[$ver_id]
    );
}

layout_header('Tipos de Cirugía');
?>
<?php if ($ver_id && $cirugia_ver): ?>
<!-- Vista de insumos -->
<div class="mb-3">
    <a href="tipos_cirugia.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Volver</a>
</div>
<div class="pab-card mb-4">
    <div class="card-header">
        <i class="bi bi-box-seam"></i> Insumos de: <strong><?= sanitizar($cirugia_ver['nombre']) ?></strong>
    </div>
    <div class="p-3">
        <form method="POST" class="row g-2 align-items-end mb-3">
            <?= csrf_campo() ?>
            <input type="hidden" name="accion_form" value="add_insumo">
            <input type="hidden" name="id_tipo_cirugia" value="<?= $ver_id ?>">
            <div class="col-md-5">
                <label class="form-label small">Insumo</label>
                <select name="id_insumo" class="form-select form-select-sm">
                    <?php foreach ($insumos_disponibles as $ins): ?>
                    <option value="<?= $ins['id'] ?>"><?= sanitizar($ins['tipo']) ?> — <?= sanitizar($ins['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small">Cantidad</label>
                <input type="number" name="cantidad" class="form-control form-control-sm" value="1" min="0.1" step="0.1">
            </div>
            <div class="col-md-3">
                <label class="form-label small">Observación</label>
                <input type="text" name="observacion" class="form-control form-control-sm">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-sm btn-success w-100"><i class="bi bi-plus-lg me-1"></i> Agregar</button>
            </div>
        </form>
        <table class="table table-sm table-pab align-middle">
            <thead><tr><th>Insumo</th><th>Tipo</th><th>Cantidad</th><th>Observación</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($insumos_cirugia as $ci): ?>
            <tr>
                <td><?= sanitizar($ci['insumo_nombre']) ?></td>
                <td class="small text-muted"><?= sanitizar($ci['tipo_nombre']) ?></td>
                <td><?= $ci['cantidad'] ?></td>
                <td class="small"><?= sanitizar($ci['observacion'] ?? '') ?></td>
                <td>
                    <form method="POST" class="d-inline">
                        <?= csrf_campo() ?>
                        <input type="hidden" name="accion_form" value="del_insumo">
                        <input type="hidden" name="id_ci" value="<?= $ci['id'] ?>">
                        <input type="hidden" name="id_tc" value="<?= $ver_id ?>">
                        <button type="submit" title="Eliminar" class="btn btn-sm btn-outline-danger py-0"
                                onclick="return confirm('¿Quitar insumo?')">
                            <i class="bi bi-trash3"></i>
                        </button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$insumos_cirugia): ?>
            <tr><td colspan="5" class="text-center text-muted py-3">Sin insumos asociados aún.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php else: ?>
<!-- Lista principal -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div></div>
    <button class="btn btn-primary" onclick="abrirModal()"><i class="bi bi-plus-lg me-1"></i> Nueva Cirugía</button>
</div>
<?php if ($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>
<div class="pab-card">
    <div class="card-header"><i class="bi bi-scissors"></i> Tipos de Cirugía</div>
    <div class="table-responsive">
        <table class="table table-pab table-hover align-middle mb-0">
            <thead><tr><th>Nombre</th><th>Pabellón requerido</th><th>Duración</th><?php if (es_superadmin()): ?><th>Institución</th><?php endif; ?><th>Estado</th><th>Acciones</th></tr></thead>
            <tbody>
            <?php foreach ($lista as $r): ?>
            <tr>
                <td class="fw-semibold"><?= sanitizar($r['nombre']) ?></td>
                <td><span class="badge bg-light text-dark"><?= sanitizar($r['tipo_pab']) ?></span></td>
                <td><i class="bi bi-clock text-muted me-1"></i><?= $r['duracion_min'] ?> min</td>
                <?php if (es_superadmin()): ?><td class="small"><?= sanitizar($r['inst_nombre']) ?></td><?php endif; ?>
                <td><span class="badge bg-<?= $r['activo']?'success':'secondary' ?>"><?= $r['activo']?'Activa':'Inactiva' ?></span></td>
                <td class="d-flex gap-1">
                    <button title="Editar" class="btn btn-sm btn-outline-primary py-0" onclick="abrirModal(<?= htmlspecialchars(json_encode($r),ENT_QUOTES) ?>)"><i class="bi bi-pencil"></i></button>
                    <a href="?ver=<?= $r['id'] ?>" class="btn btn-sm btn-outline-info py-0" title="Insumos"><i class="bi bi-box-seam"></i></a>
                    <a href="?toggle=<?= $r['id'] ?>" title="Cambiar estado" class="btn btn-sm btn-outline-warning py-0" onclick="return confirm('¿Cambiar estado?')"><i class="bi bi-toggle-on"></i></a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="modal fade" id="modalCirugia" tabindex="-1">
  <div class="modal-dialog"><form method="POST" class="modal-content">
    <?= csrf_campo() ?>
    <input type="hidden" name="accion_form" value="guardar">
    <input type="hidden" name="id" id="fc_id">
    <div class="modal-header"><h5 class="modal-title" id="fc_titulo">Nueva Cirugía</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body row g-3">
        <?php if (es_superadmin()): ?>
        <div class="col-12"><label class="form-label">Institución</label>
        <select name="id_institucion" id="fc_inst" class="form-select">
            <?php foreach ($instituciones as $i): ?><option value="<?= $i['id'] ?>"><?= sanitizar($i['nombre']) ?></option><?php endforeach; ?>
        </select></div>
        <?php else: ?>
        <input type="hidden" name="id_institucion" value="<?= (int)$id_inst ?>">
        <?php endif; ?>
        <div class="col-12"><label class="form-label">Tipo de Pabellón requerido <span class="text-danger">*</span></label>
        <select name="id_tipo_pabellon" id="fc_tipopab" class="form-select">
            <?php foreach ($tipos_pabellon as $tp): ?><option value="<?= $tp['id'] ?>"><?= sanitizar($tp['nombre']) ?></option><?php endforeach; ?>
        </select></div>
        <div class="col-8"><label class="form-label">Nombre <span class="text-danger">*</span></label><input type="text" name="nombre" id="fc_nombre" class="form-control"></div>
        <div class="col-4"><label class="form-label">Duración (min)</label><input type="number" name="duracion_min" id="fc_dur" class="form-control" value="60" min="15"></div>
        <div class="col-12"><label class="form-label">Descripción</label><textarea name="descripcion" id="fc_desc" class="form-control" rows="2"></textarea></div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i> Guardar</button>
    </div>
  </form></div>
</div>
<script>
function abrirModal(r) {
    document.getElementById('fc_titulo').textContent = r ? 'Editar Cirugía' : 'Nueva Cirugía';
    document.getElementById('fc_id').value    = r ? r.id : '';
    document.getElementById('fc_nombre').value= r ? r.nombre : '';
    document.getElementById('fc_dur').value   = r ? r.duracion_min : 60;
    document.getElementById('fc_desc').value  = r ? (r.descripcion||'') : '';
    const st = document.getElementById('fc_tipopab');
    if (r && st) st.value = r.id_tipo_pabellon;
    new bootstrap.Modal(document.getElementById('modalCirugia')).show();
}
</script>
<?php layout_footer(); ?>
