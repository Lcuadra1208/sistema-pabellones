<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/brevo.php';
requiere_login();

// Solo mantención y admin pueden acceder
if (!es_mantencion() && !es_admin() && !es_superadmin()) {
    redirigir('dashboard.php');
}

$usuario    = usuario_actual();
$id_usuario = (int)$usuario['id'];
$id_inst    = usuario_institucion();
$error      = '';

// Para mantención: si no tiene institución asignada en su perfil,
// buscar la institución de los pabellones que tiene asignados
// Si es admin/superadmin, ver todas; si es mantención, ver según su institución
if (es_mantencion() && !$id_inst) {
    // Intentar obtener la institución desde acreditaciones o directamente del usuario
    $inst_row = Database::fetchOne(
        "SELECT id_institucion FROM usuarios WHERE id=?", [$id_usuario]
    );
    $id_inst = (int)($inst_row['id_institucion'] ?? 0);
}

// Mantención filtra por institución del PABELLÓN (no de la solicitud)
// El médico puede ser de otra institución pero el pabellón pertenece a la clínica del mantenedor
$inst_cond      = $id_inst ? "AND p.id_institucion=$id_inst"  : '';
$inst_cond_post = $id_inst ? "AND p2.id_institucion=$id_inst" : '';

// ════════════════════════════════════════════════════════════
// POST
// ════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_validar()) {
    $af      = $_POST['accion_form'] ?? '';
    $id_sol  = (int)($_POST['id_sol'] ?? 0);

    // ── Marcar cirugía como completada ───────────────────
    if ($af === 'completar_cirugia') {
        $hora_real_fin = sanitizar($_POST['hora_real_fin'] ?? '');
        $obs           = sanitizar($_POST['obs_mantencion'] ?? '');

        $hoy_post   = date('Y-m-d');
        $hora_min   = date('H:i:s', strtotime('-1 hour')); // holgura 1h

        $sol = Database::fetchOne(
            "SELECT s.* FROM solicitudes_pabellon s
             JOIN pabellones p2 ON p2.id=s.id_pabellon
             WHERE s.id=?
               AND s.estado IN ('aprobada','pendiente')
               AND s.fecha_solicitada = ?
               AND s.hora_fin >= ?
               $inst_cond_post",
            [$id_sol, $hoy_post, $hora_min]
        );
        if (!$sol) {
            $error = 'No puede completar esta cirugía: no pertenece al día de hoy o ya expiró el rango horario.';
            goto fin_m;
        }

        // Verificar si las columnas de mantención existen
        $col_existe = Database::fetchOne(
            "SELECT COUNT(*) n FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='solicitudes_pabellon'
             AND COLUMN_NAME='sanitizacion_ini'"
        )['n'] ?? 0;

        if ($col_existe) {
            Database::query(
                "UPDATE solicitudes_pabellon
                 SET estado='completada', hora_real_fin=?,
                     sanitizacion_ini=NOW(), id_mantencion=?, obs_mantencion=?
                 WHERE id=?",
                [$hora_real_fin ?: null, $id_usuario, $obs, $id_sol]
            );
        } else {
            // Columnas no existen aún — solo cambiar estado
            Database::query(
                "UPDATE solicitudes_pabellon SET estado='completada' WHERE id=?",
                [$id_sol]
            );
            flash_set('advertencia', '⚠️ Ejecute migracion_mantencion.sql para habilitar el control de sanitización.');
        }

        // Historial
        Database::insert(
            "INSERT INTO solicitudes_historial
             (id_solicitud,id_usuario,estado_antes,estado_despues,observacion)
             VALUES (?,?,'aprobada','completada',?)",
            [$id_sol, $id_usuario, 'Cirugía completada. Sanitización iniciada.'.($obs ? ' '.$obs : '')]
        );
        audit('completar_cirugia', 'solicitudes_pabellon', $id_sol);

        // Notificar al médico
        $medico = Database::fetchOne("SELECT * FROM usuarios WHERE id=?", [$sol['id_medico']]);
        $inst   = Database::fetchOne("SELECT logo FROM instituciones WHERE id=?", [$sol['id_institucion']]);
        $pab    = Database::fetchOne("SELECT nombre FROM pabellones WHERE id=?", [$sol['id_pabellon']]);
        $tc     = Database::fetchOne("SELECT nombre FROM tipos_cirugia WHERE id=?", [$sol['id_tipo_cirugia']]);
        if ($medico) {
            $sol_data = array_merge($sol, [
                'estado'          => 'completada',
                'pabellon_nombre' => $pab['nombre'] ?? '—',
                'cirugia'         => $tc['nombre']  ?? '—',
                'observacion'     => 'Cirugía completada. Pabellón en proceso de sanitización.',
            ]);
            $logo = ($inst['logo'] ?? '') ? APP_URL.'/'.$inst['logo'] : '';
            BrevoMailer::notificarCambioSolicitud($sol_data, $medico, $logo);
        }

        flash_set('exito', 'Cirugía marcada como completada. Sanitización iniciada.');
        redirigir('modules/mantencion/index.php');
    }

    // ── Registrar fin de sanitización (pabellón libre) ───
    if ($af === 'fin_sanitizacion') {
        $obs = sanitizar($_POST['obs_sanitizacion'] ?? '');

        $sol = Database::fetchOne(
            "SELECT s.* FROM solicitudes_pabellon s
             JOIN pabellones p2 ON p2.id=s.id_pabellon
             WHERE s.id=?
               AND s.estado='completada'
               AND s.sanitizacion_ini IS NOT NULL
               AND s.sanitizacion_fin IS NULL
               AND DATE(s.sanitizacion_ini) = CURDATE()
               $inst_cond_post",
            [$id_sol]
        );
        if (!$sol) { $error = 'Solicitud no encontrada o sanitización ya registrada.'; goto fin_m; }

        Database::query(
            "UPDATE solicitudes_pabellon
             SET sanitizacion_fin=NOW(), obs_mantencion=CONCAT(IFNULL(obs_mantencion,''),' | Sanitización: ',?)
             WHERE id=?",
            [$obs ?: 'Completada', $id_sol]
        );

        // Historial
        Database::insert(
            "INSERT INTO solicitudes_historial (id_solicitud,id_usuario,estado_antes,estado_despues,observacion)
             VALUES (?,?,'completada','completada','Sanitización finalizada. Pabellón disponible.')",
            [$id_sol, $id_usuario]
        );
        audit('fin_sanitizacion', 'solicitudes_pabellon', $id_sol);

        flash_set('exito', '✅ Pabellón liberado y disponible para nuevas cirugías.');
        redirigir('modules/mantencion/index.php');
    }
}
fin_m:

// ── Datos ─────────────────────────────────────────────────
$hoy       = date('Y-m-d');
$ver_fecha = sanitizar($_GET['fecha'] ?? $hoy); // Fecha a ver (default hoy)
$es_hoy    = ($ver_fecha === $hoy);
$fecha_ant = date('Y-m-d', strtotime($ver_fecha . ' -1 day'));
$fecha_sig = date('Y-m-d', strtotime($ver_fecha . ' +1 day'));

// Hora actual menos 1 hora (holgura) para no perder cirugías en curso
$hora_corte = $es_hoy ? date('H:i:s', strtotime('-1 hour')) : '00:00:00';

// Cirugías del día seleccionado — desde 1h antes hasta fin del día
$aprobadas = Database::fetchAll(
    "SELECT s.*, p.nombre AS pabellon, tc.nombre AS cirugia,
            CONCAT(u.nombres,' ',u.apellidos) AS medico,
            i.nombre AS inst_nombre, tp.color_hex
     FROM solicitudes_pabellon s
     JOIN pabellones p ON p.id=s.id_pabellon
     JOIN tipos_pabellon tp ON tp.id=p.id_tipo_pabellon
     JOIN tipos_cirugia tc ON tc.id=s.id_tipo_cirugia
     JOIN usuarios u ON u.id=s.id_medico
     JOIN instituciones i ON i.id=s.id_institucion
     WHERE s.estado IN ('aprobada','pendiente')
       AND s.id_pabellon IS NOT NULL
       AND s.fecha_solicitada = ?
       AND s.hora_fin >= ?
       $inst_cond
     ORDER BY s.hora_inicio",
    [$ver_fecha, $hora_corte]
);

