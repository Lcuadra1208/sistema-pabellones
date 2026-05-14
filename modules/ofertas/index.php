<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/layout.php';
requiere_login(); // Acceso a todos; permisos por rol dentro del módulo

$usuario   = usuario_actual();
$id_inst   = usuario_institucion();
$id_admin  = (int)$usuario['id'];
$error     = '';

// ── API AJAX: precio vigente del pabellón ─────────────────
if (isset($_GET['api_precio'])) {
    header('Content-Type: application/json');
    $id_pab = (int)($_GET['id_pabellon'] ?? 0);
    if (!$id_pab) { echo json_encode(['ok'=>false]); exit; }

    $pab = Database::fetchOne(
        "SELECT p.id_tipo_pabellon, tp.nombre AS tipo_nombre
         FROM pabellones p
         JOIN tipos_pabellon tp ON tp.id = p.id_tipo_pabellon
         WHERE p.id = ? AND p.id_institucion = ?",
        [$id_pab, $id_inst]
    );
    if (!$pab) { echo json_encode(['ok'=>false,'msg'=>'Pabellón no encontrado']); exit; }

    $tarifa = Database::fetchOne(
        "SELECT precio_hora, precio_media_hora
         FROM precios_tipo_pabellon
         WHERE id_tipo_pabellon = ?
           AND id_institucion   = ?
           AND vigente_desde   <= CURDATE()
           AND (vigente_hasta IS NULL OR vigente_hasta >= CURDATE())
         ORDER BY vigente_desde DESC
         LIMIT 1",
        [$pab['id_tipo_pabellon'], $id_inst]
    );

    echo json_encode([
        'ok'                => (bool)$tarifa,
        'tipo'              => $pab['tipo_nombre'],
        'precio_hora'       => $tarifa ? (float)$tarifa['precio_hora']       : null,
        'precio_media_hora' => $tarifa ? (float)$tarifa['precio_media_hora'] : null,
        'msg'               => $tarifa ? '' : 'Sin tarifa vigente para este tipo de pabellón.',
    ]);
    exit;
}

// ════════════════════════════════════════════════════════════
// POST
// ════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_validar()) {
    $af = $_POST['accion_form'] ?? '';

    // ── Crear oferta ──────────────────────────────────────
    if ($af === 'crear_oferta' && (es_admin() || es_superadmin())) {
        $id_pab      = (int)($_POST['id_pabellon']  ?? 0);
        $fecha       = sanitizar($_POST['fecha_oferta']  ?? '');
        $hi          = sanitizar($_POST['hora_inicio']   ?? '');
        $hf          = sanitizar($_POST['hora_fin']      ?? '');
        $desc        = sanitizar($_POST['descripcion']   ?? '');
        $modo        = sanitizar($_POST['modo_precio']   ?? '');   // 'porcentaje' | 'valor'
        $precio_normal = (float)($_POST['precio_normal'] ?? 0);

        if (!$id_pab || !$fecha || !$hi || !$hf) {
            $error = 'Complete todos los campos obligatorios.';
            goto fin6;
        }
        if ($hi >= $hf) {
            $error = 'La hora de fin debe ser posterior a la hora de inicio.';
            goto fin6;
        }

        // Calcular precio oferta
        $precio_oferta = null;
        $descuento_pct = null;

        if ($precio_normal > 0 && $modo) {
            if ($modo === 'porcentaje') {
                $pct = (float)($_POST['descuento_pct'] ?? 0);
                if ($pct <= 0 || $pct >= 100) {
                    $error = 'El descuento debe ser entre 1% y 99%.';
                    goto fin6;
                }
                $descuento_pct = $pct;
                $precio_oferta = round($precio_normal * (1 - $pct / 100));
            } elseif ($modo === 'valor') {
                $val = (float)str_replace(['.','$',' '], '', $_POST['precio_oferta_directo'] ?? 0);
                if ($val <= 0 || $val >= $precio_normal) {
                    $error = 'El valor de oferta debe ser mayor a 0 y menor al precio normal.';
                    goto fin6;
                }
                $precio_oferta = $val;
                $descuento_pct = round((1 - $val / $precio_normal) * 100, 2);
            }
        }

        Database::insert(
            "INSERT INTO ofertas_pabellon
             (id_pabellon, id_admin, fecha_oferta, hora_inicio, hora_fin,
              descripcion, precio_normal, precio_oferta, descuento_pct, modo_precio)
             VALUES (?,?,?,?,?,?,?,?,?,?)",
            [$id_pab, $id_admin, $fecha, $hi, $hf,
             $desc, $precio_normal ?: null,
             $precio_oferta, $descuento_pct, $modo ?: null]
        );
        audit('crear_oferta', 'ofertas_pabellon', $id_pab);
        flash_set('exito', 'Oferta publicada correctamente.');
        redirigir('modules/ofertas/index.php');
    }

    // ── Cancelar oferta ───────────────────────────────────
    if ($af === 'cancelar_oferta' && (es_admin() || es_superadmin())) {
        $id_of = (int)($_POST['id_oferta'] ?? 0);
        Database::query("UPDATE ofertas_pabellon SET estado='vencida' WHERE id=?", [$id_of]);
        flash_set('info', 'Oferta cancelada.');
        redirigir('modules/ofertas/index.php');
    }
}
fin6:

// ── Datos ─────────────────────────────────────────────────
$pabellones = Database::fetchAll(
    "SELECT p.id, p.nombre, tp.nombre AS tipo, tp.id AS id_tipo
     FROM pabellones p
     JOIN tipos_pabellon tp ON tp.id = p.id_tipo_pabellon
     WHERE p.activo = 1 AND p.id_institucion = ?
     ORDER BY p.nombre",
    [$id_inst]
);

$lista = Database::fetchAll(
    "SELECT o.*, p.nombre AS pabellon, tp.color_hex,
            COALESCE(s.estado,'—') AS sol_estado
     FROM ofertas_pabellon o
     JOIN pabellones p       ON p.id  = o.id_pabellon
     JOIN tipos_pabellon tp  ON tp.id = p.id_tipo_pabellon
     LEFT JOIN solicitudes_pabellon s ON s.id = o.id_solicitud
     WHERE p.id_institucion = ?
     ORDER BY o.fecha_oferta DESC, o.hora_inicio",
    [$id_inst]
);

// ── Tomar oferta (médico viene desde calendario) ─────────
$oferta_tomar = null;
$tipos_cirugia = [];
$bloques = [];
if (es_medico() && isset($_GET['id_oferta'])) {
    $id_of_get = (int)$_GET['id_oferta'];
    $oferta_tomar = Database::fetchOne(
        "SELECT o.*, p.nombre AS pabellon, p.id_tipo_pabellon,
                i.nombre AS inst_nombre, i.id AS id_institucion_pab
         FROM ofertas_pabellon o
         JOIN pabellones p      ON p.id  = o.id_pabellon
         JOIN instituciones i   ON i.id  = p.id_institucion
         WHERE o.id = ? AND o.estado = 'disponible'",
        [$id_of_get]
    );
    if ($oferta_tomar) {
        // Cirugías de la institución de la oferta
        $tipos_cirugia = Database::fetchAll(
            "SELECT id, nombre, duracion_min FROM tipos_cirugia
             WHERE activo=1 AND id_institucion=? ORDER BY nombre",
            [$oferta_tomar['id_institucion_pab']]
        );
        // Generar bloques horarios de 1, 2 y 3 horas
        $t    = strtotime('1970-01-01 '.$oferta_tomar['hora_inicio']);
        $t_fin= strtotime('1970-01-01 '.$oferta_tomar['hora_fin']);
        for ($dur = 1; $dur <= 3; $dur++) {
            $tt = $t;
            while ($tt + $dur*3600 <= $t_fin) {
                $bloques[$dur][] = [
                    'inicio' => date('H:i', $tt),
                    'fin'    => date('H:i', $tt + $dur*3600),
                    'label'  => date('H:i', $tt).' – '.date('H:i', $tt + $dur*3600)
                              .($dur > 1 ? ' ('.$dur.' h)' : ''),
                ];
                $tt += 3600;
            }
        }
    }
}

