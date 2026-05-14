<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/layout.php';
requiere_login(ROL_ADMIN_HOSPITAL);
$id_inst = es_superadmin() ? null : usuario_institucion();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_validar()) {
    $af = $_POST['accion_form'] ?? '';
    if ($af === 'acreditar') {
        $id_med  = (int)($_POST['id_medico'] ?? 0);
        $id_ip   = es_superadmin() ? (int)($_POST['id_institucion'] ?? 0) : (int)$id_inst;
        $desde   = sanitizar($_POST['fecha_inicio'] ?? date('Y-m-d'));
        $hasta   = sanitizar($_POST['fecha_fin'] ?? '') ?: null;
        $obs     = sanitizar($_POST['observacion'] ?? '');
        if (!$id_med || !$id_ip) { $error='Médico e institución requeridos.'; goto fin7; }
        Database::query(
            "INSERT INTO acreditaciones (id_medico,id_institucion,fecha_inicio,fecha_fin,observacion)
             VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE activo=1,fecha_inicio=VALUES(fecha_inicio),
             fecha_fin=VALUES(fecha_fin),observacion=VALUES(observacion)",
            [$id_med,$id_ip,$desde,$hasta,$obs]
        );
        audit('acreditar_medico','acreditaciones',$id_med,"Institución $id_ip");
        flash_set('exito','Acreditación guardada.');
        redirigir('modules/mantenedores/acreditaciones.php');
    }
    if ($af === 'revocar') {
        $id_ac = (int)($_POST['id_ac'] ?? 0);
        Database::query("UPDATE acreditaciones SET activo=0 WHERE id=?",[$id_ac]);
        flash_set('info','Acreditación revocada.');
        redirigir('modules/mantenedores/acreditaciones.php');
    }
}
fin7:

$instituciones = es_superadmin()
    ? Database::fetchAll("SELECT id,nombre FROM instituciones WHERE activo=1 ORDER BY nombre") : [];
$medicos = Database::fetchAll(
    "SELECT id,CONCAT(nombres,' ',apellidos) AS nombre_completo,especialidad
     FROM usuarios WHERE id_rol=? AND activo=1 ORDER BY apellidos",[ROL_MEDICO]
);
$lista = Database::fetchAll(
    "SELECT a.*,
            CONCAT(u.nombres,' ',u.apellidos) AS medico, u.especialidad,
            i.nombre AS inst_nombre
     FROM acreditaciones a
     JOIN usuarios u ON u.id=a.id_medico
     JOIN instituciones i ON i.id=a.id_institucion
     " . ($id_inst ? "WHERE a.id_institucion=$id_inst" : '') . "
     ORDER BY i.nombre,u.apellidos"
);

layout_header('Acreditaciones de Médicos');
?>
<div class="d-flex justify-content-end mb-3">
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAcred">
        <i class="bi bi-person-check me-1"></i> Nueva Acreditación
    </button>
</div>
<?php if ($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>
<div class="pab-card">
    <div class="card-header"><i class="bi bi-building-check"></i> Acreditaciones vigentes e históricas</div>
    <div class="table-responsive">
        <table class="table table-pab table-hover align-middle mb-0">
            <thead><tr><th>Médico</th><th>Especialidad</th><?php if (es_superadmin()): ?><th>Institución</th><?php endif; ?><th>Desde</th><th>Hasta</th><th>Estado</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($lista as $r): ?>
            <?php $vig = $r['activo'] && (!$r['fecha_fin'] || $r['fecha_fin'] >= date('Y-m-d')); ?>
            <tr>
                <td class="fw-semibold"><?= sanitizar($r['medico']) ?></td>
                <td class="small text-muted"><?= sanitizar($r['especialidad'] ?? '—') ?></td>
                <?php if (es_superadmin()): ?><td class="small"><?= sanitizar($r['inst_nombre']) ?></td><?php endif; ?>
                <td><?= formato_fecha($r['fecha_inicio']) ?></td>
                <td><?= $r['fecha_fin'] ? formato_fecha($r['fecha_fin']) : '<span class="text-muted">Indefinida</span>' ?></td>
                <td><span class="badge bg-<?= $vig?'success':'secondary' ?>"><?= $vig?'Vigente':'Revocada' ?></span></td>
                <td>
                    <?php if ($vig): ?>
                    <form method="POST" class="d-inline">
                        <?= csrf_campo() ?>
                        <input type="hidden" name="accion_form" value="revocar">
                        <input type="hidden" name="id_ac" value="<?= $r['id'] ?>">
                        <button type="submit" title="Revocar acreditación" class="btn btn-sm btn-outline-danger py-0"
                                onclick="return confirm('¿Revocar esta acreditación?')">
                            <i class="bi bi-person-x"></i>
                        </button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="modalAcred" tabindex="-1">
  <div class="modal-dialog"><form method="POST" class="modal-content">
    <?= csrf_campo() ?>
    <input type="hidden" name="accion_form" value="acreditar">
    <div class="modal-header"><h5 class="modal-title">Nueva Acreditación</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body row g-3">
        <?php if (es_superadmin()): ?>
        <div class="col-12"><label class="form-label">Institución</label>
        <select name="id_institucion" class="form-select">
            <?php foreach ($instituciones as $i): ?><option value="<?= $i['id'] ?>"><?= sanitizar($i['nombre']) ?></option><?php endforeach; ?>
        </select></div>
        <?php else: ?>
        <input type="hidden" name="id_institucion" value="<?= (int)$id_inst ?>">
        <?php endif; ?>
        <div class="col-12"><label class="form-label">Médico <span class="text-danger">*</span></label>
        <select name="id_medico" class="form-select">
            <?php foreach ($medicos as $m): ?><option value="<?= $m['id'] ?>"><?= sanitizar($m['nombre_completo']) ?><?= $m['especialidad'] ? ' ('.$m['especialidad'].')' : '' ?></option><?php endforeach; ?>
        </select></div>
        <div class="col-6"><label class="form-label">Desde <span class="text-danger">*</span></label><input type="date" name="fecha_inicio" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
        <div class="col-6"><label class="form-label">Hasta</label><input type="date" name="fecha_fin" class="form-control"><div class="form-text">Dejar vacío para indefinida.</div></div>
        <div class="col-12"><label class="form-label">Observación</label><textarea name="observacion" class="form-control" rows="2"></textarea></div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i> Guardar</button>
    </div>
  </form></div>
</div>
<?php layout_footer(); ?>
