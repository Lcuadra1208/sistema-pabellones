<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/layout.php';
requiere_login(ROL_SUPERADMIN);

$desde  = sanitizar($_GET['desde']   ?? date('Y-m-01'));
$hasta  = sanitizar($_GET['hasta']   ?? date('Y-m-d'));
$filtro_usuario = (int)($_GET['id_usuario'] ?? 0);
$filtro_accion  = sanitizar($_GET['accion'] ?? '');
$pagina = max(1,(int)($_GET['pag'] ?? 1));
$por_pagina = 50;
$offset = ($pagina-1)*$por_pagina;

$where = "WHERE al.created_at BETWEEN ? AND ? + INTERVAL 1 DAY";
$params = [$desde, $hasta];

if ($filtro_usuario) {
    $where .= " AND al.id_usuario=?";
    $params[] = $filtro_usuario;
}
if ($filtro_accion) {
    $where .= " AND al.accion LIKE ?";
    $params[] = '%'.$filtro_accion.'%';
}

$total = Database::fetchOne(
    "SELECT COUNT(*) n FROM audit_log al $where", $params
)['n'] ?? 0;

$logs = Database::fetchAll(
    "SELECT al.*, CONCAT(u.nombres,' ',u.apellidos) AS usuario_nombre,
            u.id_rol, r.nombre AS rol_nombre
     FROM audit_log al
     LEFT JOIN usuarios u ON u.id=al.id_usuario
     LEFT JOIN roles r ON r.id=u.id_rol
     $where
     ORDER BY al.created_at DESC
     LIMIT $por_pagina OFFSET $offset",
    $params
);

$usuarios = Database::fetchAll(
    "SELECT id, CONCAT(nombres,' ',apellidos) AS nombre FROM usuarios ORDER BY nombres"
);

$acciones_unicas = Database::fetchAll(
    "SELECT DISTINCT accion FROM audit_log ORDER BY accion"
);

$total_pags = max(1,ceil($total/$por_pagina));

// Colores por tipo de acción
function color_accion(string $accion): string {
    if (str_contains($accion,'crear') || str_contains($accion,'subir'))   return 'success';
    if (str_contains($accion,'editar') || str_contains($accion,'config')) return 'primary';
    if (str_contains($accion,'eliminar') || str_contains($accion,'revocar')) return 'danger';
    if (str_contains($accion,'login') || str_contains($accion,'logout'))  return 'secondary';
    if (str_contains($accion,'gestionar') || str_contains($accion,'cancelar')) return 'warning';
    return 'info';
}

layout_header('Auditoría del Sistema');
?>

<!-- Filtros -->
<div class="pab-card p-3 mb-4">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-2">
            <label class="form-label small fw-semibold">Desde</label>
            <input type="date" name="desde" class="form-control form-control-sm" value="<?= $desde ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label small fw-semibold">Hasta</label>
            <input type="date" name="hasta" class="form-control form-control-sm" value="<?= $hasta ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-semibold">Usuario</label>
            <select name="id_usuario" class="form-select form-select-sm">
                <option value="">Todos los usuarios</option>
                <?php foreach ($usuarios as $u): ?>
                <option value="<?= $u['id'] ?>" <?= $filtro_usuario==$u['id']?'selected':'' ?>>
                    <?= sanitizar($u['nombre']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-semibold">Acción</label>
            <select name="accion" class="form-select form-select-sm">
                <option value="">Todas las acciones</option>
                <?php foreach ($acciones_unicas as $a): ?>
                <option value="<?= sanitizar($a['accion']) ?>" <?= $filtro_accion===$a['accion']?'selected':'' ?>>
                    <?= sanitizar($a['accion']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2 d-flex gap-1">
            <button type="submit" class="btn btn-primary btn-sm flex-grow-1">
                <i class="bi bi-funnel me-1"></i>Filtrar
            </button>
            <a href="?" class="btn btn-outline-secondary btn-sm" title="Limpiar filtros">
                <i class="bi bi-x-circle"></i>
            </a>
        </div>
    </form>
</div>

<!-- Resumen -->
<div class="d-flex align-items-center justify-content-between mb-3">
    <div class="text-muted small">
        <i class="bi bi-list-ul me-1"></i>
        <strong><?= number_format($total) ?></strong> registros encontrados
        — Página <?= $pagina ?> de <?= $total_pags ?>
    </div>
    <div class="d-flex gap-2">
        <!-- Exportar CSV -->
        <a href="?desde=<?= $desde ?>&hasta=<?= $hasta ?>&id_usuario=<?= $filtro_usuario ?>&accion=<?= urlencode($filtro_accion) ?>&export=csv"
           class="btn btn-sm btn-outline-success" title="Exportar a CSV">
            <i class="bi bi-filetype-csv me-1"></i> CSV
        </a>
    </div>
</div>

<?php
// Exportar CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="auditoria_'.date('Ymd').'.csv"');
    $out = fopen('php://output','w');
    // BOM para Excel
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out,['Fecha','Usuario','Rol','Acción','Tabla','ID Registro','IP'], ';');
    foreach ($logs as $l) {
        fputcsv($out,[
            $l['created_at'],
            $l['usuario_nombre'] ?? 'Sistema',
            $l['rol_nombre'] ?? '—',
            $l['accion'],
            $l['tabla'] ?? '—',
            $l['registro_id'] ?? '—',
            $l['ip'] ?? '—',
        ], ';');
    }
    // Exportar todos sin paginación
    $todos = Database::fetchAll(
        "SELECT al.*, CONCAT(u.nombres,' ',u.apellidos) AS usuario_nombre,
                r.nombre AS rol_nombre
         FROM audit_log al
         LEFT JOIN usuarios u ON u.id=al.id_usuario
         LEFT JOIN roles r ON r.id=u.id_rol
         $where ORDER BY al.created_at DESC", $params
    );
    foreach (array_slice($todos, $por_pagina) as $l) {
        fputcsv($out,[
            $l['created_at'],
            $l['usuario_nombre'] ?? 'Sistema',
            $l['rol_nombre'] ?? '—',
            $l['accion'],
            $l['tabla'] ?? '—',
            $l['registro_id'] ?? '—',
            $l['ip'] ?? '—',
        ], ';');
    }
    fclose($out);
    exit;
}
?>

