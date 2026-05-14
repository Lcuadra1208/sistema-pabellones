<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/brevo.php';
requiere_login();

$usuario   = usuario_actual();
$id_inst   = usuario_institucion();
$id_medico = (int)$usuario['id'];
$accion    = $_GET['accion'] ?? 'lista';
$id        = (int)($_GET['id'] ?? 0);
$error     = '';

// ── GUARDAR SOLICITUD ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_validar()) {
    $af = $_POST['accion_form'] ?? '';

    if ($af === 'nueva_solicitud') {
        $id_inst_sol = es_medico()
            ? (int)($_POST['id_institucion'] ?? 0)
            : (int)$id_inst;
        $id_tc       = (int)($_POST['id_tipo_cirugia']  ?? 0);
        $id_pab_sol  = (int)($_POST['id_pabellon_sol']  ?? 0) ?: null;
        $fecha   = sanitizar($_POST['fecha_solicitada'] ?? '');
        $hinicio = sanitizar($_POST['hora_inicio'] ?? '');
        $hfin    = sanitizar($_POST['hora_fin'] ?? '');
        $pac_nom = sanitizar($_POST['paciente_nombre'] ?? '');
        $pac_rut = sanitizar($_POST['paciente_rut'] ?? '');
        $diag    = sanitizar($_POST['diagnostico'] ?? '');

        if (!$id_inst_sol || !$id_tc || !$fecha || !$hinicio || !$hfin) {
            $error = 'Complete todos los campos obligatorios.';
        } elseif ($hinicio >= $hfin) {
            $error = 'La hora de fin debe ser posterior a la hora de inicio.';
        } else {
            // ── Validar que el médico no tenga otra cirugía en el mismo horario ──
            // Normalizar horas a HH:MM:SS para comparación correcta con la BD
            $hi_norm = strlen($hinicio) === 5 ? $hinicio.':00' : $hinicio;
            $hf_norm = strlen($hfin)    === 5 ? $hfin.':00'    : $hfin;

            $conflicto_medico = Database::fetchOne(
                "SELECT s.id, p.nombre AS pabellon, i.nombre AS institucion,
                        s.hora_inicio, s.hora_fin
                 FROM solicitudes_pabellon s
                 LEFT JOIN pabellones p ON p.id = s.id_pabellon
                 JOIN instituciones i ON i.id = s.id_institucion
                 WHERE s.id_medico = ?
                   AND s.fecha_solicitada = ?
                   AND s.estado IN ('pendiente','aprobada')
                   AND TIME(s.hora_fin)   > TIME(?)
                   AND TIME(s.hora_inicio) < TIME(?)",
                [$id_medico, $fecha, $hi_norm, $hf_norm]
            );
            if ($conflicto_medico) {
                $hi = substr($conflicto_medico['hora_inicio'],0,5);
                $hf = substr($conflicto_medico['hora_fin'],0,5);
                $error = '⚠️ Conflicto de horario: ya tiene una cirugía de '.$hi.' a '.$hf
                       . ' en ' . $conflicto_medico['institucion']
                       . ($conflicto_medico['pabellon'] ? ' — '.$conflicto_medico['pabellon'] : '')
                       . '. No puede estar en dos lugares a la vez. Elija otro horario.';
            }

            if (!$error) {
            // Verificar conflicto de horario en el pabellón
            $conflicto = Database::fetchOne(
                "SELECT id FROM solicitudes_pabellon
                 WHERE id_institucion=? AND fecha_solicitada=?
                   AND estado IN ('aprobada','pendiente')
                   AND NOT (TIME(hora_fin) <= TIME(?) OR TIME(hora_inicio) >= TIME(?))",
                [$id_inst_sol,$fecha,$hi_norm,$hf_norm]
            );
            // Si viene con pabellón pre-asignado → aprobar automáticamente
            $estado_inicial = $id_pab_sol ? 'aprobada' : 'pendiente';

            $new = Database::insert(
                "INSERT INTO solicitudes_pabellon
                 (id_institucion,id_medico,id_tipo_cirugia,id_pabellon,fecha_solicitada,hora_inicio,hora_fin,
                  paciente_nombre,paciente_rut,diagnostico,estado,id_admin_gestion,fecha_gestion)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)",
                [$id_inst_sol,$id_medico,$id_tc,$id_pab_sol,$fecha,$hinicio,$hfin,
                 $pac_nom,$pac_rut,$diag,$estado_inicial,
                 $id_pab_sol ? $id_medico : null,
                 $id_pab_sol ? date('Y-m-d H:i:s') : null]
            );
            // Historial
            $obs_hist = $id_pab_sol
                ? 'Solicitud creada con pabellón pre-asignado — aprobada automáticamente.'
                : 'Solicitud creada — pendiente de aprobación.';
            Database::insert(
                "INSERT INTO solicitudes_historial (id_solicitud,id_usuario,estado_antes,estado_despues,observacion)
                 VALUES (?,?,NULL,?,?)",
                [$new, $id_medico, $estado_inicial, $obs_hist]
            );
            audit('crear_solicitud','solicitudes_pabellon',$new);
            if ($estado_inicial === 'pendiente') {
                // Notificar al admin solo si queda pendiente
                $admin_inst = Database::fetchOne(
                    "SELECT u.*, i.logo FROM usuarios u
                     LEFT JOIN instituciones i ON i.id=u.id_institucion
                     WHERE u.id_institucion=? AND u.id_rol=? AND u.activo=1 LIMIT 1",
                    [$id_inst_sol, ROL_ADMIN_HOSPITAL]
                );
                if ($admin_inst) {
                    $sol_data = Database::fetchOne(
                        "SELECT s.*, tc.nombre AS cirugia,
                                CONCAT(u.nombres,' ',u.apellidos) AS medico
                         FROM solicitudes_pabellon s
                         JOIN tipos_cirugia tc ON tc.id=s.id_tipo_cirugia
                         JOIN usuarios u ON u.id=s.id_medico
                         WHERE s.id=?", [$new]
                    );
                    $logo_url = $admin_inst['logo'] ? APP_URL.'/'.$admin_inst['logo'] : '';
                    BrevoMailer::notificarNuevaSolicitud($sol_data, $admin_inst, $logo_url);
                }
                flash_set('exito', 'Solicitud enviada. Quedará pendiente hasta ser aprobada por el administrador.');
            } else {
                // Aprobada automáticamente — notificar al médico
                $medico_data = Database::fetchOne("SELECT * FROM usuarios WHERE id=?", [$id_medico]);
                $inst_logo   = Database::fetchOne("SELECT logo FROM instituciones WHERE id=?", [$id_inst_sol]);
                $pab_nombre  = Database::fetchOne("SELECT nombre FROM pabellones WHERE id=?", [$id_pab_sol])['nombre'] ?? '—';
                $tc_nombre   = Database::fetchOne("SELECT nombre FROM tipos_cirugia WHERE id=?", [$id_tc])['nombre'] ?? '—';
                $sol_full = [
                    'estado'          => 'aprobada',
                    'pabellon_nombre' => $pab_nombre,
                    'cirugia'         => $tc_nombre,
                    'fecha_solicitada'=> $fecha,
                    'hora_inicio'     => $hinicio,
                    'hora_fin'        => $hfin,
                    'observacion'     => 'Su solicitud fue aprobada automáticamente.',
                ];
                $logo_url = ($inst_logo['logo'] ?? '') ? APP_URL.'/'.$inst_logo['logo'] : '';
                if ($medico_data) BrevoMailer::notificarCambioSolicitud($sol_full, $medico_data, $logo_url);
                flash_set('exito', '✅ Solicitud confirmada y aprobada. Pabellón: '.$pab_nombre.' · '.$fecha.' '.$hinicio.'–'.$hfin);
            }
            } // fin else sin conflicto médico
            redirigir('modules/solicitudes/index.php');
        }
    }

    // Admin gestiona solicitud (aprobar/rechazar + asignar pabellón)
    if ($af === 'gestionar' && !es_medico()) {
        $id_sol    = (int)($_POST['id_sol'] ?? 0);
        $nuevo_est = sanitizar($_POST['estado'] ?? '');
        $id_pab    = (int)($_POST['id_pabellon'] ?? 0) ?: null;
        $obs       = sanitizar($_POST['observacion'] ?? '');
        $estados_validos = ['aprobada','rechazada','cancelada','completada'];
        if ($id_sol && in_array($nuevo_est,$estados_validos)) {
            // Obtener estado anterior para historial
            $sol_antes = Database::fetchOne("SELECT * FROM solicitudes_pabellon WHERE id=?",[$id_sol]);

            // ── VALIDAR CONFLICTO DE HORARIO al aprobar con pabellón ──
            if ($nuevo_est === 'aprobada' && $id_pab) {
                $conflicto = Database::fetchOne(
                    "SELECT s.id,
                            CONCAT(u.nombres,' ',u.apellidos) AS medico,
                            s.hora_inicio, s.hora_fin
                     FROM solicitudes_pabellon s
                     JOIN usuarios u ON u.id = s.id_medico
                     WHERE s.id_pabellon   = ?
                       AND s.fecha_solicitada = ?
                       AND s.estado IN ('aprobada')
                       AND s.id != ?
                       AND NOT (s.hora_fin <= ? OR s.hora_inicio >= ?)",
                    [
                        $id_pab,
                        $sol_antes['fecha_solicitada'],
                        $id_sol,
                        $sol_antes['hora_inicio'],
                        $sol_antes['hora_fin'],
                    ]
                );
                if ($conflicto) {
                    $hi = substr($conflicto['hora_inicio'], 0, 5);
                    $hf = substr($conflicto['hora_fin'],    0, 5);
                    flash_set('error',
                        '⚠️ Conflicto de horario: el pabellón ya está asignado a '
                        .$conflicto['medico'].' de '.$hi.' a '.$hf
                        .' ese día. Elija otro pabellón u otro horario.'
                    );
                    redirigir('modules/solicitudes/index.php?id='.$id_sol);
                }
            }

            Database::query(
                "UPDATE solicitudes_pabellon
                 SET estado=?,id_pabellon=?,observacion=?,id_admin_gestion=?,fecha_gestion=NOW()
                 WHERE id=?",
                [$nuevo_est,$id_pab,$obs,$id_medico,$id_sol]
            );
            // Historial
            Database::insert(
                "INSERT INTO solicitudes_historial
                 (id_solicitud,id_usuario,estado_antes,estado_despues,id_pabellon_antes,id_pabellon_despues,observacion)
                 VALUES (?,?,?,?,?,?,?)",
                [$id_sol,$id_medico,
                 $sol_antes['estado'],$nuevo_est,
                 $sol_antes['id_pabellon'],$id_pab,
                 $obs ?: null]
            );
            audit('gestionar_solicitud','solicitudes_pabellon',$id_sol,$nuevo_est);
            // Notificar al médico si el estado cambió
            if ($sol_antes['estado'] !== $nuevo_est
                && in_array($nuevo_est,['aprobada','rechazada','cancelada','completada'])) {
                $medico_data = Database::fetchOne(
                    "SELECT u.* FROM usuarios u WHERE u.id=?",[$sol_antes['id_medico']]
                );
                $inst_data = Database::fetchOne(
                    "SELECT logo FROM instituciones WHERE id=?",[$sol_antes['id_institucion']]
                );
                $pab_nombre = $id_pab ? Database::fetchOne("SELECT nombre FROM pabellones WHERE id=?",[$id_pab])['nombre'] ?? '—' : '—';
                $tc_nombre  = Database::fetchOne("SELECT nombre FROM tipos_cirugia WHERE id=?",[$sol_antes['id_tipo_cirugia']])['nombre'] ?? '—';
                $sol_full   = array_merge($sol_antes,[
                    'estado'         => $nuevo_est,
                    'pabellon_nombre'=> $pab_nombre,
                    'cirugia'        => $tc_nombre,
                    'observacion'    => $obs,
                ]);
                $logo_url = ($inst_data['logo'] ?? '') ? APP_URL.'/'.$inst_data['logo'] : '';
                if ($medico_data) BrevoMailer::notificarCambioSolicitud($sol_full, $medico_data, $logo_url);
            }
            // Si se rechaza o cancela y vino de una oferta, liberar el tramo
            if (in_array($nuevo_est,['rechazada','cancelada'])) {
                $oferta_orig = Database::fetchOne(
                    "SELECT * FROM ofertas_pabellon WHERE id_solicitud=?", [$id_sol]
                );
                if ($oferta_orig && $sol_antes['id_pabellon']) {
                    Database::insert(
                        "INSERT INTO ofertas_pabellon
                         (id_pabellon, id_admin, fecha_oferta, hora_inicio, hora_fin,
                          descripcion, precio_normal, precio_oferta, descuento_pct, modo_precio, estado)
                         VALUES (?,?,?,?,?,?,?,?,?,?,'disponible')",
                        [
                            $sol_antes['id_pabellon'],
                            $oferta_orig['id_admin'],
                            $sol_antes['fecha_solicitada'],
                            $sol_antes['hora_inicio'],
                            $sol_antes['hora_fin'],
                            'Espacio liberado por '.$nuevo_est.' de solicitud',
                            $oferta_orig['precio_normal'],
                            $oferta_orig['precio_oferta'],
                            $oferta_orig['descuento_pct'],
                            $oferta_orig['modo_precio'],
                        ]
                    );
                }
            }

            flash_set('exito','Solicitud actualizada a: '.$nuevo_est.'.');
            redirigir('modules/solicitudes/index.php');
        }
    }

    // Cancelar (médico cancela la propia)
    if ($af === 'cancelar_propia') {
        $id_sol = (int)($_POST['id_sol'] ?? 0);
        $sol = Database::fetchOne("SELECT * FROM solicitudes_pabellon WHERE id=? AND id_medico=?",[$id_sol,$id_medico]);
        if ($sol && in_array($sol['estado'],['pendiente','aprobada'])) {
            Database::query("UPDATE solicitudes_pabellon SET estado='cancelada' WHERE id=?",[$id_sol]);

            // Historial
            Database::insert(
                "INSERT INTO solicitudes_historial (id_solicitud,id_usuario,estado_antes,estado_despues,observacion)
                 VALUES (?,?,?,?,'Cancelada por el médico')",
                [$id_sol, $id_medico, $sol['estado'], 'cancelada']
            );

            // Si la solicitud vino de una oferta, restaurar el tramo como disponible
            $oferta_orig = Database::fetchOne(
                "SELECT * FROM ofertas_pabellon WHERE id_solicitud=?", [$id_sol]
            );
            if ($oferta_orig) {
                // Marcar la oferta original como vencida (ya fue partida en tramos)
                // y crear nueva oferta con el tramo liberado
                Database::insert(
                    "INSERT INTO ofertas_pabellon
                     (id_pabellon, id_admin, fecha_oferta, hora_inicio, hora_fin,
                      descripcion, precio_normal, precio_oferta, descuento_pct, modo_precio, estado)
                     VALUES (?,?,?,?,?,?,?,?,?,?,'disponible')",
                    [
                        $sol['id_pabellon'],
                        $oferta_orig['id_admin'],
                        $sol['fecha_solicitada'],
                        $sol['hora_inicio'],
                        $sol['hora_fin'],
                        'Espacio liberado por cancelación de solicitud',
                        $oferta_orig['precio_normal'],
                        $oferta_orig['precio_oferta'],
                        $oferta_orig['descuento_pct'],
                        $oferta_orig['modo_precio'],
                    ]
                );
            }

            audit('cancelar_solicitud','solicitudes_pabellon',$id_sol);
            flash_set('info','Solicitud cancelada. El horario quedó disponible nuevamente.');
            redirigir('modules/solicitudes/index.php');
        }
    }
}

