<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/excel_writer.php';
requiere_login(ROL_ADMIN_HOSPITAL);

$id_inst       = es_superadmin() ? null : usuario_institucion();
$tipo          = $_GET['tipo'] ?? 'ocupacion';
$desde         = sanitizar($_GET['desde'] ?? date('Y-m-01'));
$hasta         = sanitizar($_GET['hasta'] ?? date('Y-m-t'));
$filtro_inst   = es_superadmin() ? (int)($_GET['id_inst'] ?? 0) : (int)$id_inst;
$where_inst    = $filtro_inst ? "AND s.id_institucion=$filtro_inst" : '';

// Instituciones para filtro (solo superadmin)
$instituciones_filtro = es_superadmin()
    ? Database::fetchAll("SELECT id, nombre FROM instituciones WHERE activo=1 ORDER BY nombre")
    : [];

// ── Ocupación por pabellón ────────────────────────────────
$ocupacion = Database::fetchAll(
    "SELECT p.nombre AS pabellon, tp.color_hex,
            COUNT(s.id) AS total_ops,
            SUM(TIMESTAMPDIFF(MINUTE,CONCAT(s.fecha_solicitada,' ',s.hora_inicio),CONCAT(s.fecha_solicitada,' ',s.hora_fin))) AS minutos_totales
     FROM solicitudes_pabellon s
     JOIN pabellones p ON p.id=s.id_pabellon
     JOIN tipos_pabellon tp ON tp.id=p.id_tipo_pabellon
     WHERE s.estado IN ('aprobada','completada')
       AND s.fecha_solicitada BETWEEN ? AND ?
       $where_inst
     GROUP BY p.id,p.nombre,tp.color_hex
     ORDER BY total_ops DESC",
    [$desde,$hasta]
);

// ── Por médico ────────────────────────────────────────────
$por_medico = Database::fetchAll(
    "SELECT CONCAT(u.nombres,' ',u.apellidos) AS medico, u.especialidad,
            COUNT(s.id) AS total_ops,
            SUM(TIMESTAMPDIFF(MINUTE,CONCAT(s.fecha_solicitada,' ',s.hora_inicio),CONCAT(s.fecha_solicitada,' ',s.hora_fin))) AS minutos_totales,
            COUNT(CASE WHEN s.estado='pendiente' THEN 1 END) AS pendientes
     FROM solicitudes_pabellon s
     JOIN usuarios u ON u.id=s.id_medico
     WHERE s.fecha_solicitada BETWEEN ? AND ?
       $where_inst
     GROUP BY s.id_medico,medico,u.especialidad
     ORDER BY total_ops DESC",
    [$desde,$hasta]
);

// ── Por tipo de cirugía ───────────────────────────────────
$por_cirugia = Database::fetchAll(
    "SELECT tc.nombre AS cirugia,
            COUNT(s.id) AS total,
            AVG(TIMESTAMPDIFF(MINUTE,CONCAT(s.fecha_solicitada,' ',s.hora_inicio),CONCAT(s.fecha_solicitada,' ',s.hora_fin))) AS duracion_prom,
            SUM(COALESCE(pc.precio_neto,0)) AS facturacion_est
     FROM solicitudes_pabellon s
     JOIN tipos_cirugia tc ON tc.id=s.id_tipo_cirugia
     LEFT JOIN precios_cirugia pc ON pc.id_tipo_cirugia=s.id_tipo_cirugia
         AND pc.vigente_desde<=s.fecha_solicitada AND (pc.vigente_hasta IS NULL OR pc.vigente_hasta>=s.fecha_solicitada)
     WHERE s.estado IN ('aprobada','completada')
       AND s.fecha_solicitada BETWEEN ? AND ?
       $where_inst
     GROUP BY tc.id,tc.nombre
     ORDER BY total DESC",
    [$desde,$hasta]
);

