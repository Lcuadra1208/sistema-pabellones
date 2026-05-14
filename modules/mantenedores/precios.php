<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/layout.php';
requiere_login(ROL_ADMIN_HOSPITAL);

$id_inst = es_superadmin() ? null : usuario_institucion();
$error   = '';

// ── Guardar ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_validar()) {
    $id_p    = (int)($_POST['id'] ?? 0);
    $id_ip   = es_superadmin() ? (int)($_POST['id_institucion'] ?? 0) : (int)$id_inst;
    $id_tp   = (int)($_POST['id_tipo_pabellon'] ?? 0);
    $p_hora  = (float)str_replace(['.','$',' '], '', $_POST['precio_hora'] ?? 0);
    $p_media = trim($_POST['precio_media_hora'] ?? '');
    $p_media = $p_media !== '' ? (float)str_replace(['.','$',' '], '', $p_media) : null;
    $desde   = sanitizar($_POST['vigente_desde'] ?? '');
    $hasta   = sanitizar($_POST['vigente_hasta'] ?? '') ?: null;
    $obs     = sanitizar($_POST['observacion'] ?? '');

    if (!$id_tp || $p_hora <= 0 || !$desde) {
        $error = 'Tipo de pabellón, precio por hora y fecha de inicio son obligatorios.';
        goto fin_precio;
    }

    if (!$id_p) {
        // Cerrar tarifa vigente anterior del mismo tipo
        Database::query(
            "UPDATE precios_tipo_pabellon
             SET vigente_hasta = DATE_SUB(?, INTERVAL 1 DAY)
             WHERE id_institucion=? AND id_tipo_pabellon=? AND vigente_hasta IS NULL",
            [$desde, $id_ip, $id_tp]
        );
        Database::insert(
            "INSERT INTO precios_tipo_pabellon
             (id_institucion, id_tipo_pabellon, precio_hora, precio_media_hora,
              vigente_desde, vigente_hasta, observacion)
             VALUES (?,?,?,?,?,?,?)",
            [$id_ip, $id_tp, $p_hora, $p_media, $desde, $hasta, $obs]
        );
        flash_set('exito', 'Tarifa registrada correctamente.');
    } else {
        Database::query(
            "UPDATE precios_tipo_pabellon
             SET precio_hora=?, precio_media_hora=?, vigente_desde=?,
                 vigente_hasta=?, observacion=?
             WHERE id=?",
            [$p_hora, $p_media, $desde, $hasta, $obs, $id_p]
        );
        flash_set('exito', 'Tarifa actualizada correctamente.');
    }
    audit('precio_tipo_pabellon', 'precios_tipo_pabellon', $id_tp);
    redirigir('modules/mantenedores/precios.php');
}
fin_precio:

// ── Datos ─────────────────────────────────────────────────
$where = $id_inst ? "WHERE tp.id_institucion = $id_inst" : '';

$instituciones = es_superadmin()
    ? Database::fetchAll("SELECT id,nombre FROM instituciones WHERE activo=1 ORDER BY nombre")
    : [];

$tipos_pabellon = Database::fetchAll(
    "SELECT tp.id, tp.nombre, tp.color_hex, i.nombre AS inst_nombre
     FROM tipos_pabellon tp
     JOIN instituciones i ON i.id = tp.id_institucion
     $where AND tp.activo = 1
     ORDER BY tp.nombre"
);

$lista = Database::fetchAll(
    "SELECT ptp.*, tp.nombre AS tipo_pabellon, tp.color_hex, i.nombre AS inst_nombre
     FROM precios_tipo_pabellon ptp
     JOIN tipos_pabellon tp  ON tp.id  = ptp.id_tipo_pabellon
     JOIN instituciones i    ON i.id   = ptp.id_institucion
     " . ($id_inst ? "WHERE ptp.id_institucion = $id_inst" : '') . "
     ORDER BY tp.nombre, ptp.vigente_desde DESC"
);

layout_header('Precios de Pabellones');
?>

<div class="d-flex justify-content-end mb-4">
    <button class="btn btn-primary" onclick="abrirModal()">
        <i class="bi bi-plus-lg me-1"></i> Nueva Tarifa
    </button>
</div>

