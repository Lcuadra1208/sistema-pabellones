<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/layout.php';
requiere_login(ROL_ADMIN_HOSPITAL);

$id_inst  = usuario_institucion();
$id_admin = (int)usuario_actual()['id'];
$accion   = $_GET['accion'] ?? 'lista';
$id       = (int)($_GET['id'] ?? 0);
$error    = '';

$DIAS = [1=>'Lunes',2=>'Martes',3=>'Miércoles',4=>'Jueves',5=>'Viernes',6=>'Sábado',7=>'Domingo'];

// ════════════════════════════════════════════════════════════
// POST
// ════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_validar()) {
    $af = $_POST['accion_form'] ?? '';

    // ── Crear / Editar período ────────────────────────────
    if ($af === 'guardar_periodo') {
        $id_p   = (int)($_POST['id'] ?? 0);
        $nombre = sanitizar($_POST['nombre'] ?? '');
        $desde  = sanitizar($_POST['fecha_inicio'] ?? '');
        $hasta  = sanitizar($_POST['fecha_fin'] ?? '');
        $obs    = sanitizar($_POST['observacion'] ?? '');

        if (!$nombre || !$desde || !$hasta) {
            $error = 'Nombre, fecha inicio y fecha fin son obligatorios.';
            goto fin_per;
        }
        if ($desde > $hasta) {
            $error = 'La fecha de inicio debe ser anterior a la fecha de fin.';
            goto fin_per;
        }
        if ($id_p) {
            Database::query(
                "UPDATE periodos_pabellon SET nombre=?,fecha_inicio=?,fecha_fin=?,observacion=? WHERE id=? AND id_institucion=?",
                [$nombre,$desde,$hasta,$obs,$id_p,$id_inst]
            );
            flash_set('exito','Período actualizado.');
        } else {
            $new = Database::insert(
                "INSERT INTO periodos_pabellon (id_institucion,nombre,fecha_inicio,fecha_fin,observacion,id_admin)
                 VALUES (?,?,?,?,?,?)",
                [$id_inst,$nombre,$desde,$hasta,$obs,$id_admin]
            );
            audit('crear_periodo','periodos_pabellon',$new);
            flash_set('exito','Período creado. Ahora configure los pabellones disponibles.');
            redirigir('modules/programacion/index.php?accion=detalle&id='.$new);
        }
        redirigir('modules/programacion/index.php');
    }

    // ── Cambiar estado del período ────────────────────────
    if ($af === 'cambiar_estado') {
        $id_p      = (int)($_POST['id'] ?? 0);
        $nuevo_est = sanitizar($_POST['estado'] ?? '');
        if (in_array($nuevo_est, ['borrador','publicado','cerrado']) && $id_p) {
            Database::query(
                "UPDATE periodos_pabellon SET estado=? WHERE id=? AND id_institucion=?",
                [$nuevo_est,$id_p,$id_inst]
            );
            $msg = match($nuevo_est) {
                'publicado' => 'Período publicado. Los médicos pueden ver la disponibilidad.',
                'cerrado'   => 'Período cerrado.',
                default     => 'Estado actualizado.',
            };
            flash_set('exito',$msg);
        }
        redirigir('modules/programacion/index.php');
    }

    // ── Guardar disponibilidad de pabellón en el período ──
    if ($af === 'guardar_disponibilidad') {
        $id_periodo  = (int)($_POST['id_periodo'] ?? 0);
        $id_pabellon = (int)($_POST['id_pabellon'] ?? 0);
        $dias        = $_POST['dias'] ?? [];
        $horas_ini   = $_POST['hora_inicio'] ?? [];
        $horas_fin   = $_POST['hora_fin'] ?? [];

        if (!$id_periodo || !$id_pabellon || empty($dias)) {
            $error = 'Seleccione un pabellón y al menos un día.';
            goto fin_per;
        }

        // Verificar que el período pertenece a esta institución
        $periodo = Database::fetchOne(
            "SELECT id FROM periodos_pabellon WHERE id=? AND id_institucion=? AND estado='borrador'",
            [$id_periodo,$id_inst]
        );
        if (!$periodo) {
            $error = 'El período no existe o ya fue publicado (no se puede modificar).';
            goto fin_per;
        }

        // Eliminar disponibilidad anterior de este pabellón en este período
        Database::query(
            "DELETE FROM periodo_pabellon_detalle WHERE id_periodo=? AND id_pabellon=?",
            [$id_periodo,$id_pabellon]
        );

        // Insertar nuevos días
        foreach ($dias as $dia) {
            $dia  = (int)$dia;
            $hi   = sanitizar($horas_ini[$dia] ?? '07:00');
            $hf   = sanitizar($horas_fin[$dia] ?? '21:00');
            if ($hi >= $hf) continue;
            Database::insert(
                "INSERT INTO periodo_pabellon_detalle
                 (id_periodo,id_pabellon,dia_semana,hora_inicio,hora_fin)
                 VALUES (?,?,?,?,?)",
                [$id_periodo,$id_pabellon,$dia,$hi,$hf]
            );
        }
        flash_set('exito','Disponibilidad guardada correctamente.');
        redirigir('modules/programacion/index.php?accion=detalle&id='.$id_periodo);
    }

    // ── Eliminar disponibilidad de un pabellón ────────────
    if ($af === 'quitar_pabellon') {
        $id_periodo  = (int)($_POST['id_periodo'] ?? 0);
        $id_pabellon = (int)($_POST['id_pabellon'] ?? 0);
        Database::query(
            "DELETE FROM periodo_pabellon_detalle WHERE id_periodo=? AND id_pabellon=?",
            [$id_periodo,$id_pabellon]
        );
        flash_set('info','Pabellón quitado del período.');
        redirigir('modules/programacion/index.php?accion=detalle&id='.$id_periodo);
    }
}
fin_per:

// ════════════════════════════════════════════════════════════
// VISTA: DETALLE DEL PERÍODO
// ════════════════════════════════════════════════════════════
if ($accion === 'detalle' && $id) {
    $periodo = Database::fetchOne(
        "SELECT * FROM periodos_pabellon WHERE id=? AND id_institucion=?",
        [$id,$id_inst]
    );
    if (!$periodo) { flash_set('error','Período no encontrado.'); redirigir('modules/programacion/index.php'); }

    // Pabellones ya configurados en este período
    $pab_configurados = Database::fetchAll(
        "SELECT ppd.id_pabellon, p.nombre AS pabellon, tp.nombre AS tipo, tp.color_hex,
                GROUP_CONCAT(CONCAT(ppd.dia_semana,':',ppd.hora_inicio,'-',ppd.hora_fin)
                             ORDER BY ppd.dia_semana SEPARATOR '|') AS dias_config
         FROM periodo_pabellon_detalle ppd
         JOIN pabellones p ON p.id=ppd.id_pabellon
         JOIN tipos_pabellon tp ON tp.id=p.id_tipo_pabellon
         WHERE ppd.id_periodo=? AND ppd.activo=1
         GROUP BY ppd.id_pabellon, p.nombre, tp.nombre, tp.color_hex",
        [$id]
    );
    $pab_ids_config = array_column($pab_configurados,'id_pabellon');

    // Todos los pabellones de la institución
    $todos_pabellones = Database::fetchAll(
        "SELECT p.id,p.nombre,tp.nombre AS tipo,tp.color_hex
         FROM pabellones p
         JOIN tipos_pabellon tp ON tp.id=p.id_tipo_pabellon
         WHERE p.id_institucion=? AND p.activo=1 ORDER BY p.nombre",
        [$id_inst]
    );
    // Pabellones aún no agregados
    $pab_disponibles = array_filter($todos_pabellones,
        fn($p) => !in_array($p['id'], $pab_ids_config)
    );

    layout_header('Programación — '.$periodo['nombre']);
    ?>
    <!-- Cabecera del período -->
    <div class="d-flex align-items-start gap-3 mb-4 flex-wrap">
        <a href="index.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Períodos
        </a>
        <div class="flex-grow-1">
            <h5 class="mb-0 fw-bold"><?= sanitizar($periodo['nombre']) ?></h5>
            <div class="text-muted small">
                <i class="bi bi-calendar3 me-1"></i>
                <?= formato_fecha($periodo['fecha_inicio']) ?> al <?= formato_fecha($periodo['fecha_fin']) ?>
            </div>
        </div>
        <?php
        $badge_est = match($periodo['estado']) {
            'publicado' => 'success', 'cerrado' => 'secondary', default => 'warning'
        };
        ?>
        <span class="badge bg-<?= $badge_est ?> fs-6 px-3 py-2">
            <?= ucfirst($periodo['estado']) ?>
        </span>

        <!-- Cambiar estado -->
        <?php if ($periodo['estado'] === 'borrador' && count($pab_configurados) > 0): ?>
        <form method="POST" class="d-inline">
            <?= csrf_campo() ?>
            <input type="hidden" name="accion_form" value="cambiar_estado">
            <input type="hidden" name="id" value="<?= $periodo['id'] ?>">
            <input type="hidden" name="estado" value="publicado">
            <button type="submit" class="btn btn-success btn-sm"
                    onclick="return confirm('¿Publicar este período? Los médicos podrán ver la disponibilidad.')">
                <i class="bi bi-megaphone me-1"></i> Publicar Período
            </button>
        </form>
        <?php elseif ($periodo['estado'] === 'publicado'): ?>
        <form method="POST" class="d-inline">
            <?= csrf_campo() ?>
            <input type="hidden" name="accion_form" value="cambiar_estado">
            <input type="hidden" name="id" value="<?= $periodo['id'] ?>">
            <input type="hidden" name="estado" value="cerrado">
            <button type="submit" class="btn btn-outline-secondary btn-sm"
                    onclick="return confirm('¿Cerrar este período?')">
                <i class="bi bi-lock me-1"></i> Cerrar Período
            </button>
        </form>
        <?php endif; ?>
    </div>

    <?php if ($periodo['estado'] === 'borrador'): ?>
    <div class="alert alert-warning py-2 mb-3">
        <i class="bi bi-pencil-square me-2"></i>
        <strong>Modo borrador</strong> — Configure los pabellones y sus horarios, luego publique el período para que sea visible.
    </div>
    <?php elseif ($periodo['estado'] === 'publicado'): ?>
    <div class="alert alert-success py-2 mb-3">
        <i class="bi bi-check-circle me-2"></i>
        <strong>Publicado</strong> — Los médicos pueden ver esta disponibilidad en el calendario.
        Los pabellones no pueden modificarse mientras esté publicado.
    </div>
    <?php endif; ?>

    <?php if ($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>

    <div class="row g-4">
        <!-- Columna izquierda: pabellones configurados -->
        <div class="col-md-7">
            <div class="pab-card">
                <div class="card-header">
                    <i class="bi bi-door-open"></i> Pabellones en este período
                    <span class="badge bg-primary ms-2"><?= count($pab_configurados) ?></span>
                </div>

                <?php if ($pab_configurados): ?>
                <?php foreach ($pab_configurados as $pc):
                    // Parsear dias_config: "1:07:00:00-21:00:00:1|2:08:00:00-20:00:00:2"
                    $dias_arr = [];
                    foreach (explode('|', $pc['dias_config']) as $dstr) {
                        $parts = explode(':', $dstr, 4); // dia:HH:MM:MM-HH:MM:MM:cupo
                        // Formato: dia_semana:hora_ini_H:hora_ini_M:hora_fin_H-hora_fin_M:cupo
                        // Re-parsear más simple
                        preg_match('/^(\d):(\d{2}:\d{2}):\d{2}-(\d{2}:\d{2}):\d{2}$/', $dstr, $m);
                        if ($m) {
                            $dias_arr[(int)$m[1]] = ['hi'=>$m[2],'hf'=>$m[3]];
                        }
                    }
                ?>
                <div class="p-3 border-bottom">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <span style="width:12px;height:12px;border-radius:50%;background:<?= sanitizar($pc['color_hex']) ?>;flex-shrink:0"></span>
                        <span class="fw-semibold"><?= sanitizar($pc['pabellon']) ?></span>
                        <span class="badge bg-light text-dark ms-1"><?= sanitizar($pc['tipo']) ?></span>
                        <?php if ($periodo['estado'] === 'borrador'): ?>
                        <div class="ms-auto d-flex gap-1">
                            <button title="Editar" class="btn btn-sm btn-outline-primary py-0"
                                    onclick="abrirEditar(<?= $pc['id_pabellon'] ?>, '<?= sanitizar($pc['pabellon']) ?>', <?= htmlspecialchars(json_encode($dias_arr),ENT_QUOTES) ?>)">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <form method="POST" class="d-inline">
                                <?= csrf_campo() ?>
                                <input type="hidden" name="accion_form" value="quitar_pabellon">
                                <input type="hidden" name="id_periodo" value="<?= $periodo['id'] ?>">
                                <input type="hidden" name="id_pabellon" value="<?= $pc['id_pabellon'] ?>">
                                <button type="submit" title="Eliminar" class="btn btn-sm btn-outline-danger py-0"
                                        onclick="return confirm('¿Quitar este pabellón del período?')">
                                    <i class="bi bi-trash3"></i>
                                </button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </div>
                    <!-- Grilla de días -->
                    <div class="d-flex flex-wrap gap-1 mt-1">
                        <?php foreach ($DIAS as $num => $nombre_dia):
                            $conf = $dias_arr[$num] ?? null;
                        ?>
                        <div class="rounded px-2 py-1 text-center"
                             style="min-width:90px;font-size:.75rem;
                                    background:<?= $conf ? '#d1fae5' : '#f3f4f6' ?>;
                                    color:<?= $conf ? '#065f46' : '#9ca3af' ?>;
                                    border:1px solid <?= $conf ? '#6ee7b7' : '#e5e7eb' ?>">
                            <div class="fw-semibold"><?= $nombre_dia ?></div>
                            <?php if ($conf): ?>
                            <div><?= $conf['hi'] ?> – <?= $conf['hf'] ?></div>
                            <?php else: ?>
                            <div>No disp.</div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php else: ?>
                <div class="text-center text-muted py-5">
                    <i class="bi bi-door-open fs-1 d-block mb-2 opacity-25"></i>
                    No hay pabellones configurados en este período.<br>
                    <small>Agregue pabellones desde el panel derecho.</small>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Columna derecha: agregar pabellón -->
        <?php if ($periodo['estado'] === 'borrador'): ?>
        <div class="col-md-5">
            <div class="pab-card">
                <div class="card-header">
                    <i class="bi bi-plus-circle"></i> Agregar Pabellón al Período
                </div>
                <div class="p-3">
                    <?php if ($pab_disponibles): ?>
                    <form method="POST" id="form_disponibilidad">
                        <?= csrf_campo() ?>
                        <input type="hidden" name="accion_form" value="guardar_disponibilidad">
                        <input type="hidden" name="id_periodo" value="<?= $periodo['id'] ?>">

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Pabellón <span class="text-danger">*</span></label>
                            <select name="id_pabellon" class="form-select" required>
                                <option value="">— Seleccione —</option>
                                <?php foreach ($pab_disponibles as $p): ?>
                                <option value="<?= $p['id'] ?>">
                                    <?= sanitizar($p['nombre']) ?> (<?= sanitizar($p['tipo']) ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <label class="form-label fw-semibold">
                            Días y horarios disponibles <span class="text-danger">*</span>
                        </label>
                        <div class="small text-muted mb-2">Marque los días en que este pabellón estará disponible.</div>

                        <!-- Selector rápido -->
                        <div class="d-flex gap-1 mb-2">
                            <button type="button" class="btn btn-xs btn-outline-secondary py-0 px-2"
                                    style="font-size:.75rem" onclick="selDias([1,2,3,4,5])">
                                Lun–Vie
                            </button>
                            <button type="button" class="btn btn-xs btn-outline-secondary py-0 px-2"
                                    style="font-size:.75rem" onclick="selDias([1,2,3,4,5,6])">
                                Lun–Sáb
                            </button>
                            <button type="button" class="btn btn-xs btn-outline-secondary py-0 px-2"
                                    style="font-size:.75rem" onclick="selDias([])">
                                Limpiar
                            </button>
                        </div>

                        <?php foreach ($DIAS as $num => $nombre_dia): ?>
                        <div class="border rounded p-2 mb-2" id="bloque_dia_<?= $num ?>">
                            <div class="d-flex align-items-center gap-2">
                                <input type="checkbox" name="dias[]" value="<?= $num ?>"
                                       id="dia_<?= $num ?>" class="form-check-input dia-check"
                                       onchange="toggleDia(<?= $num ?>)">
                                <label for="dia_<?= $num ?>" class="form-check-label fw-semibold mb-0">
                                    <?= $nombre_dia ?>
                                </label>
                            </div>
                            <div id="config_dia_<?= $num ?>" class="row g-2 mt-1 d-none">
                                <div class="col-6">
                                    <label class="form-label" style="font-size:.75rem">Desde</label>
                                    <input type="time" name="hora_inicio[<?= $num ?>]"
                                           class="form-control form-control-sm" value="07:00">
                                </div>
                                <div class="col-6">
                                    <label class="form-label" style="font-size:.75rem">Hasta</label>
                                    <input type="time" name="hora_fin[<?= $num ?>]"
                                           class="form-control form-control-sm" value="21:00">
                                </div>
                
                            </div>
                        </div>
                        <?php endforeach; ?>

                        <button type="submit" class="btn btn-primary w-100 mt-2">
                            <i class="bi bi-plus-circle me-1"></i> Agregar al Período
                        </button>
                    </form>
                    <?php else: ?>
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-check-circle-fill text-success fs-2 mb-2 d-block"></i>
                        Todos los pabellones activos ya están configurados en este período.
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Modal editar disponibilidad -->
    <div class="modal fade" id="modalEditar" tabindex="-1">
      <div class="modal-dialog modal-lg">
        <form method="POST" class="modal-content">
            <?= csrf_campo() ?>
            <input type="hidden" name="accion_form" value="guardar_disponibilidad">
            <input type="hidden" name="id_periodo" value="<?= $periodo['id'] ?>">
            <input type="hidden" name="id_pabellon" id="me_pabellon_id">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-pencil me-2"></i>Editar: <span id="me_nombre"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-2">
                <?php foreach ($DIAS as $num => $nombre_dia): ?>
                <div class="col-md-6">
                    <div class="border rounded p-2">
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <input type="checkbox" name="dias[]" value="<?= $num ?>"
                                   id="me_dia_<?= $num ?>" class="form-check-input me-dia-check"
                                   onchange="toggleDiaModal(<?= $num ?>)">
                            <label for="me_dia_<?= $num ?>" class="form-check-label fw-semibold mb-0">
                                <?= $nombre_dia ?>
                            </label>
                        </div>
                        <div id="me_config_<?= $num ?>" class="row g-1 d-none">
                            <div class="col-6">
                                <input type="time" name="hora_inicio[<?= $num ?>]"
                                       id="me_hi_<?= $num ?>" class="form-control form-control-sm" value="07:00">
                            </div>
                            <div class="col-6">
                                <input type="time" name="hora_fin[<?= $num ?>]"
                                       id="me_hf_<?= $num ?>" class="form-control form-control-sm" value="21:00">
                            </div>

                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i> Guardar cambios</button>
            </div>
        </form>
      </div>
    </div>

    <script>
    function toggleDia(num) {
        const checked = document.getElementById('dia_' + num).checked;
        document.getElementById('config_dia_' + num).classList.toggle('d-none', !checked);
    }
    function toggleDiaModal(num) {
        const checked = document.getElementById('me_dia_' + num).checked;
        document.getElementById('me_config_' + num).classList.toggle('d-none', !checked);
    }
    function selDias(nums) {
        [1,2,3,4,5,6,7].forEach(n => {
            const cb = document.getElementById('dia_' + n);
            if (cb) {
                cb.checked = nums.includes(n);
                toggleDia(n);
            }
        });
    }
    function abrirEditar(idPab, nombre, diasConf) {
        document.getElementById('me_pabellon_id').value = idPab;
        document.getElementById('me_nombre').textContent = nombre;
        // Resetear todos los días
        [1,2,3,4,5,6,7].forEach(n => {
            const cb = document.getElementById('me_dia_' + n);
            if (!cb) return;
            const conf = diasConf[n];
            cb.checked = !!conf;
            document.getElementById('me_config_' + n).classList.toggle('d-none', !conf);
            if (conf) {
                document.getElementById('me_hi_' + n).value   = conf.hi;
                document.getElementById('me_hf_' + n).value   = conf.hf;
            }
        });
        new bootstrap.Modal(document.getElementById('modalEditar')).show();
    }
    </script>
    <?php
    layout_footer();
    return;
}

// ════════════════════════════════════════════════════════════
// VISTA: LISTA DE PERÍODOS
// ════════════════════════════════════════════════════════════
$lista = Database::fetchAll(
    "SELECT p.*,
            (SELECT COUNT(DISTINCT ppd.id_pabellon)
             FROM periodo_pabellon_detalle ppd WHERE ppd.id_periodo=p.id) AS total_pabellones,
            CONCAT(u.nombres,' ',u.apellidos) AS admin_nombre
     FROM periodos_pabellon p
     JOIN usuarios u ON u.id=p.id_admin
     WHERE p.id_institucion=?
     ORDER BY p.fecha_inicio DESC",
    [$id_inst]
);

layout_header('Programación de Pabellones');
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="text-muted small">
        <i class="bi bi-info-circle me-1"></i>
        Define los períodos de disponibilidad y los horarios de cada pabellón.
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalPeriodo"
            onclick="abrirModalPeriodo()">
        <i class="bi bi-plus-lg me-1"></i> Nuevo Período
    </button>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>

<!-- Períodos activos destacados -->
<?php
$activos = array_filter($lista, fn($p) => $p['estado']==='publicado' && $p['fecha_fin'] >= date('Y-m-d'));
if ($activos): ?>
<div class="row g-3 mb-4">
<?php foreach ($activos as $pa): ?>
<div class="col-md-6">
    <div class="pab-card p-3 border-success" style="border-left:4px solid #16a34a !important">
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-success">Publicado</span>
            <span class="fw-bold"><?= sanitizar($pa['nombre']) ?></span>
        </div>
        <div class="text-muted small mt-1">
            <i class="bi bi-calendar3 me-1"></i>
            <?= formato_fecha($pa['fecha_inicio']) ?> al <?= formato_fecha($pa['fecha_fin']) ?>
            &nbsp;·&nbsp;
            <i class="bi bi-door-open me-1"></i><?= $pa['total_pabellones'] ?> pabellones
        </div>
        <a href="?accion=detalle&id=<?= $pa['id'] ?>" class="btn btn-sm btn-outline-success mt-2">
            <i class="bi bi-eye me-1"></i> Ver detalle
        </a>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<div class="pab-card">
    <div class="card-header">
        <i class="bi bi-calendar-range"></i> Todos los períodos
    </div>
    <div class="table-responsive">
        <table class="table table-pab table-hover align-middle mb-0">
            <thead><tr>
                <th>Período</th><th>Fechas</th><th>Pabellones</th>
                <th>Estado</th><th>Creado por</th><th>Acciones</th>
            </tr></thead>
            <tbody>
            <?php foreach ($lista as $r):
                $badge = match($r['estado']) {
                    'publicado' => 'success', 'cerrado' => 'secondary', default => 'warning'
                };
                $hoy = date('Y-m-d');
                $vigente = $r['estado']==='publicado' && $r['fecha_inicio']<=$hoy && $r['fecha_fin']>=$hoy;
            ?>
            <tr <?= $vigente ? 'class="table-success"' : '' ?>>
                <td class="fw-semibold">
                    <?= sanitizar($r['nombre']) ?>
                    <?php if ($vigente): ?>
                    <span class="badge bg-success ms-1" style="font-size:.65rem">HOY</span>
                    <?php endif; ?>
                </td>
                <td class="small">
                    <?= formato_fecha($r['fecha_inicio']) ?> –
                    <?= formato_fecha($r['fecha_fin']) ?>
                </td>
                <td>
                    <span class="badge bg-primary bg-opacity-10 text-primary">
                        <i class="bi bi-door-open me-1"></i><?= $r['total_pabellones'] ?>
                    </span>
                </td>
                <td><span class="badge bg-<?= $badge ?>"><?= ucfirst($r['estado']) ?></span></td>
                <td class="small text-muted"><?= sanitizar($r['admin_nombre']) ?></td>
                <td>
                    <a href="?accion=detalle&id=<?= $r['id'] ?>"
                       title="Editar" title="<?= $r['estado']=='borrador' ? 'Configurar' : 'Ver detalle' ?>" class="btn btn-sm btn-outline-primary py-0">
                        <i class="bi bi-<?= $r['estado']==='borrador'?'pencil':'eye' ?>"></i>
                        <?= $r['estado']==='borrador'?'Configurar':'Ver' ?>
                    </a>
                    <?php if ($r['estado'] === 'borrador'): ?>
                    <button class="btn btn-sm btn-outline-secondary py-0"
                            onclick="abrirModalPeriodo(<?= htmlspecialchars(json_encode($r),ENT_QUOTES) ?>)">
                        <i class="bi bi-pencil-square"></i>
                    </button>
                    <?php endif; ?>
                    <?php if ($r['estado'] === 'publicado'): ?>
                    <form method="POST" class="d-inline">
                        <?= csrf_campo() ?>
                        <input type="hidden" name="accion_form" value="cambiar_estado">
                        <input type="hidden" name="id" value="<?= $r['id'] ?>">
                        <input type="hidden" name="estado" value="cerrado">
                        <button type="submit" title="Bloquear pabellón" class="btn btn-sm btn-outline-secondary py-0"
                                onclick="return confirm('¿Cerrar este período?')">
                            <i class="bi bi-lock"></i>
                        </button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$lista): ?>
            <tr><td colspan="6" class="text-center text-muted py-5">
                <i class="bi bi-calendar-x fs-1 d-block mb-2 opacity-25"></i>
                No hay períodos creados aún.
            </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal crear/editar período -->
<div class="modal fade" id="modalPeriodo" tabindex="-1">
  <div class="modal-dialog"><form method="POST" class="modal-content">
    <?= csrf_campo() ?>
    <input type="hidden" name="accion_form" value="guardar_periodo">
    <input type="hidden" name="id" id="mp_id">
    <div class="modal-header">
        <h5 class="modal-title" id="mp_titulo">Nuevo Período</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body row g-3">
        <div class="col-12">
            <label class="form-label fw-semibold">Nombre del período <span class="text-danger">*</span></label>
            <input type="text" name="nombre" id="mp_nombre" class="form-control"
                   placeholder="Ej: Semana 18 — Mayo 2026">
        </div>
        <div class="col-6">
            <label class="form-label fw-semibold">Fecha inicio <span class="text-danger">*</span></label>
            <input type="date" name="fecha_inicio" id="mp_desde" class="form-control"
                   oninput="sugerirNombre()">
        </div>
        <div class="col-6">
            <label class="form-label fw-semibold">Fecha fin <span class="text-danger">*</span></label>
            <input type="date" name="fecha_fin" id="mp_hasta" class="form-control"
                   oninput="sugerirNombre()">
        </div>
        <div class="col-12">
            <label class="form-label">Observación</label>
            <textarea name="observacion" id="mp_obs" class="form-control" rows="2"></textarea>
        </div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i> Guardar</button>
    </div>
  </form></div>
</div>

<script>
function abrirModalPeriodo(r) {
    document.getElementById('mp_titulo').textContent = r ? 'Editar Período' : 'Nuevo Período';
    document.getElementById('mp_id').value     = r ? r.id : '';
    document.getElementById('mp_nombre').value = r ? r.nombre : '';
    document.getElementById('mp_desde').value  = r ? r.fecha_inicio : '';
    document.getElementById('mp_hasta').value  = r ? r.fecha_fin : '';
    document.getElementById('mp_obs').value    = r ? (r.observacion||'') : '';
    new bootstrap.Modal(document.getElementById('modalPeriodo')).show();
}

function sugerirNombre() {
    const desde = document.getElementById('mp_desde').value;
    const hasta = document.getElementById('mp_hasta').value;
    const campo = document.getElementById('mp_nombre');
    if (!desde || campo.value) return;
    const d = new Date(desde + 'T00:00:00');
    const semana = Math.ceil((d - new Date(d.getFullYear(),0,1)) / 604800000);
    const meses = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
    campo.value = `Semana ${semana} — ${meses[d.getMonth()]} ${d.getFullYear()}`;
}
</script>
<?php layout_footer(); ?>