// ── KPIs resumen ──────────────────────────────────────────
$kpis = Database::fetchOne(
    "SELECT COUNT(*) AS total,
            COUNT(CASE WHEN estado='aprobada' THEN 1 END) AS aprobadas,
            COUNT(CASE WHEN estado='pendiente' THEN 1 END) AS pendientes,
            COUNT(CASE WHEN estado='rechazada' THEN 1 END) AS rechazadas,
            COUNT(CASE WHEN estado='cancelada' THEN 1 END) AS canceladas
     FROM solicitudes_pabellon s
     WHERE s.fecha_solicitada BETWEEN ? AND ? $where_inst",
    [$desde,$hasta]
);

// ── Exportar Excel ───────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $excel = new ExcelWriter('Reporte Pabellones');

    // Hoja 1: KPIs
    $excel->agregarHoja('Resumen', ['Indicador','Cantidad'], [
        ['Total solicitudes', $kpis['total']],
        ['Aprobadas',         $kpis['aprobadas']],
        ['Pendientes',        $kpis['pendientes']],
        ['Rechazadas',        $kpis['rechazadas']],
        ['Canceladas',        $kpis['canceladas']],
    ]);

    // Hoja 2: Ocupación por pabellón
    $dias = max(1,(strtotime($hasta)-strtotime($desde))/86400+1);
    $horas_disp = $dias * 14;
    $filas_ocup = [];
    foreach ($ocupacion as $r) {
        $horas = round($r['minutos_totales']/60,1);
        $filas_ocup[] = [
            $r['pabellon'],
            $r['total_ops'],
            $horas,
            min(100,round($horas/$horas_disp*100,1)).'%',
        ];
    }
    $excel->agregarHoja('Ocupación por Pabellón',
        ['Pabellón','Operaciones','Horas de uso','% Utilización'],
        $filas_ocup
    );

    // Hoja 3: Por médico
    $filas_med = [];
    foreach ($por_medico as $r) {
        $filas_med[] = [
            $r['medico'],
            $r['especialidad'] ?? '—',
            $r['total_ops'],
            round($r['minutos_totales']/60,1),
            $r['pendientes'],
        ];
    }
    $excel->agregarHoja('Actividad por Médico',
        ['Médico','Especialidad','Operaciones','Horas quirúrgicas','Pendientes'],
        $filas_med
    );

    // Hoja 4: Por cirugía
    $filas_cir = [];
    $total_fact = 0;
    foreach ($por_cirugia as $r) {
        $total_fact += $r['facturacion_est'];
        $filas_cir[] = [
            $r['cirugia'],
            $r['total'],
            round($r['duracion_prom']).' min',
            '$'.number_format($r['facturacion_est'],0,',','.'),
        ];
    }
    $excel->agregarHoja('Por Tipo de Cirugía',
        ['Cirugía','Total ops.','Dur. promedio','Facturación estimada'],
        $filas_cir,
        ['TOTAL','','','$'.number_format($total_fact,0,',','.')]
    );

    $nombre = 'reporte_pabellones_'.date('Ymd');
    $excel->descargar($nombre);
}

// ── Exportar PDF (impresión) ──────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    // Generar vista HTML optimizada para impresión
    $inst_nombre = $filtro_inst
        ? (Database::fetchOne("SELECT nombre FROM instituciones WHERE id=?",[$filtro_inst])['nombre'] ?? 'Todas')
        : 'Todas las instituciones';
    header('Content-Type: text/html; charset=utf-8');
    include __DIR__ . '/pdf_print.php';
    exit;
}