<!-- Tabla de logs -->
<div class="pab-card">
    <div class="table-responsive">
        <table class="table table-pab table-hover align-middle mb-0" style="font-size:.83rem">
            <thead>
                <tr>
                    <th style="width:150px">Fecha y hora</th>
                    <th>Usuario</th>
                    <th style="width:100px">Rol</th>
                    <th>Acción</th>
                    <th>Tabla</th>
                    <th style="width:80px">Registro</th>
                    <th style="width:120px">IP</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($logs as $l):
                $color = color_accion($l['accion']);
            ?>
            <tr>
                <td class="text-muted small" style="white-space:nowrap">
                    <?= date('d/m/Y', strtotime($l['created_at'])) ?>
                    <br><span style="font-size:.72rem"><?= date('H:i:s', strtotime($l['created_at'])) ?></span>
                </td>
                <td>
                    <span class="fw-semibold"><?= sanitizar($l['usuario_nombre'] ?? 'Sistema') ?></span>
                </td>
                <td>
                    <span class="badge bg-<?= match($l['id_rol'] ?? 0) {
                        1=>'danger',2=>'primary',3=>'success',default=>'secondary'
                    } ?>" style="font-size:.65rem">
                        <?= sanitizar($l['rol_nombre'] ?? '—') ?>
                    </span>
                </td>
                <td>
                    <span class="badge bg-<?= $color ?> bg-opacity-15 text-<?= $color ?>"
                          style="font-size:.75rem;font-weight:600">
                        <?= sanitizar($l['accion']) ?>
                    </span>
                    <?php if ($l['detalle']): ?>
                    <span class="text-muted small ms-1"><?= sanitizar(mb_substr($l['detalle'],0,60)) ?></span>
                    <?php endif; ?>
                </td>
                <td class="text-muted small"><?= sanitizar($l['tabla'] ?? '—') ?></td>
                <td class="text-muted small text-center"><?= $l['registro_id'] ?? '—' ?></td>
                <td class="text-muted small font-monospace"><?= sanitizar($l['ip'] ?? '—') ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$logs): ?>
            <tr><td colspan="7" class="text-center text-muted py-5">
                <i class="bi bi-search fs-2 d-block mb-2 opacity-25"></i>
                No hay registros para los filtros seleccionados.
            </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Paginación -->
<?php if ($total_pags > 1): ?>
<nav class="mt-3 d-flex justify-content-center">
    <ul class="pagination pagination-sm mb-0">
        <?php if ($pagina > 1): ?>
        <li class="page-item">
            <a class="page-link" href="?desde=<?= $desde ?>&hasta=<?= $hasta ?>&id_usuario=<?= $filtro_usuario ?>&accion=<?= urlencode($filtro_accion) ?>&pag=<?= $pagina-1 ?>">
                <i class="bi bi-chevron-left"></i>
            </a>
        </li>
        <?php endif; ?>

        <?php
        $inicio_pag = max(1,$pagina-3);
        $fin_pag    = min($total_pags,$pagina+3);
        for ($p=$inicio_pag; $p<=$fin_pag; $p++): ?>
        <li class="page-item <?= $p==$pagina?'active':'' ?>">
            <a class="page-link" href="?desde=<?= $desde ?>&hasta=<?= $hasta ?>&id_usuario=<?= $filtro_usuario ?>&accion=<?= urlencode($filtro_accion) ?>&pag=<?= $p ?>">
                <?= $p ?>
            </a>
        </li>
        <?php endfor; ?>

        <?php if ($pagina < $total_pags): ?>
        <li class="page-item">
            <a class="page-link" href="?desde=<?= $desde ?>&hasta=<?= $hasta ?>&id_usuario=<?= $filtro_usuario ?>&accion=<?= urlencode($filtro_accion) ?>&pag=<?= $pagina+1 ?>">
                <i class="bi bi-chevron-right"></i>
            </a>
        </li>
        <?php endif; ?>
    </ul>
</nav>
<?php endif; ?>

<?php layout_footer(); ?>
