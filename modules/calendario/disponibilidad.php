<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/layout.php';
requiere_login();

$usuario   = usuario_actual();
$id_medico = (int)$usuario['id'];
$id_inst   = usuario_institucion();

// Mes y año actual
$anio = (int)($_GET['anio'] ?? date('Y'));
$mes  = (int)($_GET['mes']  ?? date('n'));
// Navegación
if ($mes < 1)  { $mes = 12; $anio--; }
if ($mes > 12) { $mes = 1;  $anio++; }

$primer_dia  = mktime(0,0,0,$mes,1,$anio);
$ultimo_dia  = mktime(0,0,0,$mes+1,0,$anio);
$dias_mes    = (int)date('t',$primer_dia);
$dow_inicio  = (int)date('N',$primer_dia); // 1=Lun, 7=Dom

$desde_str   = date('Y-m-d',$primer_dia);
$hasta_str   = date('Y-m-d',$ultimo_dia);
$hoy         = date('Y-m-d');

// Instituciones donde el médico tiene acceso
$inst_cond = "AND p.id_institucion IN (
    SELECT id_institucion FROM acreditaciones WHERE id_medico=$id_medico AND activo=1
    UNION
    SELECT id_institucion FROM usuarios WHERE id=$id_medico AND id_institucion IS NOT NULL
)";

// Obtener días con disponibilidad programada en este mes
$disponibles_raw = Database::fetchAll(
    "SELECT DISTINCT ppd.dia_semana, per.fecha_inicio, per.fecha_fin,
            p.nombre AS pabellon, p.id AS id_pabellon,
            i.nombre AS inst_nombre, i.id AS id_inst,
            tp.color_hex,
            ppd.hora_inicio, ppd.hora_fin
     FROM periodo_pabellon_detalle ppd
     JOIN periodos_pabellon per ON per.id = ppd.id_periodo
     JOIN pabellones p ON p.id = ppd.id_pabellon
     JOIN tipos_pabellon tp ON tp.id = p.id_tipo_pabellon
     JOIN instituciones i ON i.id = p.id_institucion
     WHERE per.estado = 'publicado'
       AND per.fecha_inicio <= ? AND per.fecha_fin >= ?
       AND ppd.activo = 1
       $inst_cond",
    [$hasta_str, $desde_str]
);

// Ofertas disponibles este mes
$ofertas_raw = Database::fetchAll(
    "SELECT o.fecha_oferta, o.hora_inicio, o.hora_fin,
            p.nombre AS pabellon, p.id AS id_pabellon,
            i.nombre AS inst_nombre, i.id AS id_inst,
            tp.color_hex,
            o.precio_oferta, o.precio_normal, o.descuento_pct
     FROM ofertas_pabellon o
     JOIN pabellones p ON p.id = o.id_pabellon
     JOIN tipos_pabellon tp ON tp.id = p.id_tipo_pabellon
     JOIN instituciones i ON i.id = p.id_institucion
     WHERE o.estado = 'disponible'
       AND o.fecha_oferta BETWEEN ? AND ?
       $inst_cond",
    [$desde_str, $hasta_str]
);

// Indexar disponibilidad por fecha
$disp_por_fecha  = [];
$oferta_por_fecha = [];

// Disponibilidad programada: expandir por fechas del mes
for ($d = 1; $d <= $dias_mes; $d++) {
    $fecha_ts  = mktime(0,0,0,$mes,$d,$anio);
    $fecha_str = date('Y-m-d',$fecha_ts);
    $dow       = (int)date('N',$fecha_ts); // 1=Lun...7=Dom

    foreach ($disponibles_raw as $disp) {
        if ($dow === (int)$disp['dia_semana']
            && $fecha_str >= $disp['fecha_inicio']
            && $fecha_str <= $disp['fecha_fin']) {
            $disp_por_fecha[$fecha_str][] = $disp;
        }
    }
}

// Ofertas por fecha
foreach ($ofertas_raw as $of) {
    $oferta_por_fecha[$of['fecha_oferta']][] = $of;
}