// ── POST tomar oferta ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_validar()
    && ($_POST['accion_form'] ?? '') === 'tomar_oferta' && es_medico()) {
    $id_of   = (int)($_POST['id_oferta']      ?? 0);
    $id_tc   = (int)($_POST['id_tipo_cirugia']?? 0);
    $hi_sel  = sanitizar($_POST['hora_inicio_sel'] ?? '');
    $hf_sel  = sanitizar($_POST['hora_fin_sel']    ?? '');
    $pac     = sanitizar($_POST['paciente_nombre'] ?? '');
    $pac_rut = sanitizar($_POST['paciente_rut']    ?? '');
    $diag    = sanitizar($_POST['diagnostico']     ?? '');

    $oferta = Database::fetchOne(
        "SELECT o.*, p.id_institucion FROM ofertas_pabellon o
         JOIN pabellones p ON p.id=o.id_pabellon
         WHERE o.id=? AND o.estado='disponible'", [$id_of]
    );

    if (!$oferta || !$id_tc || !$hi_sel || !$hf_sel) {
        $error = 'Seleccione bloque horario y tipo de cirugía.';
    } elseif ($hi_sel < substr($oferta['hora_inicio'],0,5)
           || $hf_sel > substr($oferta['hora_fin'],0,5)
           || $hi_sel >= $hf_sel) {
        $error = 'El bloque está fuera del rango de la oferta.';
    } else {
        // Validar tipo de cirugía pertenece a la institución
        $tc_ok = Database::fetchOne(
            "SELECT id FROM tipos_cirugia WHERE id=? AND id_institucion=? AND activo=1",
            [$id_tc, $oferta['id_institucion']]
        );
        if (!$tc_ok) {
            $error = 'La cirugía seleccionada no pertenece a esta institución.';
        } else {
            $new_sol = Database::insert(
                "INSERT INTO solicitudes_pabellon
                 (id_institucion,id_medico,id_tipo_cirugia,id_pabellon,
                  fecha_solicitada,hora_inicio,hora_fin,
                  paciente_nombre,paciente_rut,diagnostico,estado)
                 VALUES (?,?,?,?,?,?,?,?,?,?,'aprobada')",
                [$oferta['id_institucion'], (int)usuario_actual()['id'],
                 $id_tc, $oferta['id_pabellon'],
                 $oferta['fecha_oferta'], $hi_sel.':00', $hf_sel.':00',
                 $pac, $pac_rut, $diag]
            );
            // Marcar oferta como reservada
            Database::query(
                "UPDATE ofertas_pabellon SET estado='reservada', id_solicitud=? WHERE id=?",
                [$new_sol, $id_of]
            );
            // Crear nuevas ofertas con los tramos libres restantes
            $hi_of = substr($oferta['hora_inicio'],0,5);
            $hf_of = substr($oferta['hora_fin'],0,5);
            if ($hi_of < $hi_sel) {
                Database::insert(
                    "INSERT INTO ofertas_pabellon
                     (id_pabellon,id_admin,fecha_oferta,hora_inicio,hora_fin,
                      descripcion,precio_normal,precio_oferta,descuento_pct,modo_precio)
                     VALUES (?,?,?,?,?,?,?,?,?,?)",
                    [$oferta['id_pabellon'], $oferta['id_admin'],
                     $oferta['fecha_oferta'], $hi_of.':00', $hi_sel.':00',
                     'Espacio disponible (resto de oferta)',
                     $oferta['precio_normal'], $oferta['precio_oferta'],
                     $oferta['descuento_pct'], $oferta['modo_precio']]
                );
            }
            if ($hf_sel < $hf_of) {
                Database::insert(
                    "INSERT INTO ofertas_pabellon
                     (id_pabellon,id_admin,fecha_oferta,hora_inicio,hora_fin,
                      descripcion,precio_normal,precio_oferta,descuento_pct,modo_precio)
                     VALUES (?,?,?,?,?,?,?,?,?,?)",
                    [$oferta['id_pabellon'], $oferta['id_admin'],
                     $oferta['fecha_oferta'], $hf_sel.':00', $hf_of.':00',
                     'Espacio disponible (resto de oferta)',
                     $oferta['precio_normal'], $oferta['precio_oferta'],
                     $oferta['descuento_pct'], $oferta['modo_precio']]
                );
            }
            audit('tomar_oferta', 'ofertas_pabellon', $id_of);
            flash_set('exito', 'Reserva confirmada: '.$hi_sel.' – '.$hf_sel.'. Su solicitud quedó aprobada.');
            redirigir('modules/solicitudes/index.php');
        }
    }
}

