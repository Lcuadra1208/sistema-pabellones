<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/layout.php';
requiere_login(ROL_ADMIN_HOSPITAL);

$id_inst = es_superadmin() ? null : usuario_institucion();
$error   = '';

// Guardar
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_validar()) {
    $id_post     = (int)($_POST['id'] ?? 0);
    $id_inst_p   = es_superadmin() ? (int)($_POST['id_institucion'] ?? 0) : (int)$id_inst;
    $id_tipo     = (int)($_POST['id_tipo_pabellon'] ?? 0);
    $nombre      = sanitizar($_POST['nombre'] ?? '');
    $numero      = sanitizar($_POST['numero'] ?? '');
    $piso        = sanitizar($_POST['piso'] ?? '');
    $cap         = sanitizar($_POST['capacidad_desc'] ?? '');

    if (!$nombre || !$id_tipo || !$id_inst_p) {
        $error = 'Nombre, tipo de pabellón e institución son obligatorios.';
    } else {
        if ($id_post) {
            Database::query(
                "UPDATE pabellones SET id_institucion=?,id_tipo_pabellon=?,nombre=?,numero=?,piso=?,capacidad_desc=? WHERE id=?",
                [$id_inst_p,$id_tipo,$nombre,$numero,$piso,$cap,$id_post]
            );
            audit('editar_pabellon','pabellones',$id_post);
            flash_set('exito','Pabellón actualizado.');
        } else {
            $new = Database::insert(
                "INSERT INTO pabellones (id_institucion,id_tipo_pabellon,nombre,numero,piso,capacidad_desc) VALUES (?,?,?,?,?,?)",
                [$id_inst_p,$id_tipo,$nombre,$numero,$piso,$cap]
            );
            audit('crear_pabellon','pabellones',$new);
            flash_set('exito','Pabellón creado.');
        }
        redirigir('modules/mantenedores/pabellones.php');
    }
}

if (($_GET['accion'] ?? '') === 'toggle' && ($tid = (int)($_GET['id'] ?? 0))) {
    Database::query("UPDATE pabellones SET activo = NOT activo WHERE id=?",[$tid]);
    flash_set('info','Estado actualizado.');
    redirigir('modules/mantenedores/pabellones.php');
}

// Tipos de pabellón filtrados
$tipos = Database::fetchAll(
    "SELECT tp.*, i.nombre AS inst_nombre FROM tipos_pabellon tp
     JOIN instituciones i ON i.id=tp.id_institucion
     WHERE tp.activo=1" . ($id_inst ? " AND tp.id_institucion=$id_inst" : '') . " ORDER BY tp.nombre"
);

$instituciones = es_superadmin()
    ? Database::fetchAll("SELECT id,nombre FROM instituciones WHERE activo=1 ORDER BY nombre")
    : [];

$lista = Database::fetchAll(
    "SELECT p.*, tp.nombre AS tipo_nombre, tp.color_hex, i.nombre AS inst_nombre
     FROM pabellones p
     JOIN tipos_pabellon tp ON tp.id = p.id_tipo_pabellon
     JOIN instituciones i ON i.id = p.id_institucion
     " . ($id_inst ? "WHERE p.id_institucion=$id_inst" : '') . "
     ORDER BY i.nombre, p.nombre"
);

layout_header('Pabellones');

// Verificar pre-requisitos antes de crear pabellones
$inst_check = $id_inst ?? 0;
if (es_admin() && $inst_check) {
    // Verificar si la tabla precios_tipo_pabellon existe antes de consultarla
    $tabla_precios_existe = Database::fetchOne(
        "SELECT COUNT(*) n FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'precios_tipo_pabellon'"
    )['n'] ?? 0;

    $n_precios = 0;
    if ($tabla_precios_existe) {
        $n_precios = Database::fetchOne(
            "SELECT COUNT(*) n FROM precios_tipo_pabellon
             WHERE id_institucion=? AND (vigente_hasta IS NULL OR vigente_hasta >= CURDATE())",
            [$inst_check]
        )['n'] ?? 0;
    }

    $prereq = [
        'Tipos de Cirugía' => Database::fetchOne("SELECT COUNT(*) n FROM tipos_cirugia WHERE id_institucion=? AND activo=1",[$inst_check])['n'] ?? 0,
        'Insumos'          => Database::fetchOne("SELECT COUNT(*) n FROM insumos WHERE id_institucion=? AND activo=1",[$inst_check])['n'] ?? 0,
        'Precios'          => $n_precios,
        'Acreditaciones'   => Database::fetchOne("SELECT COUNT(*) n FROM acreditaciones WHERE id_institucion=? AND activo=1",[$inst_check])['n'] ?? 0,
    ];
    $faltantes = array_keys(array_filter($prereq, fn($v) => $v == 0));
}
?>
<?php if (es_admin() && !empty($faltantes)): ?>
<div class="alert alert-warning d-flex align-items-start gap-3 mb-4">
    <i class="bi bi-exclamation-triangle-fill fs-4 mt-1 flex-shrink-0 text-warning"></i>
    <div>
        <div class="fw-bold">Configure los parámetros previos antes de crear pabellones</div>
        <div class="small mt-1">Para que los pabellones funcionen correctamente, primero debe registrar:</div>
        <div class="d-flex flex-wrap gap-2 mt-2">
            <?php
            $links = [
                'Tipos de Cirugía' => 'tipos_cirugia.php',
                'Insumos'          => 'insumos.php',
                'Precios'          => 'precios.php',
                'Acreditaciones'   => 'acreditaciones.php',
            ];
            foreach ($faltantes as $nombre):
                $href = $links[$nombre] ?? '#';
            ?>
            <a href="<?= APP_URL ?>/modules/mantenedores/<?= $href ?>"
               class="btn btn-sm btn-warning">
                <i class="bi bi-arrow-right-circle me-1"></i><?= $nombre ?>
            </a>
            <?php endforeach; ?>
        </div>
        <div class="small mt-2 text-muted">Puede crear pabellones de todas formas, pero algunas funciones no estarán disponibles hasta completar la configuración.</div>
    </div>
