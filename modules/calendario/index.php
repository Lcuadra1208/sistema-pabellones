<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/layout.php';
requiere_login();

$usuario   = usuario_actual();
$id_inst   = usuario_institucion();
$id_medico = (int)$usuario['id'];

// ── API JSON para FullCalendar ────────────────────────────
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    $inicio      = $_GET['start'] ?? date('Y-m-01');
    $fin         = $_GET['end']   ?? date('Y-m-t');
    $filtro_inst = (int)($_GET['inst'] ?? 0);  // filtro institución
    $filtro_pab  = (int)($_GET['pab']  ?? 0);  // filtro pabellón
    $filtro_tipo = sanitizar($_GET['tipo'] ?? ''); // 'solicitud' | 'oferta' | ''

    $eventos = [];

    // Solicitudes aprobadas
    if (es_superadmin()) {
        $rows = Database::fetchAll(
            "SELECT s.id,s.fecha_solicitada,s.hora_inicio,s.hora_fin,s.estado,
                    CONCAT(u.nombres,' ',u.apellidos) AS medico,
                    tc.nombre AS cirugia, p.nombre AS pabellon, tp.color_hex,
                    i.nombre AS institucion
             FROM solicitudes_pabellon s
             JOIN usuarios u ON u.id=s.id_medico
             JOIN tipos_cirugia tc ON tc.id=s.id_tipo_cirugia
             LEFT JOIN pabellones p ON p.id=s.id_pabellon
             LEFT JOIN tipos_pabellon tp ON tp.id=p.id_tipo_pabellon
             LEFT JOIN instituciones i ON i.id=s.id_institucion
             WHERE s.fecha_solicitada BETWEEN ? AND ? AND s.estado NOT IN ('cancelada','rechazada')
             ORDER BY s.fecha_solicitada,s.hora_inicio",
            [$inicio,$fin]
        );
    } elseif (es_admin()) {
        $rows = Database::fetchAll(
            "SELECT s.id,s.fecha_solicitada,s.hora_inicio,s.hora_fin,s.estado,
                    CONCAT(u.nombres,' ',u.apellidos) AS medico,
                    tc.nombre AS cirugia, p.nombre AS pabellon, tp.color_hex,
                    i.nombre AS institucion
             FROM solicitudes_pabellon s
             JOIN usuarios u ON u.id=s.id_medico
             JOIN tipos_cirugia tc ON tc.id=s.id_tipo_cirugia
             LEFT JOIN pabellones p ON p.id=s.id_pabellon
             LEFT JOIN tipos_pabellon tp ON tp.id=p.id_tipo_pabellon
             LEFT JOIN instituciones i ON i.id=s.id_institucion
             WHERE s.id_institucion=? AND s.fecha_solicitada BETWEEN ? AND ?
               AND s.estado NOT IN ('cancelada','rechazada')
             ORDER BY s.fecha_solicitada,s.hora_inicio",
            [$id_inst,$inicio,$fin]
        );
    } else {
        // Médico: sus operaciones + disponibilidades en instituciones acreditadas
        if ($filtro_tipo === 'oferta') { $rows = []; } else {
        $extra_med = '';
        $params_med = [$id_medico, $inicio, $fin];
        if ($filtro_inst) { $extra_med .= " AND s.id_institucion=?"; $params_med[] = $filtro_inst; }
        if ($filtro_pab)  { $extra_med .= " AND s.id_pabellon=?";    $params_med[] = $filtro_pab; }
        $rows = Database::fetchAll(
            "SELECT s.id,s.fecha_solicitada,s.hora_inicio,s.hora_fin,s.estado,
                    CONCAT(u.nombres,' ',u.apellidos) AS medico,
                    tc.nombre AS cirugia, p.nombre AS pabellon, tp.color_hex,
                    i.nombre AS institucion
             FROM solicitudes_pabellon s
             JOIN usuarios u ON u.id=s.id_medico
             JOIN tipos_cirugia tc ON tc.id=s.id_tipo_cirugia
             LEFT JOIN pabellones p ON p.id=s.id_pabellon
             LEFT JOIN tipos_pabellon tp ON tp.id=p.id_tipo_pabellon
             LEFT JOIN instituciones i ON i.id=s.id_institucion
             WHERE s.id_medico=? AND s.fecha_solicitada BETWEEN ? AND ?
               AND s.estado NOT IN ('cancelada','rechazada')
               $extra_med
             ORDER BY s.fecha_solicitada,s.hora_inicio",
            $params_med
        ); }
    }

    foreach ($rows as $r) {
        $color = $r['color_hex'] ?? '#3788d8';
        // Si la solicitud vino de una oferta, usar violeta medio
        $es_de_oferta = Database::fetchOne(
            "SELECT id FROM ofertas_pabellon WHERE id_solicitud=? LIMIT 1", [$r['id']]
        );
        $estado_color = match($r['estado']) {
            'pendiente'  => '#f59e0b',
            'aprobada'   => $es_de_oferta ? '#7c3aed' : $color,  // violeta si es de oferta
            'completada' => '#6b7280',
            default      => $color,
        };
        // Solicitudes sin pabellón → mostrar en all-day (no ocupan grilla horaria)
        $sin_pabellon = !$r['pabellon'];
        $eventos[] = [
            'id'      => 'sol_'.$r['id'],
            'title'   => ($r['pabellon'] ? $r['pabellon'].' · ' : '⏳ Sin pabellón · ') . $r['cirugia'],
            'start'   => $r['fecha_solicitada'].($sin_pabellon ? '' : 'T'.$r['hora_inicio']),
            'end'     => $sin_pabellon ? null : $r['fecha_solicitada'].'T'.$r['hora_fin'],
            'allDay'  => $sin_pabellon,
            'color'   => $estado_color,
            'extendedProps' => [
                'tipo'       => 'solicitud',
                'estado'     => $r['estado'],
                'medico'     => $r['medico'],
                'cirugia'    => $r['cirugia'],
                'pabellon'   => $r['pabellon'] ?? '—',
                'institucion'=> $r['institucion'] ?? '—',
                'id_sol'     => $r['id'],
            ],
        ];
    }

    // Bloqueos (solo admin y superadmin)
    if (!es_medico()) {
        $bwhere = es_admin() ? "WHERE p.id_institucion=$id_inst AND b.activo=1" : "WHERE b.activo=1";
        $bloqueos = Database::fetchAll(
            "SELECT b.*,p.nombre AS pabellon FROM bloqueos_pabellon b
             JOIN pabellones p ON p.id=b.id_pabellon $bwhere
             AND b.fecha_inicio <= ? AND b.fecha_fin >= ?",
            [$fin,$inicio]
        );
        foreach ($bloqueos as $b) {
            $eventos[] = [
                'id'    => 'blq_'.$b['id'],
                'title' => '🔒 '.$b['pabellon'].': '.$b['motivo'],
                'start' => $b['fecha_inicio'],
                'end'   => $b['fecha_fin'],
                'color' => '#dc2626',
                'extendedProps' => ['tipo'=>'bloqueo','motivo'=>$b['motivo'],'pabellon'=>$b['pabellon']],
            ];
        }
    }

    // Ofertas disponibles
    // Para médico: ve ofertas de su institución base + instituciones donde está acreditado
    if (es_medico()) {
        $extra_of = '';
        $params_of = [$inicio, $fin, $id_medico, $id_medico];
        if ($filtro_inst) { $extra_of .= " AND p.id_institucion=?"; $params_of[] = $filtro_inst; }
        if ($filtro_pab)  { $extra_of .= " AND o.id_pabellon=?";    $params_of[] = $filtro_pab; }
        $skip_ofertas = ($filtro_tipo === 'solicitud');
        $ofertas = $skip_ofertas ? [] : Database::fetchAll(
            "SELECT o.*, p.nombre AS pabellon, i.nombre AS inst_nombre
             FROM ofertas_pabellon o
             JOIN pabellones p ON p.id = o.id_pabellon
             JOIN instituciones i ON i.id = p.id_institucion
             WHERE o.estado = 'disponible'
               AND o.fecha_oferta BETWEEN ? AND ?
               AND p.id_institucion IN (
                   SELECT id_institucion FROM acreditaciones
                   WHERE id_medico = ? AND activo = 1
                   UNION
                   SELECT id_institucion FROM usuarios
                   WHERE id = ? AND id_institucion IS NOT NULL
               )
               $extra_of",
            $params_of
        );
    } elseif (es_admin()) {
        $ofertas = Database::fetchAll(
            "SELECT o.*, p.nombre AS pabellon, i.nombre AS inst_nombre
             FROM ofertas_pabellon o
             JOIN pabellones p ON p.id = o.id_pabellon
             JOIN instituciones i ON i.id = p.id_institucion
             WHERE o.estado = 'disponible'
               AND o.fecha_oferta BETWEEN ? AND ?
               AND p.id_institucion = ?",
            [$inicio, $fin, $id_inst]
        );
    } else {
        // Superadmin: todas las ofertas
        $ofertas = Database::fetchAll(
            "SELECT o.*, p.nombre AS pabellon, i.nombre AS inst_nombre
             FROM ofertas_pabellon o
             JOIN pabellones p ON p.id = o.id_pabellon
             JOIN instituciones i ON i.id = p.id_institucion
             WHERE o.estado = 'disponible'
               AND o.fecha_oferta BETWEEN ? AND ?",
            [$inicio, $fin]
        );
    }
    foreach ($ofertas as $of) {
        $eventos[] = [
            'id'    => 'ofr_'.$of['id'],
            'title' => '✅ '.$of['inst_nombre'].' — '.$of['pabellon'],
            'start' => $of['fecha_oferta'].'T'.$of['hora_inicio'],
            'end'   => $of['fecha_oferta'].'T'.$of['hora_fin'],
            'color' => '#166534',  // Verde oscuro — oferta disponible
            'extendedProps' => [
                'tipo'        => 'oferta',
                'id_oferta'   => $of['id'],
                'pabellon'   => $of['pabellon'],
                'institucion'=> $of['inst_nombre'],
            ],
        ];
    }

    // ── Disponibilidad programada desde períodos publicados ──
    // Solo visible para médicos (y superadmin para supervisión)
    // Genera eventos de fondo por cada día del período que corresponda
    if ($filtro_tipo !== 'solicitud') {
        // Instituciones accesibles por el médico (o todas para superadmin)
        if (es_medico()) {
            $inst_cond = "AND p.id_institucion IN (
                SELECT id_institucion FROM acreditaciones WHERE id_medico=$id_medico AND activo=1
                UNION
                SELECT id_institucion FROM usuarios WHERE id=$id_medico AND id_institucion IS NOT NULL
            )";
        } elseif (es_admin()) {
            $inst_cond = "AND p.id_institucion = $id_inst";
        } else {
            $inst_cond = "";
        }

        $filtro_pab_cond  = $filtro_pab  ? "AND ppd.id_pabellon = $filtro_pab"         : '';
        $filtro_inst_cond = $filtro_inst ? "AND per.id_institucion = $filtro_inst" : '';

        // Obtener detalles de períodos publicados que se cruzan con el rango visible
        $detalles = Database::fetchAll(
            "SELECT ppd.id_pabellon, ppd.dia_semana, ppd.hora_inicio, ppd.hora_fin,
                    p.nombre AS pabellon, tp.color_hex,
                    i.nombre AS inst_nombre, i.id AS id_inst,
                    per.fecha_inicio AS per_inicio, per.fecha_fin AS per_fin,
                    per.id AS id_periodo
             FROM periodo_pabellon_detalle ppd
             JOIN periodos_pabellon per ON per.id = ppd.id_periodo
             JOIN pabellones p          ON p.id   = ppd.id_pabellon
             JOIN tipos_pabellon tp     ON tp.id  = p.id_tipo_pabellon
             JOIN instituciones i       ON i.id   = p.id_institucion
             WHERE per.estado = 'publicado'
               AND per.fecha_inicio <= ? AND per.fecha_fin >= ?
               AND ppd.activo = 1
               $inst_cond $filtro_pab_cond $filtro_inst_cond
             ORDER BY ppd.id_pabellon, ppd.dia_semana, ppd.hora_inicio",
            [$fin, $inicio]
        );

        // Para cada detalle, generar un evento por cada fecha dentro del rango
        // que coincida con el día de semana configurado
        $fecha_cursor = max($inicio, date('Y-m-d')); // no mostrar días pasados
        $fecha_fin_vis = $fin;

        // Obtener solicitudes ya existentes para no mostrar disponibilidad ocupada
        $ocupados = Database::fetchAll(
            "SELECT id_pabellon, fecha_solicitada, hora_inicio, hora_fin
             FROM solicitudes_pabellon
             WHERE fecha_solicitada BETWEEN ? AND ?
               AND estado IN ('pendiente','aprobada')",
            [$inicio, $fin]
        );
        // Indexar ocupados por pabellon+fecha
        $ocupados_idx = [];
        foreach ($ocupados as $oc) {
            $ocupados_idx[$oc['id_pabellon'].'_'.$oc['fecha_solicitada']][] = [
                'hi' => $oc['hora_inicio'], 'hf' => $oc['hora_fin']
            ];
        }

        foreach ($detalles as $det) {
            // Recorrer cada fecha del rango visible
            $d = new DateTime(max($inicio, $det['per_inicio']));
            $d_fin = new DateTime(min($fin, $det['per_fin']));

            while ($d <= $d_fin) {
                $fecha_str = $d->format('Y-m-d');
                // día semana PHP: 0=dom,1=lun... | nuestro: 1=lun...7=dom
                $dow_php = (int)$d->format('N'); // 1=lun 7=dom
                if ($dow_php === (int)$det['dia_semana']) {
                    // Calcular tramos libres descontando solicitudes existentes
                    $key = $det['id_pabellon'].'_'.$fecha_str;
                    $ocups = $ocupados_idx[$key] ?? [];

                    // Verificar si hay bloqueo activo
                    $bloqueado = Database::fetchOne(
                        "SELECT id FROM bloqueos_pabellon
                         WHERE id_pabellon=? AND activo=1
                           AND fecha_inicio <= ? AND fecha_fin >= ?",
                        [$det['id_pabellon'], $fecha_str.' '.$det['hora_fin'],
                         $fecha_str.' '.$det['hora_inicio']]
                    );
                    if ($bloqueado) { $d->modify('+1 day'); continue; }

                    // Calcular tramos libres dentro del horario del pabellón
                    $tramos = calcularTramosLibres(
                        $det['hora_inicio'], $det['hora_fin'], $ocups
                    );

                    $color = $det['color_hex'];
                    $eventos[] = [
                        'id'              => 'disp_'.$det['id_periodo'].'_'.$det['id_pabellon'].'_'.$fecha_str,
                        'title'           => $det['pabellon'],
                        'start'           => $fecha_str.'T'.$det['hora_inicio'],
                        'end'             => $fecha_str.'T'.$det['hora_fin'],
                        'display'         => 'block',
                        'backgroundColor' => $color.'22',
                        'borderColor'     => $color,
                        'textColor'       => $color,
                        'classNames'      => ['evento-disponibilidad'],
                        'overlap'         => false,
                        'order'           => $det['id_pabellon'],
                        'extendedProps'   => [
                            'tipo'        => 'disponibilidad',
                            'pabellon'    => $det['pabellon'],
                            'institucion' => $det['inst_nombre'],
                            'id_pabellon' => $det['id_pabellon'],
                            'id_inst'     => $det['id_inst'],
                            'fecha'       => $fecha_str,
                            'hora_inicio' => $det['hora_inicio'],
                            'hora_fin'    => $det['hora_fin'],
                            'color'       => $color,
                        ],
                    ];
                }
                $d->modify('+1 day');
            }
        }
    }

    echo json_encode($eventos);
    exit;
}