layout_header('Ofertas de Pabellón');

// ── Vista tomar oferta ────────────────────────────────────
if (es_medico() && $oferta_tomar):
    $dur_etiquetas = [1=>'1 hora', 2=>'2 horas', 3=>'3 horas'];
?>
<div class="mb-3">
    <a href="<?= APP_URL ?>/modules/calendario/index.php" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i> Volver al calendario
    </a>
</div>
<?php if ($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>
<div class="pab-card p-4" style="max-width:700px;margin:auto">
    <h6 class="fw-bold mb-4"><i class="bi bi-calendar-check me-2 text-success"></i>Reservar espacio disponible</h6>

    <!-- Info de la oferta -->
    <div class="alert alert-success d-flex gap-3 align-items-start mb-4">
        <i class="bi bi-hospital fs-4 mt-1"></i>
        <div>
            <div class="fw-bold"><?= sanitizar($oferta_tomar['inst_nombre']) ?></div>
            <div><?= sanitizar($oferta_tomar['pabellon']) ?> &mdash; <?= formato_fecha($oferta_tomar['fecha_oferta']) ?></div>
            <div class="small mt-1">
                <i class="bi bi-clock me-1"></i>Rango completo disponible:
                <strong><?= formato_hora($oferta_tomar['hora_inicio']) ?> a <?= formato_hora($oferta_tomar['hora_fin']) ?></strong>
            </div>
            <?php if ($oferta_tomar['precio_oferta']): ?>
            <div class="mt-2 d-flex align-items-center gap-2">
                <span class="text-decoration-line-through text-muted small">
                    <?= formato_peso((float)$oferta_tomar['precio_normal']) ?>
                </span>
                <span class="fw-bold text-success fs-6">
                    <?= formato_peso((float)$oferta_tomar['precio_oferta']) ?>
                </span>
                <span class="badge bg-warning text-dark">
                    -<?= number_format((float)$oferta_tomar['descuento_pct'],1) ?>% oferta
                </span>
            </div>
            <?php elseif ($oferta_tomar['precio_normal']): ?>
            <div class="mt-1 fw-semibold text-dark">
                <?= formato_peso((float)$oferta_tomar['precio_normal']) ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$tipos_cirugia): ?>
    <div class="alert alert-danger">
        <i class="bi bi-exclamation-triangle me-2"></i>
        <strong><?= sanitizar($oferta_tomar['inst_nombre']) ?></strong> no tiene tipos de cirugía registrados.
        Contacte al administrador.
    </div>
    <?php else: ?>
    <form method="POST">
        <?= csrf_campo() ?>
        <input type="hidden" name="accion_form"     value="tomar_oferta">
        <input type="hidden" name="id_oferta"       value="<?= $oferta_tomar['id'] ?>">
        <input type="hidden" name="hora_inicio_sel" id="hi_sel" value="">
        <input type="hidden" name="hora_fin_sel"    id="hf_sel" value="">

        <!-- Selector de bloque -->
        <div class="mb-4">
            <label class="form-label fw-semibold">
                <i class="bi bi-clock me-1 text-primary"></i>
                Seleccione el bloque que necesita <span class="text-danger">*</span>
            </label>
            <div class="small text-muted mb-2">Haga clic en el bloque horario deseado.</div>
            <?php foreach ($bloques as $dur => $grupo): ?>
            <div class="mb-2">
                <div class="text-muted fw-semibold mb-1" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.06em">
                    <?= $dur_etiquetas[$dur] ?>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($grupo as $b): ?>
                    <button type="button" class="btn btn-outline-primary btn-bloque"
                            data-hi="<?= $b['inicio'] ?>" data-hf="<?= $b['fin'] ?>"
                            onclick="seleccionarBloque(this)">
                        <i class="bi bi-clock me-1"></i><?= $b['label'] ?>
                    </button>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <div id="resumen_bloque" class="alert alert-primary py-2 d-none mt-2">
                <i class="bi bi-check2-circle me-2"></i>
                Bloque seleccionado: <strong id="txt_bloque"></strong>
            </div>
        </div>

        <!-- Datos de la cirugía -->
        <div class="row g-3">
            <div class="col-md-12">
                <label class="form-label fw-semibold">Tipo de Cirugía <span class="text-danger">*</span></label>
                <select name="id_tipo_cirugia" id="sel_tc" class="form-select" onchange="sugerirBloque(this)">
                    <?php foreach ($tipos_cirugia as $tc): ?>
                    <option value="<?= $tc['id'] ?>" data-dur="<?= $tc['duracion_min'] ?>">
                        <?= sanitizar($tc['nombre']) ?> (<?= $tc['duracion_min'] ?> min)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Nombre del paciente</label>
                <input type="text" name="paciente_nombre" class="form-control">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">RUT del paciente</label>
                <input type="text" name="paciente_rut" class="form-control"
                       placeholder="12.345.678-9" oninput="formatearRut(this)">
            </div>
            <div class="col-12">
                <label class="form-label fw-semibold">Diagnóstico / Observaciones</label>
                <textarea name="diagnostico" class="form-control" rows="2"></textarea>
            </div>
        </div>

        <div class="mt-4 d-flex gap-2 align-items-center">
            <button type="submit" class="btn btn-success" onclick="return validarBloque()">
                <i class="bi bi-check-circle me-1"></i> Confirmar Reserva
            </button>
            <a href="<?= APP_URL ?>/modules/calendario/index.php" class="btn btn-outline-secondary">
                Cancelar
            </a>
            <span id="msg_sin_bloque" class="text-danger small d-none">
                <i class="bi bi-exclamation-triangle me-1"></i>Seleccione un bloque horario.
            </span>
        </div>
    </form>
    <?php endif; ?>