<?php if ($error): ?>
<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?= $error ?></div>
<?php endif; ?>

<!-- Resumen tarifas vigentes -->
<?php
$vigentes = array_filter($lista, fn($r) => !$r['vigente_hasta'] || $r['vigente_hasta'] >= date('Y-m-d'));
if ($vigentes):
?>
<div class="row g-3 mb-4">
<?php foreach ($vigentes as $v): ?>
<div class="col-md-4 col-6">
    <div class="pab-card p-3 d-flex align-items-center gap-3">
        <span style="width:40px;height:40px;border-radius:10px;flex-shrink:0;
                     background:<?= sanitizar($v['color_hex']) ?>25;
                     border:2px solid <?= sanitizar($v['color_hex']) ?>;
                     display:flex;align-items:center;justify-content:center">
            <i class="bi bi-door-open" style="color:<?= sanitizar($v['color_hex']) ?>"></i>
        </span>
        <div class="min-w-0">
            <div class="fw-semibold small text-truncate"><?= sanitizar($v['tipo_pabellon']) ?></div>
            <div class="text-success fw-bold"><?= formato_peso((float)$v['precio_hora']) ?><span class="text-muted fw-normal small">/hr</span></div>
            <?php if ($v['precio_media_hora']): ?>
            <div class="text-muted" style="font-size:.75rem"><?= formato_peso((float)$v['precio_media_hora']) ?>/30 min</div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Tabla completa con historial -->
<div class="pab-card">
    <div class="card-header">
        <i class="bi bi-currency-dollar"></i> Tarifas de Tipos de Pabellón
        <span class="badge bg-secondary ms-2"><?= count($lista) ?> registros</span>
    </div>
    <div class="table-responsive">
        <table class="table table-pab table-hover align-middle mb-0">
            <thead><tr>
                <th>Tipo de Pabellón</th>
                <?php if (es_superadmin()): ?><th>Institución</th><?php endif; ?>
                <th>Precio / hora</th>
                <th>Precio / ½ hora</th>
                <th>Vigente desde</th>
                <th>Vigente hasta</th>
                <th>Estado</th>
                <th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($lista as $r):
                $vig = !$r['vigente_hasta'] || $r['vigente_hasta'] >= date('Y-m-d');
            ?>
            <tr>
                <td>
                    <span style="display:inline-block;width:10px;height:10px;border-radius:50%;
                                 background:<?= sanitizar($r['color_hex']) ?>;margin-right:6px"></span>
                    <span class="fw-semibold"><?= sanitizar($r['tipo_pabellon']) ?></span>
                </td>
                <?php if (es_superadmin()): ?>
                <td class="small text-muted"><?= sanitizar($r['inst_nombre']) ?></td>
                <?php endif; ?>
                <td class="fw-bold text-success"><?= formato_peso((float)$r['precio_hora']) ?></td>
                <td class="text-muted"><?= $r['precio_media_hora'] ? formato_peso((float)$r['precio_media_hora']) : '—' ?></td>
                <td><?= formato_fecha($r['vigente_desde']) ?></td>
                <td>
                    <?php if ($r['vigente_hasta']): ?>
                        <?= formato_fecha($r['vigente_hasta']) ?>
                    <?php else: ?>
                        <span class="badge bg-success">Vigente actual</span>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="badge bg-<?= $vig ? 'success' : 'secondary' ?>">
                        <?= $vig ? 'Vigente' : 'Histórico' ?>
                    </span>
                </td>
                <td>
                    <?php if ($vig): ?>
                    <button title="Editar" class="btn btn-sm btn-outline-primary py-0"
                            onclick="abrirModal(<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>)">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$lista): ?>
            <tr>
                <td colspan="8" class="text-center text-muted py-5">
                    <i class="bi bi-currency-dollar fs-1 d-block mb-2 opacity-25"></i>
                    No hay tarifas registradas.<br>
                    <small>Haga clic en "Nueva Tarifa" para comenzar.</small>
                </td>
            </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ── Modal ────────────────────────────────────────────── -->