// ── Pre-llenar desde disponibilidad programada (GET) ─────
$pre_id_inst    = (int)($_GET['id_inst']    ?? 0);
$pre_id_pabellon= (int)($_GET['id_pabellon']?? 0);
$pre_fecha      = sanitizar($_GET['fecha']      ?? '');
$pre_hora_ini   = sanitizar($_GET['hora_inicio'] ?? '');
$pre_hora_fin   = sanitizar($_GET['hora_fin']    ?? '');

// ── DATOS para formularios ────────────────────────────────
// Instituciones donde el médico está acreditado (para solicitar)
$instituciones_medico = [];
if (es_medico()) {
    $instituciones_medico = Database::fetchAll(
        "SELECT i.id,i.nombre FROM instituciones i
         JOIN acreditaciones a ON a.id_institucion=i.id
         WHERE a.id_medico=? AND a.activo=1 AND i.activo=1
         UNION
         SELECT id,nombre FROM instituciones WHERE id=? AND activo=1",
        [$id_medico,$id_inst ?? 0]
    );
}
$instituciones_admin = (!es_medico() && es_superadmin())
    ? Database::fetchAll("SELECT id,nombre FROM instituciones WHERE activo=1 ORDER BY nombre") : [];

// Tipos de cirugía
$tipos_cirugia_json = [];
$tc_result = Database::fetchAll(
    "SELECT id,nombre,duracion_min,id_tipo_pabellon FROM tipos_cirugia WHERE activo=1"
    . ($id_inst ? " AND id_institucion=$id_inst" : '') . " ORDER BY nombre"
);
foreach ($tc_result as $tc) {
    $tipos_cirugia_json[$tc['id']] = $tc;
}