// Mis solicitudes este mes (para mostrar en el día)
$mis_solicitudes = Database::fetchAll(
    "SELECT s.fecha_solicitada, s.hora_inicio, s.hora_fin, s.estado,
            p.nombre AS pabellon, p.id AS id_pabellon,
            tc.nombre AS cirugia
     FROM solicitudes_pabellon s
     JOIN pabellones p ON p.id = s.id_pabellon
     JOIN tipos_cirugia tc ON tc.id = s.id_tipo_cirugia
     WHERE s.id_medico=? AND s.fecha_solicitada BETWEEN ? AND ?
       AND s.estado IN ('pendiente','aprobada')",
    [$id_medico, $desde_str, $hasta_str]
);
$dias_con_solicitud = [];
$solicitudes_por_fecha = [];
foreach ($mis_solicitudes as $sol) {
    $dias_con_solicitud[$sol['fecha_solicitada']] = true;
    $solicitudes_por_fecha[$sol['fecha_solicitada']][] = $sol;
}

$nombre_mes = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio',
               'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];

layout_header('Disponibilidad '.$nombre_mes[$mes].' '.$anio);
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <div class="d-flex align-items-center gap-2">
        <a href="?mes=<?= $mes-1 ?>&anio=<?= $anio ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-chevron-left"></i>
        </a>
        <h5 class="mb-0 fw-bold"><?= $nombre_mes[$mes] ?> <?= $anio ?></h5>
        <a href="?mes=<?= $mes+1 ?>&anio=<?= $anio ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-chevron-right"></i>
        </a>
        <a href="?mes=<?= date('n') ?>&anio=<?= date('Y') ?>"
           class="btn btn-outline-primary btn-sm ms-2">Hoy</a>
    </div>
    <div class="d-flex gap-3 align-items-center small">
        <span class="d-flex align-items-center gap-1">
            <span style="width:12px;height:12px;border-radius:3px;background:#bbf7d0;border:1px solid #059669;display:inline-block"></span>
            Disponible
        </span>
        <span class="d-flex align-items-center gap-1">
            <span style="width:12px;height:12px;border-radius:3px;background:#fef9c3;border:1px solid #ca8a04;display:inline-block"></span>
            Oferta especial
        </span>
        <span class="d-flex align-items-center gap-1">
            <span style="width:12px;height:12px;border-radius:3px;background:#dbeafe;border:1px solid #3b82f6;display:inline-block"></span>
            Con solicitud
        </span>
        <a href="<?= APP_URL ?>/modules/calendario/index.php" class="btn btn-primary btn-sm ms-2">
            <i class="bi bi-calendar-week me-1"></i> Vista semanal
        </a>
    </div>
</div>