// Cirugías en sanitización (solo si columnas existen)
$col_sanit = Database::fetchOne(
    "SELECT COUNT(*) n FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='solicitudes_pabellon'
     AND COLUMN_NAME='sanitizacion_ini'"
)['n'] ?? 0;

$sanitizando = [];
if ($col_sanit) {
    $sanitizando = Database::fetchAll(
        "SELECT s.*, p.nombre AS pabellon, tc.nombre AS cirugia,
                CONCAT(u.nombres,' ',u.apellidos) AS medico,
                i.nombre AS inst_nombre, tp.color_hex,
                TIMESTAMPDIFF(MINUTE, s.sanitizacion_ini, NOW()) AS mins_sanitizando
         FROM solicitudes_pabellon s
         JOIN pabellones p ON p.id=s.id_pabellon
         JOIN tipos_pabellon tp ON tp.id=p.id_tipo_pabellon
         JOIN tipos_cirugia tc ON tc.id=s.id_tipo_cirugia
         JOIN usuarios u ON u.id=s.id_medico
         JOIN instituciones i ON i.id=s.id_institucion
         WHERE s.estado='completada'
           AND s.sanitizacion_ini IS NOT NULL
           AND s.sanitizacion_fin IS NULL
           $inst_cond
         ORDER BY s.sanitizacion_ini",
        []
    );
}

// Historial del día (completadas con sanitización)
$historial_hoy = [];
if ($col_sanit) {
    $historial_hoy = Database::fetchAll(
        "SELECT s.*, p.nombre AS pabellon, tc.nombre AS cirugia,
                CONCAT(u.nombres,' ',u.apellidos) AS medico,
                i.nombre AS inst_nombre,
                TIMESTAMPDIFF(MINUTE, s.sanitizacion_ini, s.sanitizacion_fin) AS mins_sanitizacion
         FROM solicitudes_pabellon s
         JOIN pabellones p ON p.id=s.id_pabellon
         JOIN tipos_cirugia tc ON tc.id=s.id_tipo_cirugia
         JOIN usuarios u ON u.id=s.id_medico
         JOIN instituciones i ON i.id=s.id_institucion
         WHERE s.estado='completada'
           AND s.sanitizacion_fin IS NOT NULL
           AND s.fecha_solicitada = ?
           $inst_cond
         ORDER BY s.sanitizacion_fin DESC",
        [$ver_fecha]
    );
}

layout_header('Mantención de Pabellones');
?>

<?php if ($error): ?>
<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?= sanitizar($error) ?></div>
<?php endif; ?>