// Pabellones (admin)
$pabellones = [];
if (!es_medico()) {
    $pabellones = Database::fetchAll(
        "SELECT p.id,p.nombre,p.id_tipo_pabellon FROM pabellones p
         WHERE p.activo=1" . ($id_inst ? " AND p.id_institucion=$id_inst" : '') . " ORDER BY p.nombre"
    );
}

// ── LISTA de solicitudes ──────────────────────────────────
$filtro_estado = $_GET['estado'] ?? '';
$where_lista   = [];
$params_lista  = [];

if (es_medico()) {
    $where_lista[] = "s.id_medico=?";
    $params_lista[] = $id_medico;
} elseif (es_admin()) {
    $where_lista[] = "s.id_institucion=?";
    $params_lista[] = $id_inst;
}
if ($filtro_estado) {
    $where_lista[] = "s.estado=?";
    $params_lista[] = $filtro_estado;
}

$where_sql = $where_lista ? 'WHERE '.implode(' AND ',$where_lista) : '';
$lista = Database::fetchAll(
    "SELECT s.*,
            CONCAT(u.nombres,' ',u.apellidos) AS medico,
            tc.nombre AS cirugia, tc.duracion_min,
            p.nombre AS pabellon_nombre,
            i.nombre AS inst_nombre
     FROM solicitudes_pabellon s
     JOIN usuarios u ON u.id=s.id_medico
     JOIN tipos_cirugia tc ON tc.id=s.id_tipo_cirugia
     LEFT JOIN pabellones p ON p.id=s.id_pabellon
     LEFT JOIN instituciones i ON i.id=s.id_institucion
     $where_sql
     ORDER BY s.fecha_solicitada DESC, s.hora_inicio DESC
     LIMIT 200",
    $params_lista
);