<!-- Calendario mensual -->
<div class="pab-card p-3">
    <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:1px;background:#e2e8f0;border-radius:6px;overflow:hidden">
        <!-- Encabezados días -->
        <?php foreach (['Lun','Mar','Mié','Jue','Vie','Sáb','Dom'] as $dn): ?>
        <div style="background:#f8fafc;padding:8px;text-align:center;font-size:.78rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em">
            <?= $dn ?>
        </div>
        <?php endforeach; ?>

        <!-- Días vacíos al inicio -->
        <?php for ($v = 1; $v < $dow_inicio; $v++): ?>
        <div style="background:#f8fafc;min-height:90px;padding:6px"></div>
        <?php endfor; ?>

        <!-- Días del mes -->
        <?php for ($d = 1; $d <= $dias_mes; $d++):
            $fecha_str = date('Y-m-d', mktime(0,0,0,$mes,$d,$anio));
            $es_hoy    = ($fecha_str === $hoy);
            $pasado    = ($fecha_str < $hoy);
            $tiene_disp   = !empty($disp_por_fecha[$fecha_str]);
            $tiene_oferta = !empty($oferta_por_fecha[$fecha_str]);
            $tiene_sol    = isset($dias_con_solicitud[$fecha_str]);

            // Color del fondo del día
            if ($tiene_oferta)     $bg = '#fef3c7'; // ámbar suave
            elseif ($tiene_disp)   $bg = '#bbf7d0'; // verde-esmeralda visible
            elseif ($tiene_sol)    $bg = '#dbeafe'; // azul = con solicitud
            elseif ($pasado)       $bg = '#f8fafc'; // gris = pasado
            else                   $bg = '#fff';    // blanco = sin info
        ?>
        <div style="background:<?= $bg ?>;min-height:90px;padding:6px;cursor:<?= ($tiene_disp||$tiene_oferta||$tiene_sol)?'pointer':'default' ?>;position:relative;transition:filter .15s"
             <?php if ($tiene_disp || $tiene_oferta || $tiene_sol): ?>
             onclick="abrirDia('<?= $fecha_str ?>',<?= htmlspecialchars(json_encode([
                 'disp'   => $disp_por_fecha[$fecha_str]   ?? [],
                 'ofertas'=> $oferta_por_fecha[$fecha_str] ?? [],
                 'solicitudes' => $solicitudes_por_fecha[$fecha_str] ?? [],
             ]),ENT_QUOTES) ?>)"
             onmouseover="this.style.filter='brightness(.96)'"
             onmouseout="this.style.filter=''"
             <?php endif; ?>
        >
            <!-- Número del día -->
            <div style="font-size:.85rem;font-weight:<?= $es_hoy?'700':'500' ?>;
                        color:<?= $es_hoy?'#1a6fa3':($pasado?'#94a3b8':'#1e293b') ?>;
                        <?= $es_hoy?'background:#1a6fa3;color:#fff;width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;':'' ?>">
                <?= $d ?>
            </div>

            <!-- Indicadores -->
            <div style="margin-top:4px">
                <?php if ($tiene_oferta): ?>
                <div style="font-size:.65rem;background:#ca8a04;color:#fff;border-radius:3px;padding:1px 4px;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                    ⭐ <?= count($oferta_por_fecha[$fecha_str]) ?> oferta<?= count($oferta_por_fecha[$fecha_str])>1?'s':'' ?>
                </div>
                <?php endif; ?>
                <?php if ($tiene_disp): ?>
                <?php
                // Agrupar por pabellón para no repetir
                $pabs = array_unique(array_column($disp_por_fecha[$fecha_str],'pabellon'));
                foreach (array_slice($pabs,0,2) as $pn):
                ?>
                <div style="font-size:.63rem;color:#14532d;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                    ✓ <?= htmlspecialchars($pn) ?>
                </div>
                <?php endforeach;
                if (count($pabs) > 2): ?>
                <div style="font-size:.62rem;color:#16a34a">+<?= count($pabs)-2 ?> más...</div>
                <?php endif; ?>
                <?php endif; ?>
                <?php if ($tiene_sol): ?>
                <div style="font-size:.63rem;color:#1d4ed8">📋 Solicitud</div>
                <?php endif; ?>
            </div>
        </div>
        <?php endfor; ?>

        <!-- Días vacíos al final -->
        <?php
        $dow_fin = (int)date('N', mktime(0,0,0,$mes,$dias_mes,$anio));
        for ($v = $dow_fin; $v < 7; $v++): ?>
        <div style="background:#f8fafc;min-height:90px;padding:6px"></div>
        <?php endfor; ?>
    </div>
</div>

<!-- Modal detalle del día -->
<div class="modal fade" id="modalDia" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
        <div class="modal-header">
            <h5 class="modal-title" id="md_titulo">Disponibilidad del día</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body" id="md_body"></div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
        </div>
    </div>
  </div>
</div>

