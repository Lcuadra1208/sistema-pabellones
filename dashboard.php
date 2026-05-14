<?php
/**
 * dashboard.php — Inicio del sistema
 */
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/layout.php';
requiere_login();

$usuario      = usuario_actual();
$id_inst      = usuario_institucion();
$hoy          = date('Y-m-d');
$inicio_semana = date('Y-m-d', strtotime('monday this week'));
$fin_semana    = date('Y-m-d', strtotime('sunday this week'));

// ── Estadísticas según rol ────────────────────────────────
$stats = [];

if (es_superadmin()) {
    $stats['instituciones'] = Database::fetchOne("SELECT COUNT(*) AS n FROM instituciones WHERE activo=1")['n'] ?? 0;
    $stats['usuarios']      = Database::fetchOne("SELECT COUNT(*) AS n FROM usuarios WHERE activo=1")['n'] ?? 0;
    $stats['solicitudes_hoy'] = Database::fetchOne(
        "SELECT COUNT(*) AS n FROM solicitudes_pabellon WHERE DATE(created_at)=?", [$hoy])['n'] ?? 0;
    $stats['pabellones']    = Database::fetchOne("SELECT COUNT(*) AS n FROM pabellones WHERE activo=1")['n'] ?? 0;

} elseif (es_admin()) {
    $stats['pabellones'] = Database::fetchOne(
        "SELECT COUNT(*) AS n FROM pabellones WHERE id_institucion=? AND activo=1", [$id_inst])['n'] ?? 0;
    $stats['pendientes'] = Database::fetchOne(
        "SELECT COUNT(*) AS n FROM solicitudes_pabellon WHERE id_institucion=? AND estado='pendiente'",
        [$id_inst])['n'] ?? 0;
    $stats['semana'] = Database::fetchOne(
        "SELECT COUNT(*) AS n FROM solicitudes_pabellon
         WHERE id_institucion=? AND fecha_solicitada BETWEEN ? AND ?",
        [$id_inst, $inicio_semana, $fin_semana])['n'] ?? 0;
    $stats['medicos'] = Database::fetchOne(
        "SELECT COUNT(*) AS n FROM usuarios WHERE id_institucion=? AND id_rol=? AND activo=1",
        [$id_inst, ROL_MEDICO])['n'] ?? 0;
    // Checklist configuración
    $checklist = [
        ['label'=>'Tipos de Cirugía', 'url'=>'modules/mantenedores/tipos_cirugia.php',
         'ok'=> (Database::fetchOne("SELECT COUNT(*) n FROM tipos_cirugia WHERE id_institucion=? AND activo=1",[$id_inst])['n']??0) > 0],
        ['label'=>'Insumos', 'url'=>'modules/mantenedores/insumos.php',
         'ok'=> (Database::fetchOne("SELECT COUNT(*) n FROM insumos WHERE id_institucion=? AND activo=1",[$id_inst])['n']??0) > 0],
        ['label'=>'Precios', 'url'=>'modules/mantenedores/precios.php',
         'ok'=> (function($id){ try{ return (Database::fetchOne("SELECT COUNT(*) n FROM precios_tipo_pabellon WHERE id_institucion=? AND (vigente_hasta IS NULL OR vigente_hasta >= CURDATE())",[$id])['n']??0)>0; }catch(\Exception $e){ return false; } })($id_inst)],
        ['label'=>'Acreditaciones', 'url'=>'modules/mantenedores/acreditaciones.php',
         'ok'=> (Database::fetchOne("SELECT COUNT(*) n FROM acreditaciones WHERE id_institucion=? AND activo=1",[$id_inst])['n']??0) > 0],
        ['label'=>'Pabellones', 'url'=>'modules/mantenedores/pabellones.php',
         'ok'=> $stats['pabellones'] > 0],
    ];
    $config_completa = !in_array(false, array_column($checklist,'ok'));

} else {
    // Médico
    $id_medico = (int)$usuario['id'];
    $stats['mis_semana'] = Database::fetchOne(
        "SELECT COUNT(*) AS n FROM solicitudes_pabellon
         WHERE id_medico=? AND fecha_solicitada BETWEEN ? AND ?",
        [$id_medico, $inicio_semana, $fin_semana])['n'] ?? 0;
    $stats['pendientes'] = Database::fetchOne(
        "SELECT COUNT(*) AS n FROM solicitudes_pabellon
         WHERE id_medico=? AND estado='pendiente'", [$id_medico])['n'] ?? 0;
    $stats['aprobadas'] = Database::fetchOne(
        "SELECT COUNT(*) AS n FROM solicitudes_pabellon
         WHERE id_medico=? AND estado='aprobada' AND fecha_solicitada >= ?",
        [$id_medico, $hoy])['n'] ?? 0;
}

// ── Checklist config para admin ──────────────────────────
// (ya calculado arriba si es_admin())

// ── Próximas operaciones ──────────────────────────────────
$cond_prox = es_superadmin()
    ? "WHERE s.fecha_solicitada >= ? AND s.estado = 'aprobada'"
    : (es_admin()
        ? "WHERE s.fecha_solicitada >= ? AND s.estado = 'aprobada' AND s.id_institucion = " . (int)$id_inst
        : "WHERE s.fecha_solicitada >= ? AND s.estado = 'aprobada' AND s.id_medico = " . (int)($usuario['id']));

$proximas = Database::fetchAll(
    "SELECT s.fecha_solicitada, s.hora_inicio, s.hora_fin,
            CONCAT(u.nombres,' ',u.apellidos) AS medico,
            tc.nombre AS cirugia, p.nombre AS pabellon, i.nombre AS institucion
     FROM solicitudes_pabellon s
     JOIN usuarios u     ON u.id = s.id_medico
     JOIN tipos_cirugia tc ON tc.id = s.id_tipo_cirugia
     LEFT JOIN pabellones p  ON p.id  = s.id_pabellon
     LEFT JOIN instituciones i ON i.id = s.id_institucion
     $cond_prox
     ORDER BY s.fecha_solicitada, s.hora_inicio
     LIMIT 8",
    [$hoy]
);

layout_header('Dashboard');
?>

<div class="row g-3 mb-4">

<?php if (es_superadmin()): ?>
    <!-- Superadmin stats -->
    <?php
    $cards = [
        ['icon'=>'bi-building','label'=>'Instituciones activas','val'=>$stats['instituciones'],'color'=>'primary'],
        ['icon'=>'bi-people',  'label'=>'Usuarios activos',     'val'=>$stats['usuarios'],      'color'=>'success'],
        ['icon'=>'bi-door-open','label'=>'Pabellones',          'val'=>$stats['pabellones'],    'color'=>'info'],
        ['icon'=>'bi-clipboard-plus','label'=>'Solicitudes hoy','val'=>$stats['solicitudes_hoy'],'color'=>'warning'],
    ];
    ?>

<?php elseif (es_admin()): ?>
    <?php
    $cards = [
        ['icon'=>'bi-door-open',      'label'=>'Pabellones activos', 'val'=>$stats['pabellones'],'color'=>'primary'],
        ['icon'=>'bi-people',         'label'=>'Médicos',            'val'=>$stats['medicos'],   'color'=>'success'],
        ['icon'=>'bi-calendar-week',  'label'=>'Ops esta semana',    'val'=>$stats['semana'],    'color'=>'info'],
        ['icon'=>'bi-hourglass-split','label'=>'Solicitudes pendientes','val'=>$stats['pendientes'],'color'=>'warning'],
    ];
    ?>

<?php else: ?>
    <?php
    $cards = [
        ['icon'=>'bi-calendar-week',  'label'=>'Mis ops esta semana','val'=>$stats['mis_semana'],'color'=>'primary'],
        ['icon'=>'bi-hourglass-split','label'=>'Pendientes',          'val'=>$stats['pendientes'],'color'=>'warning'],
        ['icon'=>'bi-check-circle',   'label'=>'Próximas aprobadas',  'val'=>$stats['aprobadas'], 'color'=>'success'],
    ];
    ?>
<?php endif; ?>

    <?php foreach ($cards as $card): ?>
    <div class="col-6 col-md-3">
        <div class="pab-card p-3 d-flex align-items-center gap-3">
            <div class="rounded-3 p-2 bg-<?= $card['color'] ?> bg-opacity-10 text-<?= $card['color'] ?>">
                <i class="bi <?= $card['icon'] ?> fs-4"></i>
            </div>
            <div>
                <div class="fs-4 fw-bold"><?= $card['val'] ?></div>
                <div class="text-muted small"><?= $card['label'] ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Checklist configuración (solo admin con config incompleta) -->
<?php if (es_admin() && isset($checklist) && !$config_completa): ?>
<div class="pab-card mb-4">
    <div class="card-header" style="background:rgba(245,158,11,.08);border-bottom:1px solid rgba(245,158,11,.2)">
        <i class="bi bi-list-check text-warning"></i>
        <span class="text-warning">Pasos de configuración recomendados</span>
        <span class="ms-auto badge bg-warning text-dark">
            <?= count(array_filter(array_column($checklist,'ok'))) ?>/<?= count($checklist) ?> completados
        </span>
    </div>
    <div class="p-3">
        <div class="row g-2">
        <?php foreach ($checklist as $idx => $paso): ?>
        <div class="col-md-4 col-6">
            <a href="<?= APP_URL ?>/<?= $paso['url'] ?>"
               class="d-flex align-items-center gap-2 p-2 rounded-2 text-decoration-none
                      <?= $paso['ok'] ? 'bg-success bg-opacity-10' : 'bg-warning bg-opacity-10' ?>">
                <span class="fs-5 <?= $paso['ok'] ? 'text-success' : 'text-warning' ?>">
                    <i class="bi bi-<?= $paso['ok'] ? 'check-circle-fill' : 'circle' ?>"></i>
                </span>
                <div>
                    <div class="small fw-semibold <?= $paso['ok'] ? 'text-success' : 'text-dark' ?>">
                        <?= $idx+1 ?>. <?= $paso['label'] ?>
                    </div>
                    <div class="text-muted" style="font-size:.7rem">
                        <?= $paso['ok'] ? 'Configurado ✓' : 'Pendiente — clic para configurar' ?>
                    </div>
                </div>
            </a>
        </div>
        <?php endforeach; ?>
        </div>
        <?php if (!$checklist[4]['ok'] && $checklist[0]['ok']): ?>
        <div class="alert alert-info py-2 mt-3 mb-0 small">
            <i class="bi bi-info-circle me-1"></i>
            Una vez configurados los parámetros (pasos 1–4), podrá crear los <strong>Pabellones</strong> y comenzar a asignarlos.
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Próximas operaciones -->
<div class="pab-card">
    <div class="card-header">
        <i class="bi bi-calendar-check"></i>
        Próximas operaciones agendadas
    </div>
    <div class="table-responsive">
        <?php if ($proximas): ?>
        <table class="table table-pab table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Hora</th>
                    <?php if (!es_medico()): ?><th>Médico</th><?php endif; ?>
                    <?php if (es_superadmin()): ?><th>Institución</th><?php endif; ?>
                    <th>Cirugía</th>
                    <th>Pabellón</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($proximas as $op): ?>
                <tr>
                    <td><i class="bi bi-calendar3 text-muted me-1"></i><?= formato_fecha($op['fecha_solicitada']) ?></td>
                    <td><?= formato_hora($op['hora_inicio']) ?> – <?= formato_hora($op['hora_fin']) ?></td>
                    <?php if (!es_medico()): ?>
                    <td><i class="bi bi-person-circle text-muted me-1"></i><?= sanitizar($op['medico']) ?></td>
                    <?php endif; ?>
                    <?php if (es_superadmin()): ?>
                    <td><span class="badge bg-light text-dark"><?= sanitizar($op['institucion']) ?></span></td>
                    <?php endif; ?>
                    <td><?= sanitizar($op['cirugia']) ?></td>
                    <td>
                        <?php if ($op['pabellon']): ?>
                            <span class="badge bg-primary bg-opacity-10 text-primary">
                                <i class="bi bi-door-open me-1"></i><?= sanitizar($op['pabellon']) ?>
                            </span>
                        <?php else: ?>
                            <span class="text-muted small">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="<?= APP_URL ?>/modules/calendario/index.php?fecha=<?= $op['fecha_solicitada'] ?>"
                           title="Ver en calendario"
                           class="btn btn-sm btn-outline-primary py-0">
                            <i class="bi bi-calendar3"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="text-center py-5 text-muted">
            <i class="bi bi-calendar-x fs-1 d-block mb-2 opacity-25"></i>
            No hay operaciones agendadas próximas.
        </div>
        <?php endif; ?>
    </div>
</div>

<?php layout_footer(); ?>