</div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div></div>
    <div class="d-flex gap-2">
        <a href="tipos_pabellon.php" class="btn btn-outline-secondary">
            <i class="bi bi-tags me-1"></i> Tipos de Pabellón
        </a>
        <button class="btn btn-primary" onclick="abrirModal()">
            <i class="bi bi-plus-lg me-1"></i> Nuevo Pabellón
        </button>
    </div>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>

<div class="pab-card">
    <div class="card-header"><i class="bi bi-door-open"></i> Pabellones registrados</div>
    <div class="table-responsive">
        <table class="table table-pab table-hover align-middle mb-0">
            <thead><tr>
                <th>Nombre</th><th>N°</th><th>Piso</th><th>Tipo</th>
                <?php if (es_superadmin()): ?><th>Institución</th><?php endif; ?>
                <th>Estado</th><th>Acciones</th>
            </tr></thead>
            <tbody>
            <?php foreach ($lista as $r): ?>
            <tr>
                <td class="fw-semibold"><?= sanitizar($r['nombre']) ?></td>
                <td><?= sanitizar($r['numero'] ?? '—') ?></td>
                <td><?= sanitizar($r['piso'] ?? '—') ?></td>
                <td>
                    <span class="badge" style="background:<?= sanitizar($r['color_hex']) ?>20;color:<?= sanitizar($r['color_hex']) ?>;border:1px solid <?= sanitizar($r['color_hex']) ?>50">
                        <?= sanitizar($r['tipo_nombre']) ?>
                    </span>
                </td>
                <?php if (es_superadmin()): ?>
                <td class="small text-muted"><?= sanitizar($r['inst_nombre']) ?></td>
                <?php endif; ?>
                <td><span class="badge bg-<?= $r['activo']?'success':'secondary' ?>"><?= $r['activo']?'Activo':'Inactivo' ?></span></td>
                <td>
                    <button title="Editar" class="btn btn-sm btn-outline-primary py-0"
                            onclick="abrirModal(<?= htmlspecialchars(json_encode($r),ENT_QUOTES) ?>)">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <a href="?accion=toggle&id=<?= $r['id'] ?>"
                       title="<?= $r['activo'] ? 'Desactivar' : 'Activar' ?>" class="btn btn-sm btn-outline-<?= $r['activo']?'warning':'success' ?> py-0"
                       onclick="return confirm('¿Cambiar estado?')">
                        <i class="bi bi-<?= $r['activo']?'pause':'play' ?>"></i>
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="modalPabellon" tabindex="-1">
  <div class="modal-dialog">
    <form method="POST" class="modal-content">
        <?= csrf_campo() ?>
        <input type="hidden" name="id" id="fp_id">
        <div class="modal-header">
            <h5 class="modal-title" id="fp_titulo">Nuevo Pabellón</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body row g-3">
            <?php if (es_superadmin()): ?>
            <div class="col-12">
                <label class="form-label">Institución <span class="text-danger">*</span></label>
                <select name="id_institucion" id="fp_inst" class="form-select">
                    <?php foreach ($instituciones as $i): ?>
                    <option value="<?= $i['id'] ?>"><?= sanitizar($i['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-12">
                <label class="form-label">Tipo de Pabellón <span class="text-danger">*</span></label>
                <select name="id_tipo_pabellon" id="fp_tipo" class="form-select">
                    <?php foreach ($tipos as $t): ?>
                    <option value="<?= $t['id'] ?>"><?= sanitizar($t['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-8">
                <label class="form-label">Nombre <span class="text-danger">*</span></label>
                <input type="text" name="nombre" id="fp_nombre" class="form-control" placeholder="Pabellón Central A">
            </div>
            <div class="col-4">
                <label class="form-label">N°</label>
                <input type="text" name="numero" id="fp_numero" class="form-control">
            </div>
            <div class="col-6">
                <label class="form-label">Piso</label>
                <input type="text" name="piso" id="fp_piso" class="form-control">
            </div>
            <div class="col-6">
                <label class="form-label">Descripción capacidad</label>
                <input type="text" name="capacidad_desc" id="fp_cap" class="form-control">
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
    document.getElementById('fp_titulo').textContent = r ? 'Editar Pabellón' : 'Nuevo Pabellón';
    document.getElementById('fp_id').value     = r ? r.id : '';
    document.getElementById('fp_nombre').value = r ? r.nombre : '';
    document.getElementById('fp_numero').value = r ? (r.numero||'') : '';
    document.getElementById('fp_piso').value   = r ? (r.piso||'') : '';
    document.getElementById('fp_cap').value    = r ? (r.capacidad_desc||'') : '';
    const st = document.getElementById('fp_tipo');
    if (r && st) st.value = r.id_tipo_pabellon;
    new bootstrap.Modal(document.getElementById('modalPabellon')).show();
}
</script>
<?php layout_footer(); ?>
