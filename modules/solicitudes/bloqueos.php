<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/layout.php';
requiere_login(ROL_ADMIN_HOSPITAL);

$id_inst = usuario_institucion();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_validar()) {
    $id_pab  = (int)($_POST['id_pabellon'] ?? 0);
    $finicio = sanitizar($_POST['fecha_inicio'] ?? '');
    $ffin    = sanitizar($_POST['fecha_fin'] ?? '');
    $motivo  = sanitizar($_POST['motivo'] ?? '');
    if ($id_pab && $finicio && $ffin && $motivo) {
        Database::insert(
            "INSERT INTO bloqueos_pabellon (id_pabellon,id_admin,fecha_inicio,fecha_fin,motivo) VALUES (?,?,?,?,?)",
            [$id_pab,(int)usuario_actual()['id'],$finicio,$ffin,$motivo]
        );
        audit('bloquear_pabellon','bloqueos_pabellon',$id_pab,$motivo);
        flash_set('exito','Pabellón bloqueado correctamente.');
    }
    redirigir('modules/solicitudes/bloqueos.php');
}

if (($desbloquear = (int)($_GET['desbloquear'] ?? 0))) {
    Database::query("UPDATE bloqueos_pabellon SET activo=0 WHERE id=?",[$desbloquear]);
    flash_set('info','Bloqueo eliminado.');
    redirigir('modules/solicitudes/bloqueos.php');
}

$lista = Database::fetchAll(
    "SELECT b.*,p.nombre AS pabellon,CONCAT(u.nombres,' ',u.apellidos) AS admin
     FROM bloqueos_pabellon b
     JOIN pabellones p ON p.id=b.id_pabellon
     JOIN usuarios u ON u.id=b.id_admin
     WHERE p.id_institucion=? AND b.activo=1
     ORDER BY b.fecha_inicio DESC",
    [$id_inst]
);

layout_header('Bloqueos de Pabellones');
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <a href="../calendario/index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Calendario</a>
    <button class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#modalBloqueo"><i class="bi bi-lock me-1"></i>Nuevo Bloqueo</button>
</div>
<div class="pab-card">
    <div class="card-header"><i class="bi bi-lock-fill text-danger"></i> Bloqueos activos</div>
    <div class="table-responsive">
        <table class="table table-pab table-hover align-middle mb-0">
            <thead><tr><th>Pabellón</th><th>Desde</th><th>Hasta</th><th>Motivo</th><th>Por</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($lista as $r): ?>
            <tr>
                <td class="fw-semibold"><?= sanitizar($r['pabellon']) ?></td>
                <td><?= formato_fecha_hora($r['fecha_inicio']) ?></td>
                <td><?= formato_fecha_hora($r['fecha_fin']) ?></td>
                <td><?= sanitizar($r['motivo']) ?></td>
                <td class="small text-muted"><?= sanitizar($r['admin']) ?></td>
                <td>
                    <a href="?desbloquear=<?= $r['id'] ?>"
                       class="btn btn-sm btn-outline-success py-0"
                       onclick="return confirm('¿Eliminar este bloqueo?')">
                        <i class="bi bi-unlock"></i> Desbloquear
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$lista): ?>
            <tr><td colspan="6" class="text-center text-muted py-4">No hay bloqueos activos.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
// Modal bloqueo
$pabellones = Database::fetchAll("SELECT id,nombre FROM pabellones WHERE id_institucion=? AND activo=1 ORDER BY nombre",[$id_inst]);
?>
<div class="modal fade" id="modalBloqueo" tabindex="-1">
  <div class="modal-dialog"><form method="POST" class="modal-content">
    <?= csrf_campo() ?>
    <div class="modal-header"><h5 class="modal-title">Bloquear Pabellón</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body row g-3">
        <div class="col-12"><label class="form-label">Pabellón</label>
        <select name="id_pabellon" class="form-select">
            <?php foreach ($pabellones as $p): ?><option value="<?= $p['id'] ?>"><?= sanitizar($p['nombre']) ?></option><?php endforeach; ?>
        </select></div>
        <div class="col-6"><label class="form-label">Desde <span class="text-danger">*</span></label><input type="datetime-local" name="fecha_inicio" class="form-control" required></div>
        <div class="col-6"><label class="form-label">Hasta <span class="text-danger">*</span></label><input type="datetime-local" name="fecha_fin" class="form-control" required></div>
        <div class="col-12"><label class="form-label">Motivo <span class="text-danger">*</span></label><input type="text" name="motivo" class="form-control" placeholder="Mantenimiento, limpieza..." required></div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-danger"><i class="bi bi-lock me-1"></i>Bloquear</button>
    </div>
  </form></div>
</div>
<?php layout_footer(); ?>