<script>
function abrirDia(fecha, datos) {
    const [anio,mes,dia] = fecha.split('-');
    const meses = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio',
                   'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    document.getElementById('md_titulo').textContent =
        'Disponibilidad — ' + parseInt(dia) + ' de ' + meses[parseInt(mes)] + ' ' + anio;

    let html = '';

    // Ofertas especiales primero
    if (datos.ofertas.length > 0) {
        html += '<h6 class="fw-bold text-warning mb-2"><i class="bi bi-star-fill me-1"></i>Ofertas especiales</h6>';
        datos.ofertas.forEach(of => {
            const hi = of.hora_inicio.substring(0,5);
            const hf = of.hora_fin.substring(0,5);
            let precio_html = '';
            if (of.precio_oferta) {
                precio_html = `<span class="text-decoration-line-through text-muted small">$${parseInt(of.precio_normal).toLocaleString('es-CL')}</span>
                               <span class="fw-bold text-success ms-1">$${parseInt(of.precio_oferta).toLocaleString('es-CL')}</span>
                               <span class="badge bg-warning text-dark ms-1">-${parseFloat(of.descuento_pct).toFixed(0)}%</span>`;
            }
            html += `<div class="d-flex align-items-center gap-3 p-2 mb-2 rounded" style="background:#fef9c3;border:1px solid #ca8a04">
                       <div>
                           <div class="fw-semibold">${of.pabellon}</div>
                           <div class="small text-muted">${of.inst_nombre}</div>
                           <div class="small"><i class="bi bi-clock me-1"></i>${hi} – ${hf}</div>
                           <div class="mt-1">${precio_html}</div>
                       </div>
                       <div class="ms-auto">
                           <a href="<?= APP_URL ?>/modules/ofertas/index.php?id_oferta=${of.id_pabellon}&fecha=${fecha}"
                              class="btn btn-sm btn-warning">Reservar</a>
                       </div>
                     </div>`;
        });
    }

    // Disponibilidad programada
    if (datos.disp.length > 0) {
        html += '<h6 class="fw-bold text-success mb-2 mt-3"><i class="bi bi-calendar-check me-1"></i>Horarios disponibles</h6>';
        // Agrupar por pabellón
        const pabs = {};
        datos.disp.forEach(d => {
            if (!pabs[d.id_pabellon]) pabs[d.id_pabellon] = d;
        });
        Object.values(pabs).forEach(disp => {
            const hi = disp.hora_inicio.substring(0,5);
            const hf = disp.hora_fin.substring(0,5);
            html += `<div class="d-flex align-items-center gap-3 p-2 mb-2 rounded" style="background:#bbf7d0;border:1px solid #059669">
                       <div style="width:12px;height:40px;border-radius:3px;background:${disp.color_hex};flex-shrink:0"></div>
                       <div class="flex-grow-1">
                           <div class="fw-semibold">${disp.pabellon}</div>
                           <div class="small text-muted">${disp.inst_nombre}</div>
                           <div class="small"><i class="bi bi-clock me-1"></i>${hi} – ${hf}</div>
                       </div>
                       <a href="<?= APP_URL ?>/modules/solicitudes/index.php?accion=nueva&fecha=${fecha}&hora_inicio=${hi}&hora_fin=${hf}&id_inst=${disp.id_inst}&id_pabellon=${disp.id_pabellon}"
                          class="btn btn-sm btn-success">
                           <i class="bi bi-clipboard-plus me-1"></i>Solicitar
                       </a>
                     </div>`;
        });
    }

    // Solicitudes existentes del médico en este día
    if (datos.solicitudes && datos.solicitudes.length > 0) {
        html += '<h6 class="fw-bold text-primary mb-2 mt-3"><i class="bi bi-clipboard-check me-1"></i>Tus operaciones agendadas</h6>';
        datos.solicitudes.forEach(sol => {
            const hi = sol.hora_inicio.substring(0,5);
            const hf = sol.hora_fin.substring(0,5);
            const badge = sol.estado === 'aprobada'
                ? '<span class="badge bg-success">Aprobada</span>'
                : '<span class="badge bg-warning text-dark">Pendiente</span>';
            html += `<div class="d-flex align-items-center gap-3 p-2 mb-2 rounded" style="background:#dbeafe;border:1px solid #3b82f6">
                       <i class="bi bi-clock-fill text-primary fs-5"></i>
                       <div class="flex-grow-1">
                           <div class="fw-semibold">${sol.pabellon}</div>
                           <div class="small text-muted">${sol.cirugia}</div>
                           <div class="small"><i class="bi bi-clock me-1"></i>${hi} – ${hf}</div>
                       </div>
                       <div>${badge}</div>
                     </div>`;
        });
    }

    if (!html) html = '<p class="text-muted text-center py-3">Sin disponibilidad en este día.</p>';
    document.getElementById('md_body').innerHTML = html;
    new bootstrap.Modal(document.getElementById('modalDia')).show();
}
</script>

<?php layout_footer(); ?>