layout_header('Reportes');
?>
<!-- Filtros -->
<div class="pab-card p-3 mb-4">
    <form method="GET" class="row g-2 align-items-end">
        <?php if (es_superadmin()): ?>
        <div class="col-md-3">
            <label class="form-label small">Institución</label>
            <select name="id_inst" class="form-select form-select-sm">
                <option value="">Todas las instituciones</option>
                <?php foreach ($instituciones_filtro as $inst): ?>
                <option value="<?= $inst['id'] ?>" <?= $filtro_inst == $inst['id'] ? 'selected' : '' ?>>
                    <?= sanitizar($inst['nombre']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div class="col-md-<?= es_superadmin() ? '2' : '3' ?>">
            <label class="form-label small">Desde</label>
            <input type="date" name="desde" class="form-control form-control-sm" value="<?= $desde ?>">
        </div>
        <div class="col-md-<?= es_superadmin() ? '2' : '3' ?>">
            <label class="form-label small">Hasta</label>
            <input type="date" name="hasta" class="form-control form-control-sm" value="<?= $hasta ?>">
        </div>
        <input type="hidden" name="tipo" value="<?= $tipo ?>">
        <div class="col-md-2 d-flex align-items-end gap-1">
            <button type="submit" class="btn btn-primary btn-sm flex-grow-1">
                <i class="bi bi-funnel me-1"></i>Filtrar
            </button>
        </div>
    </form>
</div>

<!-- Botones de exportación -->
<div class="d-flex gap-2 justify-content-end mb-3">
    <a href="?desde=<?= $desde ?>&hasta=<?= $hasta ?>&tipo=<?= $tipo ?>&id_inst=<?= $filtro_inst ?>&export=excel"
       class="btn btn-success btn-sm" title="Exportar a Excel">
        <i class="bi bi-file-earmark-excel me-1"></i> Excel
    </a>
    <a href="?desde=<?= $desde ?>&hasta=<?= $hasta ?>&tipo=<?= $tipo ?>&id_inst=<?= $filtro_inst ?>&export=pdf"
       target="_blank" class="btn btn-danger btn-sm" title="Exportar a PDF / Imprimir">
        <i class="bi bi-file-earmark-pdf me-1"></i> PDF
    </a>
</div>

<!-- KPIs -->
<div class="row g-3 mb-4">
    <?php
    $kpi_cards = [
        ['Total solicitudes','bi-clipboard','primary',$kpis['total']],
        ['Aprobadas','bi-check-circle','success',$kpis['aprobadas']],
        ['Pendientes','bi-hourglass','warning',$kpis['pendientes']],
        ['Rechazadas','bi-x-circle','danger',$kpis['rechazadas']],
    ];
    foreach ($kpi_cards as [$lbl,$ico,$color,$val]):
    ?>
    <div class="col-6 col-md-3">
        <div class="pab-card p-3 d-flex align-items-center gap-3">
            <div class="rounded-3 p-2 bg-<?= $color ?> bg-opacity-10 text-<?= $color ?>">
                <i class="bi <?= $ico ?> fs-4"></i>
            </div>
            <div><div class="fs-3 fw-bold"><?= $val ?></div><div class="text-muted small"><?= $lbl ?></div></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Tabs de reportes -->
<ul class="nav nav-tabs mb-4">
    <li class="nav-item"><a class="nav-link <?= $tipo==='ocupacion'?'active':'' ?>" href="?desde=<?= $desde ?>&hasta=<?= $hasta ?>&tipo=ocupacion&id_inst=<?= $filtro_inst ?>"><i class="bi bi-door-open me-1"></i>Ocupación</a></li>
    <li class="nav-item"><a class="nav-link <?= $tipo==='medicos'?'active':'' ?>" href="?desde=<?= $desde ?>&hasta=<?= $hasta ?>&tipo=medicos&id_inst=<?= $filtro_inst ?>"><i class="bi bi-people me-1"></i>Por Médico</a></li>
    <li class="nav-item"><a class="nav-link <?= $tipo==='cirugias'?'active':'' ?>" href="?desde=<?= $desde ?>&hasta=<?= $hasta ?>&tipo=cirugias&id_inst=<?= $filtro_inst ?>"><i class="bi bi-scissors me-1"></i>Por Cirugía</a></li>
</ul>

<?php if ($tipo === 'ocupacion'): ?>
<div class="pab-card">
    <div class="card-header"><i class="bi bi-door-open"></i> Ocupación por pabellón — <?= formato_fecha($desde) ?> al <?= formato_fecha($hasta) ?><?= $filtro_inst ? ' · '.sanitizar(array_column($instituciones_filtro,'nombre','id')[$filtro_inst] ?? '') : '' ?></div>
    <div class="table-responsive">
        <table class="table table-pab align-middle mb-0">
            <thead><tr><th>Pabellón</th><th>Operaciones</th><th>Horas de uso</th><th>Utilización</th></tr></thead>
            <tbody>
            <?php
            $dias = max(1,(strtotime($hasta)-strtotime($desde))/86400+1);
            $horas_disponibles = $dias * 14; // 14h/día hábil estimado
            foreach ($ocupacion as $r):
                $horas = round($r['minutos_totales']/60,1);
                $pct   = min(100,round($horas/$horas_disponibles*100,1));
            ?>
            <tr>
                <td>
                    <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:<?= sanitizar($r['color_hex']) ?>;margin-right:6px"></span>
                    <?= sanitizar($r['pabellon']) ?>
                </td>
                <td><span class="badge bg-primary bg-opacity-10 text-primary"><?= $r['total_ops'] ?></span></td>
                <td><?= $horas ?> h</td>
                <td>
                    <div class="d-flex align-items-center gap-2">
                        <div class="progress flex-grow-1" style="height:8px">
                            <div class="progress-bar bg-<?= $pct>80?'danger':($pct>50?'warning':'success') ?>"
                                 style="width:<?= $pct ?>%"></div>
                        </div>
                        <span class="small text-muted"><?= $pct ?>%</span>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($tipo === 'medicos'): ?>
<div class="pab-card">
    <div class="card-header"><i class="bi bi-people"></i> Actividad por médico</div>
    <div class="table-responsive">
        <table class="table table-pab align-middle mb-0">
            <thead><tr><th>Médico</th><th>Especialidad</th><th>Operaciones</th><th>Horas quirúrgicas</th><th>Pendientes</th></tr></thead>
            <tbody>
            <?php foreach ($por_medico as $r): ?>
            <tr>
                <td class="fw-semibold"><i class="bi bi-person-circle text-muted me-1"></i><?= sanitizar($r['medico']) ?></td>
                <td class="small text-muted"><?= sanitizar($r['especialidad'] ?? '—') ?></td>
                <td><span class="badge bg-success bg-opacity-10 text-success"><?= $r['total_ops'] ?></span></td>
                <td><?= round($r['minutos_totales']/60,1) ?> h</td>
                <td><?= $r['pendientes'] > 0 ? '<span class="badge bg-warning text-dark">'.$r['pendientes'].'</span>' : '—' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php else: ?>
<div class="pab-card">
    <div class="card-header"><i class="bi bi-scissors"></i> Análisis por tipo de cirugía</div>
    <div class="table-responsive">
        <table class="table table-pab align-middle mb-0">
            <thead><tr><th>Cirugía</th><th>Total ops.</th><th>Dur. promedio</th><th>Facturación estimada</th></tr></thead>
            <tbody>
            <?php
            $total_fact = array_sum(array_column($por_cirugia,'facturacion_est'));
            foreach ($por_cirugia as $r):
            ?>
            <tr>
                <td class="fw-semibold"><?= sanitizar($r['cirugia']) ?></td>
                <td><?= $r['total'] ?></td>
                <td><i class="bi bi-clock text-muted me-1"></i><?= round($r['duracion_prom']) ?> min</td>
                <td class="fw-bold text-success"><?= formato_peso((float)$r['facturacion_est']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if ($por_cirugia): ?>
            <tr class="table-light fw-bold">
                <td colspan="3">Total estimado</td>
                <td class="text-success"><?= formato_peso($total_fact) ?></td>
            </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
<?php layout_footer(); ?>