</div>

<script>
function seleccionarBloque(btn) {
    document.querySelectorAll('.btn-bloque').forEach(b => {
        b.classList.remove('btn-primary','active');
        b.classList.add('btn-outline-primary');
    });
    btn.classList.remove('btn-outline-primary');
    btn.classList.add('btn-primary','active');
    document.getElementById('hi_sel').value = btn.dataset.hi;
    document.getElementById('hf_sel').value = btn.dataset.hf;
    document.getElementById('txt_bloque').textContent = btn.dataset.hi + ' – ' + btn.dataset.hf;
    document.getElementById('resumen_bloque').classList.remove('d-none');
    document.getElementById('msg_sin_bloque').classList.add('d-none');
}
function validarBloque() {
    if (!document.getElementById('hi_sel').value) {
        document.getElementById('msg_sin_bloque').classList.remove('d-none');
        document.querySelector('.btn-bloque')?.scrollIntoView({behavior:'smooth',block:'center'});
        return false;
    }
    return true;
}
function sugerirBloque(sel) {
    const dur = parseInt(sel.options[sel.selectedIndex].dataset.dur) || 60;
    let mejor = null, menorDiff = Infinity;
    document.querySelectorAll('.btn-bloque').forEach(btn => {
        const [hh,mm] = btn.dataset.hi.split(':').map(Number);
        const [fh,fm] = btn.dataset.hf.split(':').map(Number);
        const min = (fh*60+fm)-(hh*60+mm);
        const diff = Math.abs(min-dur);
        if (diff < menorDiff) { menorDiff=diff; mejor=btn; }
    });
    document.querySelectorAll('.btn-bloque').forEach(b=>b.classList.remove('btn-warning'));
    if (mejor) { mejor.classList.add('btn-warning'); mejor.title='Sugerido'; }
}
document.addEventListener('DOMContentLoaded',()=>{
    const sel=document.getElementById('sel_tc'); if(sel) sugerirBloque(sel);
});
</script>
<?php
    layout_footer();
    return; // no mostrar la vista del admin
endif;