<!-- Navegación de fecha -->
<div class="d-flex align-items-center justify-content-between mb-4">
    <div class="d-flex align-items-center gap-2">
        <a href="?fecha=<?= $fecha_ant ?>" class="btn btn-outline-secondary btn-sm" title="Día anterior">
            <i class="bi bi-chevron-left"></i>
        </a>
        <div class="fw-bold fs-5 px-2">
            <?= $es_hoy ? '<span class="text-primary">Hoy</span> — ' : '' ?>
            <?= date('l', strtotime($ver_fecha)) ?>,
            <?= date('d', strtotime($ver_fecha)) ?> de
            <?= ['','Enero','Febrero','Marzo','Abril','Mayo','Junio',
                 'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'][(int)date('m', strtotime($ver_fecha))] ?>
            <?= date('Y', strtotime($ver_fecha)) ?>
        </div>
        <a href="?fecha=<?= $fecha_sig ?>" class="btn btn-outline-secondary btn-sm" title="Día siguiente">
            <i class="bi bi-chevron-right"></i>
        </a>
        <?php if (!$es_hoy): ?>
        <a href="?" class="btn btn-primary btn-sm ms-1">
            <i class="bi bi-calendar-check me-1"></i>Hoy
        </a>
        <?php endif; ?>
    </div>
    <div class="d-flex align-items-center gap-3 small text-muted">
        <span><i class="bi bi-check-circle-fill text-success me-1"></i>Aprobada</span>
        <span><i class="bi bi-hourglass-split text-warning me-1"></i>Pendiente</span>
        <span><i class="bi bi-stars text-warning me-1"></i>Sanitizando</span>
        <span><i class="bi bi-unlock-fill text-success me-1"></i>Liberado</span>
    </div>
</div>

<?php if (es_mantencion() && !$id_inst): ?>
<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle me-2"></i>
    <strong>Sin institución asignada.</strong> Su usuario no tiene una institución asociada.
    Solicite al administrador que asigne su institución en la gestión de usuarios.
    Mostrando todas las cirugías del sistema.
</div>
<?php endif; ?>

<!-- ══ CIRUGÍAS EN CURSO / PENDIENTES DE COMPLETAR ══════ -->
<div class="pab-card mb-4">
    <div class="card-header d-flex align-items-center justify-content-between">
        <span>
            <i class="bi bi-activity text-primary me-2"></i>
            Cirugías del día
            <?php if ($es_hoy && $hora_corte !== '00:00:00'): ?>
            <span class="text-muted small ms-2">
                (desde <?= date('H:i', strtotime($hora_corte)) ?> — holgura 1h)
            </span>
            <?php endif; ?>
        </span>
        <span class="badge bg-primary"><?= count($aprobadas) ?></span>
    </div>

    <?php if ($aprobadas): ?>
    <div class="table-responsive">
        <table class="table table-pab table-hover align-middle mb-0">
            <thead><tr>
                <th>Fecha</th><th>Horario</th><th>Pabellón</th>
                <th>Médico</th><th>Cirugía</th><th>Institución</th><th>Acción</th>
            </tr></thead>
            <tbody>
            <?php foreach ($aprobadas as $r):
                $es_hoy = ($r['fecha_solicitada'] === $hoy);
                $pasada = ($r['fecha_solicitada'] < $hoy);
            ?>
            <tr class="<?= $pasada ? 'table-warning' : '' ?>">
                <td>
                    <?= formato_fecha($r['fecha_solicitada']) ?>
                    <?php if ($pasada): ?>
                    <span class="badge bg-warning text-dark ms-1" style="font-size:.62rem">Atrasada</span>
                    <?php elseif ($es_hoy): ?>
                    <span class="badge bg-success ms-1" style="font-size:.62rem">Hoy</span>
                    <?php endif; ?>
                </td>
                <td class="fw-semibold">
                    <?= formato_hora($r['hora_inicio']) ?> – <?= formato_hora($r['hora_fin']) ?>
                </td>
                <td>
                    <span style="display:inline-block;width:10px;height:10px;border-radius:50%;
                                 background:<?= sanitizar($r['color_hex']) ?>;margin-right:5px"></span>
                    <?= sanitizar($r['pabellon']) ?>
                </td>
                <td><?= sanitizar($r['medico']) ?></td>
                <td><?= sanitizar($r['cirugia']) ?></td>
                <td class="small text-muted"><?= sanitizar($r['inst_nombre']) ?></td>
                <td>
                    <?php if ($r['estado'] === 'aprobada' && $es_hoy): ?>
                    <button class="btn btn-sm btn-success"
                            title="Marcar cirugía como completada"
                            onclick="abrirCompletar(<?= $r['id'] ?>,
                                '<?= sanitizar($r['pabellon']) ?>',
                                '<?= sanitizar($r['cirugia']) ?>',
                                '<?= sanitizar($r['medico']) ?>',
                                '<?= substr($r['hora_fin'],0,5) ?>')">
                        <i class="bi bi-check2-circle me-1"></i> Completar
                    </button>
                    <?php elseif (!$es_hoy): ?>
                    <span class="badge bg-secondary" title="Solo se puede completar el día de la cirugía">Solo hoy</span>
                    <?php else: ?>
                    <span class="badge bg-warning text-dark">Pendiente aprobación</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="text-center text-muted py-5">
        <i class="bi bi-check-circle fs-1 d-block mb-2 opacity-25"></i>
        No hay cirugías activas pendientes de completar.
    </div>
    <?php endif; ?>