// Detalle para gestionar
$solicitud_detalle = null;
if ($id && !es_medico()) {
    $solicitud_detalle = Database::fetchOne(
        "SELECT s.*,
                CONCAT(u.nombres,' ',u.apellidos) AS medico, u.especialidad,
                tc.nombre AS cirugia, tc.duracion_min, tc.id_tipo_pabellon,
                p.nombre AS pabellon_nombre, i.nombre AS inst_nombre
         FROM solicitudes_pabellon s
         JOIN usuarios u ON u.id=s.id_medico
         JOIN tipos_cirugia tc ON tc.id=s.id_tipo_cirugia
         LEFT JOIN pabellones p ON p.id=s.id_pabellon
         LEFT JOIN instituciones i ON i.id=s.id_institucion
         WHERE s.id=?",[$id]
    );
}

layout_header('Solicitudes de Pabellón');
?>

<?php if ($accion === 'nueva' || ($accion === 'lista' && isset($_GET['accion']) && $_GET['accion'] === 'nueva')): ?>
<!-- ══ FORMULARIO NUEVA SOLICITUD ══════════════════════════ -->
<div class="mb-3">
    <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Volver</a>
</div>
<?php if ($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>
<div class="pab-card">
    <div class="card-header"><i class="bi bi-clipboard-plus"></i> Nueva Solicitud de Pabellón</div>
    <div class="p-4">
        <form method="POST">
            <?= csrf_campo() ?>
            <input type="hidden" name="accion_form" value="nueva_solicitud">
            <div class="row g-3">
                <?php if (es_medico() && $instituciones_medico): ?>
                <div class="col-md-6">
                    <label class="form-label">Institución <span class="text-danger">*</span></label>
                    <select name="id_institucion" id="sel_inst" class="form-select" onchange="cargarCirugias(this.value)">
                        <?php foreach ($instituciones_medico as $ii): ?>
                        <option value="<?= $ii['id'] ?>" <?= $pre_id_inst && $pre_id_inst==$ii['id'] ? 'selected' : '' ?>>
                            <?= sanitizar($ii['nombre']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <?php if (es_superadmin()): ?>
                <div class="col-md-6">
                    <label class="form-label">Institución <span class="text-danger">*</span></label>
                    <select name="id_institucion" class="form-select">
                        <?php foreach ($instituciones_admin as $ii): ?>
                        <option value="<?= $ii['id'] ?>"><?= sanitizar($ii['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-md-6">
                    <label class="form-label">Tipo de Cirugía <span class="text-danger">*</span></label>
                    <select name="id_tipo_cirugia" id="sel_cirugia" class="form-select" onchange="actualizarHora()">
                        <?php foreach ($tc_result as $tc): ?>
                        <option value="<?= $tc['id'] ?>" data-dur="<?= $tc['duracion_min'] ?>"><?= sanitizar($tc['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($pre_id_pabellon): ?>
                <div class="col-md-6">
                    <label class="form-label">Pabellón pre-seleccionado</label>
                    <?php $pab_pre = Database::fetchOne("SELECT nombre FROM pabellones WHERE id=?",[$pre_id_pabellon]); ?>
                    <div class="form-control bg-light fw-semibold text-success">
                        <i class="bi bi-door-open me-1"></i>
                        <?= sanitizar($pab_pre['nombre'] ?? 'Pabellón #'.$pre_id_pabellon) ?>
                    </div>
                    <input type="hidden" name="id_pabellon_sol" value="<?= $pre_id_pabellon ?>">
                </div>
                <?php endif; ?>
                <div class="col-md-4">
                    <label class="form-label">Fecha <span class="text-danger">*</span></label>
                    <input type="date" name="fecha_solicitada" id="sel_fecha"
                           class="form-control" min="<?= date('Y-m-d') ?>"
                           value="<?= $pre_fecha ?: date('Y-m-d',strtotime('+1 day')) ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Hora inicio <span class="text-danger">*</span></label>
                    <input type="time" name="hora_inicio" id="sel_hinicio"
                           class="form-control" value="<?= $pre_hora_ini ?: '08:00' ?>"
                           oninput="actualizarHora()" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Hora fin</label>
                    <input type="time" name="hora_fin" id="sel_hfin"
                           class="form-control" value="<?= $pre_hora_fin ?: '09:00' ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Nombre del paciente</label>
                    <input type="text" name="paciente_nombre" class="form-control">
                </div>
                <div class="col-md-6">
                    <label class="form-label">RUT del paciente</label>
                    <input type="text" name="paciente_rut" class="form-control" placeholder="12.345.678-9" oninput="formatearRut(this)">
                </div>
                <div class="col-12">
                    <label class="form-label">Diagnóstico / Observaciones</label>
                    <textarea name="diagnostico" class="form-control" rows="3"></textarea>
                </div>
            </div>
            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i> Enviar Solicitud</button>
                <a href="index.php" class="btn btn-outline-secondary">Cancelar</a>
            </div>
        </form>
    </div>
</div>
<script>
function actualizarHora() {
    const sel = document.getElementById('sel_cirugia');
    const opt = sel.options[sel.selectedIndex];
    const dur = parseInt(opt.dataset.dur) || 60;
    const hinicio = document.getElementById('sel_hinicio').value;
    if (!hinicio) return;
    const [h,m] = hinicio.split(':').map(Number);
    const total = h*60+m+dur;
    const hf = String(Math.floor(total/60)%24).padStart(2,'0')+':'+String(total%60).padStart(2,'0');
    document.getElementById('sel_hfin').value = hf;
}
</script>

<?php elseif ($solicitud_detalle && !es_medico()): ?>
<!-- ══ DETALLE + GESTIÓN (admin) ══════════════════════════ -->
<div class="mb-3">
    <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Volver</a>
</div>
<?php $s = $solicitud_detalle; ?>
<div class="row g-3">
    <div class="col-md-7">
        <div class="pab-card p-4">
            <h6 class="fw-bold mb-3"><i class="bi bi-clipboard me-2"></i>Solicitud #<?= $s['id'] ?></h6>
            <div class="row g-2">
                <div class="col-6"><span class="text-muted small">Médico</span><div class="fw-semibold"><?= sanitizar($s['medico']) ?></div></div>
                <div class="col-6"><span class="text-muted small">Especialidad</span><div><?= sanitizar($s['especialidad'] ?? '—') ?></div></div>
                <div class="col-6"><span class="text-muted small">Institución</span><div><?= sanitizar($s['inst_nombre']) ?></div></div>
                <div class="col-6"><span class="text-muted small">Cirugía</span><div class="fw-semibold"><?= sanitizar($s['cirugia']) ?></div></div>
                <div class="col-4"><span class="text-muted small">Fecha</span><div><?= formato_fecha($s['fecha_solicitada']) ?></div></div>
                <div class="col-4"><span class="text-muted small">Inicio</span><div><?= formato_hora($s['hora_inicio']) ?></div></div>
                <div class="col-4"><span class="text-muted small">Fin</span><div><?= formato_hora($s['hora_fin']) ?></div></div>
                <?php if ($s['paciente_nombre']): ?>
                <div class="col-6"><span class="text-muted small">Paciente</span><div><?= sanitizar($s['paciente_nombre']) ?></div></div>
                <div class="col-6"><span class="text-muted small">RUT Paciente</span><div><?= sanitizar($s['paciente_rut']) ?></div></div>
                <?php endif; ?>
                <?php if ($s['diagnostico']): ?>
                <div class="col-12"><span class="text-muted small">Diagnóstico</span><div><?= sanitizar($s['diagnostico']) ?></div></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-5">
        <div class="pab-card p-4">
            <h6 class="fw-bold mb-3"><i class="bi bi-gear me-2"></i>Gestionar Solicitud</h6>
            <?php $badge = ['pendiente'=>'warning','aprobada'=>'success','rechazada'=>'danger','cancelada'=>'secondary','completada'=>'info'][$s['estado']] ?? 'secondary'; ?>
            <p>Estado actual: <span class="badge bg-<?= $badge ?>"><?= $s['estado'] ?></span></p>
            <?php if (!in_array($s['estado'],['cancelada','completada'])): ?>
            <form method="POST">
                <?= csrf_campo() ?>
                <input type="hidden" name="accion_form" value="gestionar">
                <input type="hidden" name="id_sol" value="<?= $s['id'] ?>">
                <div class="mb-3">
                    <label class="form-label">Nuevo estado</label>
                    <select name="estado" class="form-select">
                        <option value="pendiente">Pendiente</option>
                        <option value="aprobada">Aprobada</option>
                        <option value="rechazada">Rechazada</option>
                        <option value="completada">Completada</option>
                        <option value="cancelada">Cancelada</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Asignar Pabellón</label>
                    <?php
                    // Detectar pabellones con conflicto en este horario
                    $pab_ocupados = [];
                    if ($s['fecha_solicitada'] && $s['hora_inicio'] && $s['hora_fin']) {
                        $ocupados_rows = Database::fetchAll(
                            "SELECT id_pabellon, CONCAT(u.nombres,' ',u.apellidos) AS medico,
                                    hora_inicio, hora_fin
                             FROM solicitudes_pabellon sp
                             JOIN usuarios u ON u.id=sp.id_medico
                             WHERE sp.fecha_solicitada=?
                               AND sp.estado='aprobada'
                               AND sp.id != ?
                               AND NOT (sp.hora_fin <= ? OR sp.hora_inicio >= ?)",
                            [$s['fecha_solicitada'],$s['id'],$s['hora_inicio'],$s['hora_fin']]
                        );
                        foreach ($ocupados_rows as $oc) {
                            $pab_ocupados[$oc['id_pabellon']] = $oc['medico']
                                .' ('.substr($oc['hora_inicio'],0,5).'–'.substr($oc['hora_fin'],0,5).')';
                        }
                    }
                    ?>
                    <select name="id_pabellon" class="form-select">
                        <option value="">— Sin asignar —</option>
                        <?php foreach ($pabellones as $p):
                            $ocupado = isset($pab_ocupados[$p['id']]);
                        ?>
                        <option value="<?= $p['id'] ?>"
                            <?= $s['id_pabellon']==$p['id'] ? 'selected' : '' ?>
                            <?= $ocupado ? 'style="color:#dc2626;font-weight:600"' : '' ?>>
                            <?= sanitizar($p['nombre']) ?>
                            <?= $ocupado ? ' ⚠️ OCUPADO — '.$pab_ocupados[$p['id']] : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!empty($pab_ocupados)): ?>
                    <div class="form-text text-warning">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        Los pabellones marcados con ⚠️ ya tienen operaciones aprobadas en este horario.
                    </div>
                    <?php endif; ?>
                </div>
                <div class="mb-3">
                    <label class="form-label">Observación</label>
                    <textarea name="observacion" class="form-control" rows="2"><?= sanitizar($s['observacion'] ?? '') ?></textarea>
                </div>
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-save me-1"></i> Guardar cambios</button>
            </form>
            <?php else: ?>
            <p class="text-muted small">Esta solicitud ya fue <?= $s['estado'] ?> y no puede modificarse.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($solicitud_detalle):
    // Cargar historial
    $historial = Database::fetchAll(
        "SELECT h.*, CONCAT(u.nombres,' ',u.apellidos) AS usuario_nombre,
                p.nombre AS pabellon_nombre
         FROM solicitudes_historial h
         JOIN usuarios u ON u.id=h.id_usuario
         LEFT JOIN pabellones p ON p.id=h.id_pabellon_despues
         WHERE h.id_solicitud=?
         ORDER BY h.created_at ASC",
        [$solicitud_detalle['id']]
    );
    if ($historial):
?>
<div class="pab-card mt-4">
    <div class="card-header"><i class="bi bi-clock-history"></i> Historial de cambios</div>
    <div class="table-responsive">
        <table class="table table-sm table-pab align-middle mb-0">
            <thead><tr><th>Fecha</th><th>Usuario</th><th>Estado</th><th>Pabellón</th><th>Observación</th></tr></thead>
            <tbody>
            <?php foreach ($historial as $h):
                $badge = ['pendiente'=>'warning','aprobada'=>'success','rechazada'=>'danger',
                          'cancelada'=>'secondary','completada'=>'info'][$h['estado_despues']] ?? 'secondary';
            ?>
            <tr>
                <td class="small text-muted"><?= formato_fecha_hora($h['created_at']) ?></td>
                <td class="small"><?= sanitizar($h['usuario_nombre']) ?></td>
                <td>
                    <?php if ($h['estado_antes']): ?>
                    <span class="text-muted small"><?= $h['estado_antes'] ?></span>
                    <i class="bi bi-arrow-right text-muted mx-1"></i>
                    <?php endif; ?>
                    <span class="badge badge-<?= $h['estado_despues'] ?>"><?= $h['estado_despues'] ?></span>
                </td>
                <td class="small"><?= sanitizar($h['pabellon_nombre'] ?? '—') ?></td>
                <td class="small text-muted"><?= sanitizar($h['observacion'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; endif; ?>

<?php else: ?>
<!-- ══ LISTA DE SOLICITUDES ═══════════════════════════════ -->
<div class="d-flex justify-content-between align-items-center mb-3">
    <div class="d-flex gap-2">
        <?php foreach (['','pendiente','aprobada','rechazada','cancelada','completada'] as $est): ?>
        <a href="?<?= $est ? 'estado='.$est : '' ?>"
           class="btn btn-sm btn-<?= $filtro_estado===$est ? 'primary' : 'outline-secondary' ?>">
            <?= $est ? ucfirst($est) : 'Todas' ?>
        </a>
        <?php endforeach; ?>
    </div>
    <a href="?accion=nueva" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i> Nueva Solicitud</a>
</div>

<div class="pab-card">
    <div class="table-responsive">
        <table class="table table-pab table-hover align-middle mb-0">
            <thead><tr>
                <th>#</th><th>Fecha</th><th>Hora</th>
                <?php if (!es_medico()): ?><th>Médico</th><?php endif; ?>
                <?php if (es_superadmin()): ?><th>Institución</th><?php endif; ?>
                <th>Cirugía</th><th>Pabellón</th><th>Estado</th><th>Acciones</th>
            </tr></thead>
            <tbody>
            <?php foreach ($lista as $r): ?>
            <?php $badge = ['pendiente'=>'warning','aprobada'=>'success','rechazada'=>'danger','cancelada'=>'secondary','completada'=>'info'][$r['estado']] ?? 'secondary'; ?>
            <tr>
                <td class="text-muted small"><?= $r['id'] ?></td>
                <td><?= formato_fecha($r['fecha_solicitada']) ?></td>
                <td class="small"><?= formato_hora($r['hora_inicio']) ?>–<?= formato_hora($r['hora_fin']) ?></td>
                <?php if (!es_medico()): ?><td class="small"><?= sanitizar($r['medico']) ?></td><?php endif; ?>
                <?php if (es_superadmin()): ?><td class="small"><?= sanitizar($r['inst_nombre']) ?></td><?php endif; ?>
                <td><?= sanitizar($r['cirugia']) ?></td>
                <td><?= sanitizar($r['pabellon_nombre'] ?? '—') ?></td>
                <td><span class="badge badge-<?= $r['estado'] ?>"><?= $r['estado'] ?></span></td>
                <td>
                    <?php if (!es_medico()): ?>
                    <a href="?id=<?= $r['id'] ?>" title="Gestionar solicitud" title="Editar" title="Gestionar solicitud" class="btn btn-sm btn-outline-primary py-0"><i class="bi bi-gear"></i></a>
                    <?php elseif (in_array($r['estado'],['pendiente','aprobada'])): ?>
                    <form method="POST" class="d-inline">
                        <?= csrf_campo() ?>
                        <input type="hidden" name="accion_form" value="cancelar_propia">
                        <input type="hidden" name="id_sol" value="<?= $r['id'] ?>">
                        <button type="submit" title="Cancelar" title="Cancelar solicitud" class="btn btn-sm btn-outline-danger py-0"
                                onclick="return confirm('¿Cancelar esta solicitud?')">
                            <i class="bi bi-x-circle"></i>
                        </button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$lista): ?>
            <tr><td colspan="10" class="text-center py-4 text-muted">No hay solicitudes.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
<?php layout_footer(); ?>