<div class="modal fade" id="modalPrecio" tabindex="-1">
  <div class="modal-dialog">
    <form method="POST" class="modal-content">
        <?= csrf_campo() ?>
        <input type="hidden" name="id" id="fp_id">
        <div class="modal-header bg-light">
            <h5 class="modal-title">
                <i class="bi bi-currency-dollar me-2 text-success"></i>
                <span id="fp_titulo">Nueva Tarifa de Pabellón</span>
            </h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body row g-3">

            <?php if (es_superadmin()): ?>
            <div class="col-12">
                <label class="form-label fw-semibold">Institución</label>
                <select name="id_institucion" id="fp_inst" class="form-select">
                    <?php foreach ($instituciones as $i): ?>
                    <option value="<?= $i['id'] ?>"><?= sanitizar($i['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="col-12">
                <label class="form-label fw-semibold">Tipo de Pabellón <span class="text-danger">*</span></label>
                <select name="id_tipo_pabellon" id="fp_tp" class="form-select">
                    <?php foreach ($tipos_pabellon as $tp): ?>
                    <option value="<?= $tp['id'] ?>">
                        <?= sanitizar($tp['nombre']) ?>
                        <?= es_superadmin() ? ' — '.sanitizar($tp['inst_nombre']) : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-6">
                <label class="form-label fw-semibold">
                    Precio por hora (CLP) <span class="text-danger">*</span>
                </label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-currency-dollar"></i></span>
                    <input type="number" name="precio_hora" id="fp_hora"
                           class="form-control" min="0" step="100" required>
                </div>
            </div>

            <div class="col-6">
                <label class="form-label fw-semibold">
                    Precio por ½ hora (CLP)
                    <span class="text-muted fw-normal small">(opcional)</span>
                </label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-currency-dollar"></i></span>
                    <input type="number" name="precio_media_hora" id="fp_media"
                           class="form-control" min="0" step="100">
                </div>
                <div class="form-text">Si no se ingresa, se calcula al 60% de la tarifa hora.</div>
            </div>

            <div class="col-6">
                <label class="form-label fw-semibold">Vigente desde <span class="text-danger">*</span></label>
                <input type="date" name="vigente_desde" id="fp_desde"
                       class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>

            <div class="col-6">
                <label class="form-label fw-semibold">Vigente hasta</label>
                <input type="date" name="vigente_hasta" id="fp_hasta" class="form-control">
                <div class="form-text"><i class="bi bi-info-circle me-1"></i>Dejar vacío para tarifa indefinida (recomendado).</div>
            </div>

            <div class="col-12">
                <label class="form-label">Observación</label>
                <input type="text" name="observacion" id="fp_obs"
                       class="form-control" placeholder="Ej: Tarifa convenio, temporada alta...">
            </div>

        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-success">
                <i class="bi bi-save me-1"></i> Guardar Tarifa
            </button>
        </div>
    </form>
  </div>
</div>

<script>
function abrirModal(r) {
    const esNuevo = !r;
    document.getElementById('fp_titulo').textContent = esNuevo ? 'Nueva Tarifa de Pabellón' : 'Editar Tarifa';
    document.getElementById('fp_id').value    = r ? r.id : '';
    document.getElementById('fp_hora').value  = r ? r.precio_hora : '';
    document.getElementById('fp_media').value = r ? (r.precio_media_hora || '') : '';
    document.getElementById('fp_desde').value = r ? r.vigente_desde : '<?= date('Y-m-d') ?>';
    document.getElementById('fp_hasta').value = r ? (r.vigente_hasta || '') : '';
    document.getElementById('fp_obs').value   = r ? (r.observacion || '') : '';

    const selTp = document.getElementById('fp_tp');
    if (r && selTp) selTp.value = r.id_tipo_pabellon;

    <?php if (es_superadmin()): ?>
    const selInst = document.getElementById('fp_inst');
    if (r && selInst) selInst.value = r.id_institucion;
    <?php endif; ?>

    // Sugerir media hora = 60% del precio hora si está vacío
    document.getElementById('fp_hora').addEventListener('input', function() {
        const media = document.getElementById('fp_media');
        if (!media.value) {
            media.placeholder = Math.round(this.value * 0.6);
        }
    });

    new bootstrap.Modal(document.getElementById('modalPrecio')).show();
}
</script>
<?php layout_footer(); ?>