</div>

<!-- ══ PABELLONES EN SANITIZACIÓN ═══════════════════════ -->
<?php if ($sanitizando): ?>
<div class="pab-card mb-4">
    <div class="card-header d-flex align-items-center justify-content-between">
        <span><i class="bi bi-stars text-warning me-2"></i>Pabellones en sanitización</span>
        <span class="badge bg-warning text-dark"><?= count($sanitizando) ?></span>
    </div>
    <div class="p-3 row g-3">
        <?php foreach ($sanitizando as $r): ?>
        <div class="col-md-6">
            <div class="rounded-3 p-3" style="background:#fffbeb;border:1.5px solid #f59e0b">
                <div class="d-flex align-items-start justify-content-between gap-2">
                    <div>
                        <div class="fw-bold fs-6">
                            <span style="display:inline-block;width:10px;height:10px;border-radius:50%;
                                         background:<?= sanitizar($r['color_hex']) ?>;margin-right:5px"></span>
                            <?= sanitizar($r['pabellon']) ?>
                        </div>
                        <div class="small text-muted"><?= sanitizar($r['inst_nombre']) ?></div>
                        <div class="mt-2 small">
                            <i class="bi bi-person-badge me-1"></i><?= sanitizar($r['medico']) ?>
                            &nbsp;·&nbsp;
                            <i class="bi bi-scissors me-1"></i><?= sanitizar($r['cirugia']) ?>
                        </div>
                        <div class="mt-1 small">
                            <i class="bi bi-clock-history me-1 text-warning"></i>
                            Sanitizando hace
                            <strong><?= $r['mins_sanitizando'] ?> min</strong>
                            (desde <?= date('H:i', strtotime($r['sanitizacion_ini'])) ?>)
                        </div>
                        <?php if ($r['obs_mantencion']): ?>
                        <div class="mt-1 small text-muted">
                            <i class="bi bi-chat-left-text me-1"></i><?= sanitizar($r['obs_mantencion']) ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <button class="btn btn-warning btn-sm flex-shrink-0"
                            title="Registrar fin de sanitización — pabellón libre"
                            onclick="abrirSanitizacion(<?= $r['id'] ?>, '<?= sanitizar($r['pabellon']) ?>')">
                        <i class="bi bi-unlock me-1"></i> Liberar
                    </button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- ══ HISTORIAL DEL DÍA ════════════════════════════════ -->
<?php if ($historial_hoy): ?>
<div class="pab-card">
    <div class="card-header">
        <i class="bi bi-clock-history me-2"></i>Completadas hoy
    </div>
    <div class="table-responsive">
        <table class="table table-pab align-middle mb-0" style="font-size:.85rem">
            <thead><tr>
                <th>Pabellón</th><th>Médico</th><th>Cirugía</th>
                <th>Horario op.</th><th>Tiempo sanitiz.</th><th>Liberado a las</th>
            </tr></thead>
            <tbody>
            <?php foreach ($historial_hoy as $r): ?>
            <tr>
                <td class="fw-semibold"><?= sanitizar($r['pabellon']) ?></td>
                <td><?= sanitizar($r['medico']) ?></td>
                <td><?= sanitizar($r['cirugia']) ?></td>
                <td><?= formato_hora($r['hora_inicio']) ?> – <?= formato_hora($r['hora_fin']) ?></td>
                <td>
                    <span class="badge bg-success">
                        <?= $r['mins_sanitizacion'] ?> min
                    </span>
                </td>
                <td class="fw-semibold text-success">
                    <?= date('H:i', strtotime($r['sanitizacion_fin'])) ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ── Modal: Completar cirugía ──────────────────────────── -->