// ── Función: calcular tramos libres ──────────────────────
function calcularTramosLibres(string $hi_pab, string $hf_pab, array $ocupados): array
{
    $toMin = fn($t) => (int)substr($t,0,2)*60 + (int)substr($t,3,2);
    $toTime = fn($m) => sprintf('%02d:%02d:00', intdiv($m,60), $m%60);
    $ini = $toMin($hi_pab);
    $fin = $toMin($hf_pab);
    usort($ocupados, fn($a,$b) => $toMin($a['hi']) <=> $toMin($b['hi']));
    $tramos = [];
    $cursor = $ini;
    foreach ($ocupados as $oc) {
        $oc_ini = $toMin($oc['hi']);
        $oc_fin = $toMin($oc['hf']);
        if ($oc_ini > $cursor) {
            $tramos[] = ['hi' => $toTime($cursor), 'hf' => $toTime($oc_ini)];
        }
        $cursor = max($cursor, $oc_fin);
    }
    if ($cursor < $fin) {
        $tramos[] = ['hi' => $toTime($cursor), 'hf' => $toTime($fin)];
    }
    return array_values(array_filter($tramos, fn($t) => $toMin($t['hf']) - $toMin($t['hi']) >= 30));
}
// ── Pabellones para filtro ────────────────────────────────
$pabellones_filtro = [];
if (!es_medico()) {
    $pabellones_filtro = Database::fetchAll(
        "SELECT p.id, p.nombre, p.id_institucion FROM pabellones p
         WHERE p.activo=1" . ($id_inst ? " AND p.id_institucion=$id_inst" : '') . " ORDER BY p.nombre"
    );
}

