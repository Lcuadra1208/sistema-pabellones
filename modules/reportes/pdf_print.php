<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Reporte de Pabellones</title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'Segoe UI',Arial,sans-serif; font-size:11px; color:#1e293b; background:#fff; }

/* Cabecera */
.header { display:flex; align-items:center; justify-content:space-between;
          border-bottom:3px solid #1a6fa3; padding-bottom:12px; margin-bottom:20px; }
.header-logo img { max-height:55px; max-width:180px; object-fit:contain; }
.header-logo .sin-logo { font-size:1.1rem; font-weight:700; color:#1a6fa3; }
.header-info { text-align:right; }
.header-info h1 { font-size:1rem; font-weight:700; color:#1a6fa3; }
.header-info p  { font-size:.78rem; color:#64748b; margin-top:2px; }

/* KPIs */
.kpis { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:20px; }
.kpi  { background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:10px; text-align:center; }
.kpi .val { font-size:1.6rem; font-weight:700; color:#1a6fa3; }
.kpi .lbl { font-size:.72rem; color:#64748b; margin-top:2px; }

/* Secciones */
.section { margin-bottom:22px; page-break-inside:avoid; }
.section h2 { font-size:.88rem; font-weight:700; color:#1a6fa3; border-bottom:1px solid #e2e8f0;
              padding-bottom:5px; margin-bottom:8px; }

/* Tablas */
table { width:100%; border-collapse:collapse; font-size:.82rem; }
thead th { background:#1a6fa3; color:#fff; padding:6px 8px; text-align:left; font-weight:600; }
tbody tr:nth-child(even) { background:#f8fafc; }
tbody td { padding:5px 8px; border-bottom:1px solid #f1f5f9; }
tfoot td { background:#dbeafe; font-weight:700; padding:6px 8px; }

/* Barra de progreso */
.bar-wrap { background:#e2e8f0; border-radius:4px; height:8px; min-width:60px; }
.bar-fill  { background:#1a6fa3; border-radius:4px; height:8px; }

/* Footer */
.footer { position:fixed; bottom:0; left:0; right:0; border-top:1px solid #e2e8f0;
          padding:6px 20px; font-size:.7rem; color:#94a3b8;
          display:flex; justify-content:space-between; background:#fff; }

/* Impresión */
@media print {
    .no-print { display:none !important; }
    body { font-size:10px; }
    .header { page-break-after:avoid; }
}

/* Botón imprimir */
.print-btn { position:fixed; top:16px; right:16px; z-index:999;
             background:#1a6fa3; color:#fff; border:none; border-radius:6px;
             padding:8px 16px; font-size:.85rem; cursor:pointer; display:flex;
             align-items:center; gap:6px; box-shadow:0 2px 8px rgba(0,0,0,.2); }
.print-btn:hover { background:#1558a0; }
</style>
</head>
<body>

<button class="print-btn no-print" onclick="window.print()">
    🖨️ Imprimir / Guardar PDF
</button>

<?php
// Obtener logo de la institución
$logo_html = '';
if ($filtro_inst) {
    $inst_row = Database::fetchOne("SELECT logo FROM instituciones WHERE id=?",[$filtro_inst]);
    if ($inst_row && $inst_row['logo']) {
        $logo_html = '<img src="'.APP_URL.'/'.$inst_row['logo'].'" alt="Logo">';
    }
}
$periodo = date('d/m/Y', strtotime($desde)).' al '.date('d/m/Y', strtotime($hasta));
$generado = date('d/m/Y H:i');
$dias_rep = max(1,(strtotime($hasta)-strtotime($desde))/86400+1);
$horas_disp = $dias_rep * 14;
$total_fact = array_sum(array_column($por_cirugia,'facturacion_est'));
?>

<div class="header">
    <div class="header-logo">
        <?php if ($logo_html): ?>
            <?= $logo_html ?>
        <?php else: ?>
            <div class="sin-logo">🏥 <?= htmlspecialchars($inst_nombre) ?></div>
        <?php endif; ?>
    </div>
    <div class="header-info">
        <h1>Reporte de Pabellones Quirúrgicos</h1>
        <p><?= htmlspecialchars($inst_nombre) ?></p>
        <p>Período: <?= $periodo ?></p>
        <p>Generado: <?= $generado ?></p>
    </div>
</div>

<!-- KPIs -->
<div class="kpis">
    <div class="kpi"><div class="val"><?= $kpis['total'] ?></div><div class="lbl">Total solicitudes</div></div>
    <div class="kpi"><div class="val" style="color:#16a34a"><?= $kpis['aprobadas'] ?></div><div class="lbl">Aprobadas</div></div>
    <div class="kpi"><div class="val" style="color:#f59e0b"><?= $kpis['pendientes'] ?></div><div class="lbl">Pendientes</div></div>
    <div class="kpi"><div class="val" style="color:#dc2626"><?= $kpis['rechazadas'] ?></div><div class="lbl">Rechazadas</div></div>
</div>

<!-- Ocupación por pabellón -->
<div class="section">
    <h2>Ocupación por Pabellón</h2>
    <table>
        <thead><tr><th>Pabellón</th><th>Operaciones</th><th>Horas de uso</th><th>% Utilización</th></tr></thead>
        <tbody>
        <?php foreach ($ocupacion as $r):
            $horas = round($r['minutos_totales']/60,1);
            $pct   = min(100,round($horas/$horas_disp*100,1));
        ?>
        <tr>
            <td><?= htmlspecialchars($r['pabellon']) ?></td>
            <td><?= $r['total_ops'] ?></td>
            <td><?= $horas ?> h</td>
            <td>
                <div style="display:flex;align-items:center;gap:6px">
                    <div class="bar-wrap"><div class="bar-fill" style="width:<?= $pct ?>%"></div></div>
                    <span><?= $pct ?>%</span>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$ocupacion): ?><tr><td colspan="4" style="color:#94a3b8;text-align:center">Sin datos en el período</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Por médico -->
<div class="section">
    <h2>Actividad por Médico</h2>
    <table>
        <thead><tr><th>Médico</th><th>Especialidad</th><th>Operaciones</th><th>Horas quirúrgicas</th><th>Pendientes</th></tr></thead>
        <tbody>
        <?php foreach ($por_medico as $r): ?>
        <tr>
            <td><?= htmlspecialchars($r['medico']) ?></td>
            <td><?= htmlspecialchars($r['especialidad'] ?? '—') ?></td>
            <td><?= $r['total_ops'] ?></td>
            <td><?= round($r['minutos_totales']/60,1) ?> h</td>
            <td><?= $r['pendientes'] > 0 ? '<strong style="color:#f59e0b">'.$r['pendientes'].'</strong>' : '—' ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$por_medico): ?><tr><td colspan="5" style="color:#94a3b8;text-align:center">Sin datos</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Por cirugía -->
<div class="section">
    <h2>Por Tipo de Cirugía</h2>
    <table>
        <thead><tr><th>Cirugía</th><th>Operaciones</th><th>Duración promedio</th><th>Facturación estimada</th></tr></thead>
        <tbody>
        <?php foreach ($por_cirugia as $r): ?>
        <tr>
            <td><?= htmlspecialchars($r['cirugia']) ?></td>
            <td><?= $r['total'] ?></td>
            <td><?= round($r['duracion_prom']) ?> min</td>
            <td>$ <?= number_format($r['facturacion_est'],0,',','.') ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$por_cirugia): ?><tr><td colspan="4" style="color:#94a3b8;text-align:center">Sin datos</td></tr><?php endif; ?>
        </tbody>
        <?php if ($total_fact > 0): ?>
        <tfoot>
            <tr><td><strong>TOTAL ESTIMADO</strong></td><td></td><td></td>
            <td><strong>$ <?= number_format($total_fact,0,',','.') ?></strong></td></tr>
        </tfoot>
        <?php endif; ?>
    </table>
</div>

<div class="footer">
    <span><?= htmlspecialchars($inst_nombre) ?> — SistemaPabellones v<?= APP_VERSION ?></span>
    <span>Generado el <?= $generado ?></span>
</div>

</body>
</html>