// Si es médico sin id_oferta válido o con id_oferta=0 (viene de disponibilidad programada)
// redirigir a solicitudes con los parámetros GET
if (es_medico()) {
    $id_inst_get  = (int)($_GET['id_inst']     ?? 0);
    $id_pab_get   = (int)($_GET['id_pabellon'] ?? 0);
    $fecha_get    = sanitizar($_GET['fecha']       ?? '');
    $hora_ini_get = sanitizar($_GET['hora_inicio'] ?? '');
    $hora_fin_get = sanitizar($_GET['hora_fin']    ?? '');

    if ($id_pab_get && $fecha_get) {
        // Viene de disponibilidad programada — redirigir a solicitudes nueva
        redirigir(
            'modules/solicitudes/index.php?accion=nueva'
            .'&id_inst='.$id_inst_get
            .'&id_pabellon='.$id_pab_get
            .'&fecha='.$fecha_get
            .'&hora_inicio='.urlencode($hora_ini_get)
            .'&hora_fin='.urlencode($hora_fin_get)
        );
    }
    flash_set('info', 'Seleccione un espacio disponible desde el calendario.');
    redirigir('modules/calendario/index.php');
}
?>

<div class="d-flex justify-content-end mb-4">
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalOferta">
        <i class="bi bi-megaphone me-1"></i> Publicar Oferta
    </button>
</div>

<?php if ($error): ?>
<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?= $error ?></div>
<?php endif; ?>

<div class="pab-card">
    <div class="card-header"><i class="bi bi-megaphone"></i> Ofertas publicadas</div>
    <div class="table-responsive">
        <table class="table table-pab table-hover align-middle mb-0">
            <thead><tr>
                <th>Pabellón</th><th>Fecha</th><th>Horario</th>
                <th>Precio normal</th><th>Precio oferta</th><th>Descuento</th>
                <th>Estado</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($lista as $r): ?>
            <tr>
                <td>
                    <span style="display:inline-block;width:10px;height:10px;border-radius:50%;
                                 background:<?= sanitizar($r['color_hex']) ?>;margin-right:6px"></span>
                    <span class="fw-semibold"><?= sanitizar($r['pabellon']) ?></span>
                </td>
                <td><?= formato_fecha($r['fecha_oferta']) ?></td>
                <td><?= formato_hora($r['hora_inicio']) ?> – <?= formato_hora($r['hora_fin']) ?></td>
                <td class="text-muted">
                    <?= $r['precio_normal'] ? formato_peso((float)$r['precio_normal']) : '—' ?>
                </td>
                <td class="fw-bold <?= $r['precio_oferta'] ? 'text-success' : 'text-muted' ?>">
                    <?= $r['precio_oferta'] ? formato_peso((float)$r['precio_oferta']) : '—' ?>
                </td>
                <td>
                    <?php if ($r['descuento_pct']): ?>
                    <span class="badge bg-warning text-dark">
                        -<?= number_format((float)$r['descuento_pct'],1) ?>%
                    </span>
                    <?php else: ?>
                    <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php
                    $bc = match($r['estado']) {
                        'disponible'=>'success','reservada'=>'primary',default=>'secondary'
                    };
                    ?>
                    <span class="badge bg-<?= $bc ?>"><?= $r['estado'] ?></span>
                </td>
                <td>
                    <?php if ($r['estado'] === 'disponible'): ?>
                    <form method="POST" class="d-inline">
                        <?= csrf_campo() ?>
                        <input type="hidden" name="accion_form" value="cancelar_oferta">
                        <input type="hidden" name="id_oferta"   value="<?= $r['id'] ?>">
                        <button type="submit" title="Cancelar" class="btn btn-sm btn-outline-danger py-0"
                                onclick="return confirm('¿Cancelar esta oferta?')">
                            <i class="bi bi-x-circle"></i>
                        </button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$lista): ?>
            <tr><td colspan="8" class="text-center text-muted py-4">Sin ofertas publicadas.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ── Modal crear oferta ────────────────────────────────── -->