<div class="modal fade" id="modalCompletar" tabindex="-1">
  <div class="modal-dialog">
    <form method="POST" class="modal-content">
        <?= csrf_campo() ?>
        <input type="hidden" name="accion_form" value="completar_cirugia">
        <input type="hidden" name="id_sol" id="mc_id">
        <div class="modal-header">
            <h5 class="modal-title"><i class="bi bi-check2-circle text-success me-2"></i>Completar Cirugía</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
            <div class="alert alert-info py-2 mb-3">
                <strong id="mc_info"></strong>
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold">
                    Hora real de término <span class="text-muted fw-normal small">(opcional)</span>
                </label>
                <input type="time" name="hora_real_fin" id="mc_hora" class="form-control">
                <div class="form-text">Si difiere del horario programado, ingrese la hora real.</div>
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold">Observaciones</label>
                <textarea name="obs_mantencion" class="form-control" rows="2"
                          placeholder="Ej: Sin incidentes. Iniciando protocolo de sanitización..."></textarea>
            </div>
            <div class="alert alert-warning py-2 mb-0">
                <i class="bi bi-stars me-2"></i>
                Al confirmar, el pabellón quedará en estado <strong>Sanitización</strong>.
                Debe registrar el fin de la sanitización para liberarlo.
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-success">
                <i class="bi bi-check2-circle me-1"></i> Confirmar y comenzar sanitización
            </button>
        </div>
    </form>
  </div>
</div>

<!-- ── Modal: Fin sanitización ──────────────────────────── -->
<div class="modal fade" id="modalSanitizacion" tabindex="-1">
  <div class="modal-dialog">
    <form method="POST" class="modal-content">
        <?= csrf_campo() ?>
        <input type="hidden" name="accion_form" value="fin_sanitizacion">
        <input type="hidden" name="id_sol" id="ms_id">
        <div class="modal-header">
            <h5 class="modal-title"><i class="bi bi-unlock text-warning me-2"></i>Liberar Pabellón</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
            <div class="alert alert-warning py-2 mb-3">
                <i class="bi bi-stars me-2"></i>
                Pabellón: <strong id="ms_pabellon"></strong>
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold">Observaciones sanitización</label>
                <textarea name="obs_sanitizacion" class="form-control" rows="2"
                          placeholder="Ej: Sanitización completada según protocolo. Pabellón listo."></textarea>
            </div>
            <div class="alert alert-success py-2 mb-0">
                <i class="bi bi-check-circle me-2"></i>
                Al confirmar, el pabellón quedará <strong>disponible</strong> para nuevas cirugías.
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-warning">
                <i class="bi bi-unlock me-1"></i> Liberar pabellón
            </button>
        </div>
    </form>
  </div>
</div>

<script>
function abrirCompletar(id, pabellon, cirugia, medico, horaFin) {
    document.getElementById('mc_id').value = id;
    document.getElementById('mc_hora').value = horaFin;
    document.getElementById('mc_info').textContent =
        pabellon + ' — ' + cirugia + ' — Dr/a. ' + medico;
    new bootstrap.Modal(document.getElementById('modalCompletar')).show();
}
function abrirSanitizacion(id, pabellon) {
    document.getElementById('ms_id').value = id;
    document.getElementById('ms_pabellon').textContent = pabellon;
    new bootstrap.Modal(document.getElementById('modalSanitizacion')).show();
}
</script>

<?php layout_footer(); ?>