// ── Para médico: instituciones acreditadas + pabellones ───
$instituciones_medico = [];
$pabellones_medico    = [];
if (es_medico()) {
    $instituciones_medico = Database::fetchAll(
        "SELECT i.id, i.nombre, i.tipo
         FROM instituciones i
         WHERE i.activo=1
           AND i.id IN (
               SELECT id_institucion FROM acreditaciones WHERE id_medico=? AND activo=1
               UNION
               SELECT id_institucion FROM usuarios WHERE id=? AND id_institucion IS NOT NULL
           )
         ORDER BY i.nombre",
        [$id_medico, $id_medico]
    );
    // Pabellones de esas instituciones
    if ($instituciones_medico) {
        $ids_inst = implode(',', array_map(fn($i) => (int)$i['id'], $instituciones_medico));
        $pabellones_medico = Database::fetchAll(
            "SELECT p.id, p.nombre, p.id_institucion, i.nombre AS inst_nombre
             FROM pabellones p
             JOIN instituciones i ON i.id = p.id_institucion
             WHERE p.activo=1 AND p.id_institucion IN ($ids_inst)
             ORDER BY i.nombre, p.nombre"
        );
    }
}

layout_header('Calendario de Pabellones');
?>

<!-- ── Barra superior con filtros ─────────────────────── -->
<div class="pab-card p-2 mb-3">
    <div class="d-flex flex-wrap gap-2 align-items-center">

        <!-- Leyenda -->
        <div class="d-flex flex-wrap gap-3 align-items-center me-2">
            <span class="d-flex align-items-center gap-1 small"><span style="width:12px;height:12px;border-radius:3px;background:#f59e0b;display:inline-block"></span> Pendiente</span>
            <span class="d-flex align-items-center gap-1 small"><span style="width:12px;height:12px;border-radius:3px;background:#3788d8;display:inline-block"></span> Aprobada</span>
            <span class="d-flex align-items-center gap-1 small"><span style="width:12px;height:12px;border-radius:3px;background:#dc2626;display:inline-block"></span> Bloqueado</span>
            <span class="d-flex align-items-center gap-1 small"><span style="width:12px;height:12px;border-radius:3px;background:#166534;display:inline-block"></span> Oferta disponible</span>
            <span class="d-flex align-items-center gap-1 small"><span style="width:12px;height:12px;border-radius:3px;background:#7c3aed;display:inline-block"></span> Oferta tomada</span>
            <span class="d-flex align-items-center gap-1 small"><span style="width:12px;height:12px;border-radius:3px;background:#bbf7d0;border:2px solid #16a34a;display:inline-block"></span> Horario programado</span>
        </div>

        <div class="vr d-none d-md-block"></div>

        <?php if (es_medico() && $instituciones_medico): ?>
        <!-- Filtro institución (médico) -->
        <div class="d-flex align-items-center gap-1">
            <i class="bi bi-hospital text-muted small"></i>
            <select id="filtro_inst" class="form-select form-select-sm" style="min-width:180px;max-width:220px"
                    onchange="aplicarFiltros()">
                <option value="">Todas las instituciones</option>
                <?php foreach ($instituciones_medico as $ii): ?>
                <option value="<?= $ii['id'] ?>">
                    <?= sanitizar($ii['nombre']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Filtro pabellón (médico) -->
        <div class="d-flex align-items-center gap-1">
            <i class="bi bi-door-open text-muted small"></i>
            <select id="filtro_pab" class="form-select form-select-sm" style="min-width:160px;max-width:200px"
                    onchange="aplicarFiltros()">
                <option value="">Todos los pabellones</option>
                <?php foreach ($pabellones_medico as $pm): ?>
                <option value="<?= $pm['id'] ?>" data-inst="<?= $pm['id_institucion'] ?>">
                    <?= sanitizar($pm['inst_nombre']) ?> — <?= sanitizar($pm['nombre']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Filtro tipo de evento (médico) -->
        <div class="d-flex align-items-center gap-1">
            <i class="bi bi-funnel text-muted small"></i>
            <select id="filtro_tipo" class="form-select form-select-sm" style="min-width:150px"
                    onchange="aplicarFiltros()">
                <option value="">Todos los eventos</option>
                <option value="solicitud">Mis operaciones</option>
                <option value="oferta">Solo disponibles</option>
            </select>
        </div>

        <!-- Botón limpiar filtros -->
        <button class="btn btn-sm btn-outline-secondary" onclick="limpiarFiltros()" title="Limpiar filtros">
            <i class="bi bi-x-circle me-1"></i>Limpiar
        </button>

        <?php elseif (!es_medico()): ?>
        <!-- Filtro pabellón (admin/superadmin) -->
        <div class="d-flex align-items-center gap-1">
            <i class="bi bi-door-open text-muted small"></i>
            <select id="filtro_pab" class="form-select form-select-sm" style="min-width:180px"
                    onchange="aplicarFiltros()">
                <option value="">Todos los pabellones</option>
                <?php foreach ($pabellones_filtro as $p): ?>
                <option value="<?= $p['id'] ?>" data-inst="<?= $p['id_institucion'] ?>">
                    <?= sanitizar($p['nombre']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn btn-sm btn-outline-secondary" onclick="limpiarFiltros()" title="Limpiar filtros">
            <i class="bi bi-x-circle"></i>
        </button>
        <?php endif; ?>

        <!-- Botones de acción (derecha) -->
        <div class="ms-auto d-flex gap-2">
            <?php if (!es_medico()): ?>
            <a href="../solicitudes/index.php?accion=nueva" class="btn btn-sm btn-primary">
                <i class="bi bi-clipboard-plus me-1"></i> Nueva Solicitud
            </a>
            <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#modalBloqueo">
                <i class="bi bi-lock me-1"></i> Bloquear Pabellón
            </button>
            <?php else: ?>
            <a href="../solicitudes/index.php?accion=nueva" class="btn btn-sm btn-primary">
                <i class="bi bi-clipboard-plus me-1"></i> Solicitar Pabellón
            </a>
            <?php endif; ?>
        </div>

    </div>
</div>



<div class="pab-card p-3">
    <div id="calendario" style="min-height:600px"></div>
</div>

<!-- Modal detalle evento -->
<div class="modal fade" id="modalEvento" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
        <div class="modal-header">
            <h5 class="modal-title" id="me_titulo">Detalle</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body" id="me_body"></div>
        <div class="modal-footer" id="me_footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
        </div>
    </div>
  </div>
</div>

<!-- Modal bloqueo -->
<?php if (!es_medico()): ?>
<div class="modal fade" id="modalBloqueo" tabindex="-1">
  <div class="modal-dialog"><form method="POST" action="../solicitudes/bloqueos.php" class="modal-content">
    <?= csrf_campo() ?>
    <div class="modal-header"><h5 class="modal-title">Bloquear Pabellón</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body row g-3">
        <div class="col-12"><label class="form-label">Pabellón</label>
        <select name="id_pabellon" class="form-select">
            <?php foreach ($pabellones_filtro as $p): ?><option value="<?= $p['id'] ?>"><?= sanitizar($p['nombre']) ?></option><?php endforeach; ?>
        </select></div>
        <div class="col-6"><label class="form-label">Desde <span class="text-danger">*</span></label><input type="datetime-local" name="fecha_inicio" class="form-control" required></div>
        <div class="col-6"><label class="form-label">Hasta <span class="text-danger">*</span></label><input type="datetime-local" name="fecha_fin" class="form-control" required></div>
        <div class="col-12"><label class="form-label">Motivo <span class="text-danger">*</span></label><input type="text" name="motivo" class="form-control" placeholder="Mantenimiento, limpieza, emergencia..." required></div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-danger"><i class="bi bi-lock me-1"></i> Bloquear</button>
    </div>
  </form></div>
</div>
<?php endif; ?>

<style>
.evento-disponibilidad {
    opacity: 0.82 !important;
    cursor: pointer !important;
}
.evento-disponibilidad .fc-event-time  { display: none !important; }
.evento-disponibilidad .fc-event-title {
    font-size: .72rem !important;
    font-weight: 600 !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
}
/* Solicitudes encima — z-index mayor */
.fc-timegrid-event:not(.evento-disponibilidad) { z-index: 5 !important; }
.evento-disponibilidad { z-index: 2 !important; }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const calEl = document.getElementById('calendario');
    let _disponibilidades = [];
    const cal = new FullCalendar.Calendar(calEl, {
        locale: 'es',
        initialView: '<?= es_mantencion() ? "timeGridDay" : "timeGridWeek" ?>',
        initialDate: '<?= date('Y-m-d') ?>',
        headerToolbar: {
            left:   'prev,next today',
            center: 'title',
            right:  'dayGridMonth,timeGridWeek,timeGridDay,listWeek'
        },
        buttonText: { month:'Mes', week:'Semana', day:'Día', list:'Lista', today:'Hoy' },
        slotMinTime: '07:00:00',
        slotMaxTime: '22:00:00',
        slotDuration: '00:30:00',
        slotLabelInterval: '01:00',
        nowIndicator: true,
        height: 'auto',
        slotEventOverlap: false,
        eventOrder: 'order,start,-duration,allDay,title',
        businessHours: { daysOfWeek:[1,2,3,4,5,6], startTime:'07:00', endTime:'21:00' },
        // Array para guardar eventos de disponibilidad y hacer click en ellos
        eventDidMount: function(info) {
            if (info.event.extendedProps.tipo !== 'disponibilidad') return;
            _disponibilidades.push(info.event);
            // Tooltip en el fondo
            if (info.el) {
                const p = info.event.extendedProps;
                info.el.title = p.pabellon + ' · ' + p.institucion +
                    ' | ' + (p.hora_inicio||'').substring(0,5) +
                    ' – ' + (p.hora_fin||'').substring(0,5);
                info.el.style.cursor = 'pointer';
            }
        },

        dateClick: function(info) {
            // Buscar si hay disponibilidad en esa fecha/hora
            const clickTime = info.date;
            let found = null;
            _disponibilidades.forEach(ev => {
                if (ev.start <= clickTime && ev.end > clickTime
                    && ev.start.toDateString() === clickTime.toDateString()) {
                    found = ev;
                }
            });
            if (!found) return;
            const p = found.extendedProps;
            const body = `<div class="alert alert-success py-2 mb-3">
                              <i class="bi bi-check-circle-fill me-2"></i><strong>Horario disponible</strong>
                          </div>
                          <p class="mb-1"><i class="bi bi-hospital text-primary me-2"></i><strong>Institución:</strong> ${p.institucion}</p>
                          <p class="mb-1"><i class="bi bi-door-open text-muted me-2"></i><strong>Pabellón:</strong> ${p.pabellon}</p>
                          <p class="mb-1"><i class="bi bi-calendar3 text-muted me-2"></i><strong>Fecha:</strong> ${new Date(p.fecha + 'T00:00:00').toLocaleDateString('es-CL',{weekday:'long',day:'numeric',month:'long'})}</p>
                          <p class="mb-1"><i class="bi bi-clock text-muted me-2"></i><strong>Disponible:</strong> ${p.hora_inicio.substring(0,5)} – ${p.hora_fin.substring(0,5)}</p>`;
            <?php if (es_medico()): ?>
            const footer = `<a href="../ofertas/index.php?id_oferta=0&id_inst=${p.id_inst}&id_pabellon=${p.id_pabellon}&fecha=${p.fecha}&hora_inicio=${p.hora_inicio.substring(0,5)}&hora_fin=${p.hora_fin.substring(0,5)}"
                               class="btn btn-success">
                               <i class="bi bi-clipboard-plus me-1"></i>Solicitar este horario
                           </a>
                           <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>`;
            <?php else: ?>
            const footer = `<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>`;
            <?php endif; ?>
            document.getElementById('me_titulo').textContent = p.pabellon + ' — ' + p.institucion;
            document.getElementById('me_body').innerHTML = body;
            document.getElementById('me_footer').innerHTML = footer;
            new bootstrap.Modal(document.getElementById('modalEvento')).show();
        },

        events: function(fetchInfo, successCallback, failureCallback) {
            // Construir URL con filtros activos
            let params = new URLSearchParams({
                api: 1,
                start: fetchInfo.startStr,
                end:   fetchInfo.endStr,
            });
            const inst = document.getElementById('filtro_inst');
            const pab  = document.getElementById('filtro_pab');
            const tipo = document.getElementById('filtro_tipo');
            if (inst && inst.value) params.set('inst', inst.value);
            if (pab  && pab.value)  params.set('pab',  pab.value);
            if (tipo && tipo.value) params.set('tipo', tipo.value);

            fetch('?' + params.toString())
                .then(r => r.json())
                .then(data => successCallback(data))
                .catch(err => failureCallback(err));
        },
        eventClick: function(info) {
            // Ignorar clicks en eventos de fondo (background)
            if (info.event.display === 'background' || info.event.extendedProps.tipo === 'disponibilidad') {
                // Disparar dateClick manualmente con la fecha del evento
                const d = info.event.start;
                const p = info.event.extendedProps;
                const body = `<div class="alert alert-success py-2 mb-3">
                    <i class="bi bi-check-circle-fill me-2"></i><strong>Horario disponible</strong>
                </div>
                <p class="mb-1"><i class="bi bi-hospital text-primary me-2"></i><strong>Institución:</strong> ${p.institucion}</p>
                <p class="mb-1"><i class="bi bi-door-open text-muted me-2"></i><strong>Pabellón:</strong> ${p.pabellon}</p>
                <p class="mb-1"><i class="bi bi-calendar3 text-muted me-2"></i><strong>Fecha:</strong> ${new Date(p.fecha + 'T00:00:00').toLocaleDateString('es-CL',{weekday:'long',day:'numeric',month:'long'})}</p>
                <p class="mb-1"><i class="bi bi-clock text-muted me-2"></i><strong>Disponible:</strong> ${p.hora_inicio ? p.hora_inicio.substring(0,5) : ''} – ${p.hora_fin ? p.hora_fin.substring(0,5) : ''}</p>`;
                <?php if (es_medico()): ?>
                const footer = `<a href="../solicitudes/index.php?accion=nueva&id_inst=${p.id_inst}&id_pabellon=${p.id_pabellon}&fecha=${p.fecha}&hora_inicio=${p.hora_inicio ? p.hora_inicio.substring(0,5) : ''}&hora_fin=${p.hora_fin ? p.hora_fin.substring(0,5) : ''}" class="btn btn-success">
                    <i class="bi bi-clipboard-plus me-1"></i>Solicitar este horario
                </a>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>`;
                <?php else: ?>
                const footer = `<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>`;
                <?php endif; ?>
                document.getElementById('me_titulo').textContent = p.pabellon + ' — ' + p.institucion;
                document.getElementById('me_body').innerHTML = body;
                document.getElementById('me_footer').innerHTML = footer;
                new bootstrap.Modal(document.getElementById('modalEvento')).show();
                return;
            }
            const p = info.event.extendedProps;
            let body = '', footer = '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>';
            if (p.tipo === 'solicitud') {
                const badgeColor = {'pendiente':'warning','aprobada':'success','completada':'secondary','rechazada':'danger'}[p.estado] || 'secondary';
                body = `<p><strong>Estado:</strong> <span class="badge bg-${badgeColor}">${p.estado}</span></p>
                        <p><strong>Médico:</strong> ${p.medico}</p>
                        <p><strong>Cirugía:</strong> ${p.cirugia}</p>
                        <p><strong>Pabellón:</strong> ${p.pabellon}</p>
                        <p><strong>Institución:</strong> ${p.institucion}</p>`;
                <?php if (!es_medico()): ?>
                footer = `<a href="../solicitudes/index.php?id=${p.id_sol}" class="btn btn-primary btn-sm">Ver / Gestionar</a>
                          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cerrar</button>`;
                <?php endif; ?>
            } else if (p.tipo === 'bloqueo') {
                body = `<p><i class="bi bi-lock-fill text-danger me-2"></i><strong>Pabellón bloqueado</strong></p>
                        <p><strong>Pabellón:</strong> ${p.pabellon}</p>
                        <p><strong>Motivo:</strong> ${p.motivo}</p>`;
            } else if (p.tipo === 'disponibilidad') {
                body = `<div class="alert alert-success py-2 mb-3">
                            <i class="bi bi-check-circle-fill me-2"></i><strong>Horario disponible</strong>
                        </div>
                        <p class="mb-1"><i class="bi bi-hospital text-primary me-2"></i><strong>Institución:</strong> ${p.institucion}</p>
                        <p class="mb-1"><i class="bi bi-door-open text-muted me-2"></i><strong>Pabellón:</strong> ${p.pabellon}</p>
                        <p class="mb-1"><i class="bi bi-calendar3 text-muted me-2"></i><strong>Fecha:</strong> ${new Date(p.fecha + 'T00:00:00').toLocaleDateString('es-CL',{weekday:'long',day:'numeric',month:'long'})}</p>
                        <p class="mb-1"><i class="bi bi-clock text-muted me-2"></i><strong>Disponible:</strong> ${p.hora_inicio.substring(0,5)} – ${p.hora_fin.substring(0,5)}</p>`;
                <?php if (es_medico()): ?>
                footer = `<a href="../solicitudes/index.php?accion=nueva&id_inst=${p.id_inst}&id_pabellon=${p.id_pabellon}&fecha=${p.fecha}&hora_inicio=${p.hora_inicio.substring(0,5)}&hora_fin=${p.hora_fin.substring(0,5)}"
                             class="btn btn-success">
                              <i class="bi bi-clipboard-plus me-1"></i>Solicitar este horario
                          </a>
                          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>`;
                <?php else: ?>
                footer = `<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>`;
                <?php endif; ?>
            } else if (p.tipo === 'oferta') {
                body = `<div class="alert alert-success py-2 mb-3">
                            <i class="bi bi-check-circle-fill me-2"></i><strong>Espacio disponible</strong>
                        </div>
                        <p class="mb-1"><i class="bi bi-hospital text-primary me-2"></i><strong>Institución:</strong> ${p.institucion}</p>
                        <p class="mb-1"><i class="bi bi-door-open text-muted me-2"></i><strong>Pabellón:</strong> ${p.pabellon}</p>
                        <p class="mb-1"><i class="bi bi-clock text-muted me-2"></i><strong>Horario:</strong> ${info.event.start.toLocaleTimeString('es-CL',{hour:'2-digit',minute:'2-digit'})} – ${info.event.end ? info.event.end.toLocaleTimeString('es-CL',{hour:'2-digit',minute:'2-digit'}) : ''}
                        </p>`;
                <?php if (es_medico()): ?>
                footer = `<a href="../ofertas/index.php?id_oferta=${p.id_oferta}" class="btn btn-success">
                              <i class="bi bi-calendar-check me-1"></i>Solicitar este espacio
                          </a>
                          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>`;
                <?php else: ?>
                footer = `<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>`;
                <?php endif; ?>
            }
            document.getElementById('me_titulo').textContent = info.event.title;
            document.getElementById('me_body').innerHTML = body;
            document.getElementById('me_footer').innerHTML = footer;
            new bootstrap.Modal(document.getElementById('modalEvento')).show();
        },
    });
    cal.render();
    // Ir a fecha específica si viene por GET
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('fecha')) cal.gotoDate(urlParams.get('fecha'));

    // Guardar referencia global al calendario para los filtros
    window._cal = cal;
});

// ── Filtros ────────────────────────────────────────────────

function aplicarFiltros() {
    // Cuando cambia institución, actualizar opciones de pabellón
    const selInst = document.getElementById('filtro_inst');
    const selPab  = document.getElementById('filtro_pab');

    if (selInst && selPab) {
        const instVal = selInst.value;
        // Mostrar/ocultar opciones de pabellón según institución seleccionada
        Array.from(selPab.options).forEach(opt => {
            if (!opt.value) return; // "Todos" siempre visible
            opt.hidden = instVal ? (opt.dataset.inst !== instVal) : false;
        });
        // Si el pabellón seleccionado quedó oculto, resetear
        if (selPab.value && selPab.options[selPab.selectedIndex].hidden) {
            selPab.value = '';
        }
    }

    // Recargar eventos con los nuevos filtros
    if (window._cal) window._cal.refetchEvents();
}

function limpiarFiltros() {
    const ids = ['filtro_inst', 'filtro_pab', 'filtro_tipo'];
    ids.forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.value = '';
            // Mostrar todas las opciones ocultas
            Array.from(el.options).forEach(o => o.hidden = false);
        }
    });
    if (window._cal) window._cal.refetchEvents();
}
</script>
<?php layout_footer(); ?>