<div class="modal fade" id="modalOferta" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <form method="POST" class="modal-content" id="form_oferta">
        <?= csrf_campo() ?>
        <input type="hidden" name="accion_form"   value="crear_oferta">
        <input type="hidden" name="precio_normal" id="campo_precio_normal" value="0">

        <div class="modal-header">
            <h5 class="modal-title"><i class="bi bi-megaphone me-2"></i>Publicar Espacio Disponible</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body row g-3">

            <!-- Pabellón -->
            <div class="col-md-6">
                <label class="form-label fw-semibold">Pabellón <span class="text-danger">*</span></label>
                <select name="id_pabellon" id="sel_pabellon" class="form-select" required
                        onchange="cargarPrecio(this.value)">
                    <option value="">— Seleccione —</option>
                    <?php foreach ($pabellones as $p): ?>
                    <option value="<?= $p['id'] ?>">
                        <?= sanitizar($p['nombre']) ?> (<?= sanitizar($p['tipo']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Fecha -->
            <div class="col-md-6">
                <label class="form-label fw-semibold">Fecha <span class="text-danger">*</span></label>
                <input type="date" name="fecha_oferta" class="form-control"
                       min="<?= date('Y-m-d') ?>" required>
            </div>

            <!-- Horario -->
            <div class="col-md-6">
                <label class="form-label fw-semibold">Hora inicio <span class="text-danger">*</span></label>
                <input type="time" name="hora_inicio" class="form-control" value="08:00" required>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Hora fin <span class="text-danger">*</span></label>
                <input type="time" name="hora_fin" class="form-control" value="12:00" required>
            </div>

            <!-- Panel de precios (se muestra tras seleccionar pabellón) -->
            <div class="col-12" id="panel_precio" style="display:none">
                <div class="rounded-3 p-3" style="background:#f8fafc;border:1px solid #e2e8f0">
                    <div class="row g-3 align-items-start">

                        <!-- Precio normal (informativo) -->
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small text-muted text-uppercase"
                                   style="letter-spacing:.05em">Precio normal</label>
                            <div id="display_precio_normal"
                                 class="fs-4 fw-bold text-dark">—</div>
                            <div id="display_tipo_pabellon" class="text-muted small"></div>
                        </div>

                        <!-- Modo de precio -->
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small text-muted text-uppercase"
                                   style="letter-spacing:.05em">Tipo de oferta</label>
                            <div class="d-flex flex-column gap-2 mt-1">
                                <label class="d-flex align-items-center gap-2 cursor-pointer">
                                    <input type="radio" name="modo_precio" value="porcentaje"
                                           class="form-check-input mt-0"
                                           onchange="cambiarModo('porcentaje')">
                                    <span>Por % de descuento</span>
                                </label>
                                <label class="d-flex align-items-center gap-2 cursor-pointer">
                                    <input type="radio" name="modo_precio" value="valor"
                                           class="form-check-input mt-0"
                                           onchange="cambiarModo('valor')">
                                    <span>Por valor directo</span>
                                </label>
                                <label class="d-flex align-items-center gap-2 cursor-pointer">
                                    <input type="radio" name="modo_precio" value=""
                                           class="form-check-input mt-0" checked
                                           onchange="cambiarModo('')">
                                    <span class="text-muted">Sin precio especial</span>
                                </label>
                            </div>
                        </div>

                        <!-- Inputs dinámicos de precio -->
                        <div class="col-md-4">
                            <!-- Porcentaje -->
                            <div id="bloque_pct" style="display:none">
                                <label class="form-label fw-semibold small text-muted text-uppercase"
                                       style="letter-spacing:.05em">Descuento</label>
                                <div class="input-group">
                                    <input type="number" name="descuento_pct" id="inp_pct"
                                           class="form-control form-control-lg fw-bold text-center"
                                           min="1" max="99" step="1" placeholder="0"
                                           oninput="calcularDesdePorc(this.value)">
                                    <span class="input-group-text fw-bold">%</span>
                                </div>
                                <div id="resultado_pct" class="mt-2 text-center small"></div>
                            </div>
                            <!-- Valor directo -->
                            <div id="bloque_val" style="display:none">
                                <label class="form-label fw-semibold small text-muted text-uppercase"
                                       style="letter-spacing:.05em">Precio oferta</label>
                                <div class="input-group">
                                    <span class="input-group-text">$</span>
                                    <input type="number" name="precio_oferta_directo" id="inp_val"
                                           class="form-control form-control-lg fw-bold"
                                           min="1" step="1000" placeholder="0"
                                           oninput="calcularDesdeValor(this.value)">
                                </div>
                                <div id="resultado_val" class="mt-2 text-center small"></div>
                            </div>
                            <!-- Resultado final -->
                            <div id="bloque_resultado" class="text-center mt-2" style="display:none">
                                <div class="text-muted small">Precio final</div>
                                <div id="display_precio_final"
                                     class="fs-3 fw-bold text-success"></div>
                                <div id="display_ahorro" class="text-muted small"></div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>

            <!-- Sin tarifa -->
            <div class="col-12" id="alerta_sin_tarifa" style="display:none">
                <div class="alert alert-warning py-2 mb-0">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    Este tipo de pabellón no tiene tarifa vigente registrada.
                    <a href="<?= APP_URL ?>/modules/mantenedores/precios.php" target="_blank">
                        Ir a Precios
                    </a>
                </div>
            </div>

            <!-- Descripción -->
            <div class="col-12">
                <label class="form-label fw-semibold">Descripción <span class="text-muted fw-normal small">(opcional)</span></label>
                <textarea name="descripcion" class="form-control" rows="2"
                          placeholder="Ej: Espacio liberado por cancelación de última hora..."></textarea>
            </div>

        </div>

        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-megaphone me-1"></i> Publicar Oferta
            </button>
        </div>
    </form>
  </div>
</div>

<script>
const API_URL = '?api_precio=1&id_pabellon=';
let precioNormal = 0;

function fmt(n) {
    return '$ ' + Math.round(n).toLocaleString('es-CL');
}

async function cargarPrecio(idPab) {
    const panel     = document.getElementById('panel_precio');
    const alertaSin = document.getElementById('alerta_sin_tarifa');
    panel.style.display     = 'none';
    alertaSin.style.display = 'none';
    resetModo();

    if (!idPab) return;

    try {
        const res  = await fetch(API_URL + idPab);
        const data = await res.json();

        if (!data.ok) {
            alertaSin.style.display = 'block';
            document.getElementById('campo_precio_normal').value = 0;
            precioNormal = 0;
            panel.style.display = 'block'; // igual mostrar para descripción
            document.getElementById('display_precio_normal').textContent = 'Sin tarifa';
            document.getElementById('display_tipo_pabellon').textContent = data.tipo || '';
            return;
        }

        precioNormal = data.precio_hora;
        document.getElementById('campo_precio_normal').value    = precioNormal;
        document.getElementById('display_precio_normal').textContent = fmt(precioNormal);
        document.getElementById('display_tipo_pabellon').textContent =
            data.tipo + ' · tarifa/hora';
        panel.style.display = 'block';

    } catch(e) {
        console.error(e);
    }
}

function cambiarModo(modo) {
    document.getElementById('bloque_pct').style.display      = modo==='porcentaje' ? 'block' : 'none';
    document.getElementById('bloque_val').style.display      = modo==='valor'      ? 'block' : 'none';
    document.getElementById('bloque_resultado').style.display= 'none';
    document.getElementById('inp_pct').value = '';
    if (document.getElementById('inp_val')) document.getElementById('inp_val').value = '';
}

function calcularDesdePorc(pct) {
    pct = parseFloat(pct);
    if (!precioNormal || isNaN(pct) || pct <= 0 || pct >= 100) {
        document.getElementById('bloque_resultado').style.display = 'none';
        return;
    }
    const final  = Math.round(precioNormal * (1 - pct/100));
    const ahorro = precioNormal - final;
    document.getElementById('display_precio_final').textContent = fmt(final);
    document.getElementById('display_ahorro').textContent       = 'Ahorro: ' + fmt(ahorro);
    document.getElementById('bloque_resultado').style.display   = 'block';
}

function calcularDesdeValor(val) {
    val = parseFloat(String(val).replace(/\./g,''));
    if (!precioNormal || isNaN(val) || val <= 0) {
        document.getElementById('bloque_resultado').style.display = 'none';
        return;
    }
    const pct    = ((1 - val/precioNormal)*100).toFixed(1);
    const ahorro = precioNormal - val;
    document.getElementById('display_precio_final').textContent = fmt(val);
    document.getElementById('display_ahorro').textContent =
        (ahorro > 0 ? 'Descuento: ' + pct + '%  ·  Ahorro: ' + fmt(ahorro)
                    : '⚠️ Valor mayor al precio normal');
    document.getElementById('bloque_resultado').style.display = 'block';
}

function resetModo() {
    document.querySelectorAll('input[name="modo_precio"]').forEach(r => {
        if (r.value === '') r.checked = true;
    });
    cambiarModo('');
    document.getElementById('display_precio_normal').textContent = '—';
    document.getElementById('display_tipo_pabellon').textContent = '';
}
</script>
<?php layout_footer(); ?>
