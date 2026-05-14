<?php
/**
 * layout.php — funciones de layout reutilizables
 * Uso: layout_header('Título página'); ... contenido ... layout_footer();
 */

function layout_header(string $titulo_pagina, string $icon = 'bi-house'): void
{
    $usuario  = usuario_actual();
    $nombre   = $usuario ? sanitizar($usuario['nombres'] . ' ' . $usuario['apellidos']) : '';
    $rol_txt  = match((int)($usuario['id_rol'] ?? 0)) {
        ROL_SUPERADMIN     => 'Super Admin',
        ROL_ADMIN_HOSPITAL => 'Administrador',
        ROL_MEDICO         => 'Médico',
        ROL_MANTENCION     => 'Mantención',
        default            => '',
    };
    // Cargar logo e info de la institución del usuario logueado
    $inst_logo     = '';
    $inst_nombre   = $usuario['institucion_nombre'] ?? 'Sistema global';
    $id_inst_layout = $usuario['id_institucion'] ?? null;
    if ($id_inst_layout) {
        $inst_row = Database::fetchOne(
            "SELECT nombre, logo FROM instituciones WHERE id=?", [$id_inst_layout]
        );
        if ($inst_row) {
            $inst_nombre = $inst_row['nombre'];
            $inst_logo   = $inst_row['logo'] ? APP_URL.'/'.$inst_row['logo'] : '';
        }
    }
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= sanitizar($titulo_pagina) ?> — <?= APP_NAME ?></title>
    <!-- Bootstrap 5 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- FullCalendar -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css">
    <!-- App CSS -->
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css">
</head>
<body>

<!-- ══ SIDEBAR ══════════════════════════════════════════ -->
<nav id="sidebar">
    <div class="brand" style="<?= $inst_logo ? 'padding:8px 12px;background:#fff;justify-content:center' : '' ?>">
        <?php if ($inst_logo): ?>
            <img src="<?= $inst_logo ?>" alt="<?= sanitizar($inst_nombre) ?>"
                 style="max-height:44px;max-width:180px;object-fit:contain"
                 title="<?= sanitizar($inst_nombre) ?>">
        <?php else: ?>
            <i class="bi bi-hospital"></i>
            <span><?= sanitizar($inst_nombre !== 'Sistema global' ? $inst_nombre : APP_NAME) ?></span>
        <?php endif; ?>
    </div>
    <ul class="nav flex-column mt-2">

        <li class="nav-item">
            <a href="<?= APP_URL ?>/dashboard.php"
               class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'active' : '' ?>">
                <i class="bi bi-speedometer2"></i> Dashboard
            </a>
        </li>

        <?php
        // ── Función helper: ítem activo ──────────────────────────
        function nav_activo(string $fragmento): string {
            return strpos($_SERVER['PHP_SELF'], $fragmento) !== false ? 'active' : '';
        }
        $base = APP_URL;
        ?>

        <?php
        // ── Verificar estado de configuración (solo admin) ──────
        $config_ok = true;
        $config_pendientes = [];
        if (es_admin()) {
            $inst_id = usuario_institucion();
            $n_tc  = Database::fetchOne("SELECT COUNT(*) n FROM tipos_cirugia  WHERE id_institucion=? AND activo=1",[$inst_id])['n'] ?? 0;
            $n_ins = Database::fetchOne("SELECT COUNT(*) n FROM insumos         WHERE id_institucion=? AND activo=1",[$inst_id])['n'] ?? 0;
            $n_pr  = (function($id) { try { return Database::fetchOne("SELECT COUNT(*) n FROM precios_tipo_pabellon WHERE id_institucion=? AND (vigente_hasta IS NULL OR vigente_hasta >= CURDATE())",[$id]); } catch(\Exception $e) { return ["n"=>0]; } })($inst_id)['n'] ?? 0;
            $n_ac  = Database::fetchOne("SELECT COUNT(*) n FROM acreditaciones  WHERE id_institucion=? AND activo=1",[$inst_id])['n'] ?? 0;
            $n_pab = Database::fetchOne("SELECT COUNT(*) n FROM pabellones       WHERE id_institucion=? AND activo=1",[$inst_id])['n'] ?? 0;
            if (!$n_tc)  $config_pendientes[] = ['Tipos de Cirugía','tipos_cirugia.php'];
            if (!$n_ins) $config_pendientes[] = ['Insumos','insumos.php'];
            if (!$n_pr)  $config_pendientes[] = ['Precios','precios.php'];
            if (!$n_ac)  $config_pendientes[] = ['Acreditaciones','acreditaciones.php'];
            if (!$n_pab) $config_pendientes[] = ['Pabellones','pabellones.php'];
            $config_ok = empty($config_pendientes);
        }
        ?>

        <?php if (es_admin() && !$config_ok): ?>
        <li class="px-2 mb-1">
            <div class="rounded-2 p-2" style="background:rgba(245,158,11,.15);border:1px solid rgba(245,158,11,.4)">
                <div class="small fw-semibold" style="color:#f59e0b">
                    <i class="bi bi-exclamation-triangle me-1"></i>Config. incompleta
                </div>
                <?php foreach ($config_pendientes as $cp): ?>
                <a href="<?= $base ?>/modules/mantenedores/<?= $cp[1] ?>"
                   class="d-block small mt-1" style="color:#fbbf24">
                    <i class="bi bi-arrow-right-circle me-1"></i><?= $cp[0] ?>
                </a>
                <?php endforeach; ?>
            </div>
        </li>
        <?php endif; ?>

        <!-- ══ SECCIÓN 1: PABELLONES ═════════════════════════════ -->
        <li><span class="nav-section">Pabellones</span></li>

        <li class="nav-item">
            <a href="<?= $base ?>/modules/calendario/index.php"
               class="nav-link <?= nav_activo('calendario') ?>">
                <i class="bi bi-calendar-week"></i> Calendario
            </a>
        </li>
        <?php if (es_mantencion() || es_admin() || es_superadmin()): ?>
        <li class="nav-item">
            <a href="<?= $base ?>/modules/mantencion/index.php"
               class="nav-link <?= nav_activo('mantencion') ?>">
                <i class="bi bi-stars"></i> Mantención
            </a>
        </li>
        <?php endif; ?>
        <li class="nav-item">
            <a href="<?= $base ?>/modules/solicitudes/index.php"
               class="nav-link <?= nav_activo('solicitudes') ?>">
                <i class="bi bi-clipboard-plus"></i>
                <?= es_medico() ? 'Mis Solicitudes' : 'Solicitudes' ?>
            </a>
        </li>
        <?php if (es_medico()): ?>
        <li class="nav-item">
            <a href="<?= $base ?>/modules/calendario/disponibilidad.php"
               class="nav-link <?= nav_activo('disponibilidad') ?>">
                <i class="bi bi-calendar-month"></i> Disponibilidad
            </a>
        </li>
        <?php endif; ?>

        <?php if (es_admin() || es_superadmin()): ?>
        <li class="nav-item">
            <a href="<?= $base ?>/modules/programacion/index.php"
               class="nav-link <?= nav_activo('programacion') ?>">
                <i class="bi bi-calendar2-range"></i> Programación
            </a>
        </li>
        <li class="nav-item">
            <a href="<?= $base ?>/modules/ofertas/index.php"
               class="nav-link <?= nav_activo('ofertas') ?>">
                <i class="bi bi-megaphone"></i> Ofertas
            </a>
        </li>
        <li class="nav-item">
            <a href="<?= $base ?>/modules/solicitudes/bloqueos.php"
               class="nav-link <?= nav_activo('bloqueos') ?>">
                <i class="bi bi-lock"></i> Bloqueos
            </a>
        </li>
        <li class="nav-item">
            <a href="<?= $base ?>/modules/reportes/index.php"
               class="nav-link <?= nav_activo('reportes') ?>">
                <i class="bi bi-bar-chart-line"></i> Reportes
            </a>
        </li>
        <?php endif; ?>

        <!-- ══ SECCIÓN 2: PARÁMETROS ══════════════════════════════ -->
        <?php if (es_superadmin() || es_admin()): ?>
        <li><span class="nav-section">Parámetros</span></li>

        <?php if (es_superadmin()): ?>
        <li class="nav-item">
            <a href="<?= $base ?>/modules/mantenedores/instituciones.php"
               class="nav-link <?= nav_activo('instituciones') ?>">
                <i class="bi bi-building-add"></i> Instituciones
            </a>
        </li>
        <?php endif; ?>

        <li class="nav-item">
            <a href="<?= $base ?>/modules/mantenedores/pabellones.php"
               class="nav-link <?= nav_activo('/pabellones.php') ?>
                      <?= (es_admin() && !$n_pab) ? 'text-warning' : '' ?>">
                <i class="bi bi-door-open"></i> Pabellones
                <?= (es_admin() && !$n_pab) ? '<span class="badge bg-warning text-dark ms-1" style="font-size:.6rem">!</span>' : '' ?>
            </a>
        </li>
        <li class="nav-item">
            <a href="<?= $base ?>/modules/mantenedores/tipos_cirugia.php"
               class="nav-link <?= nav_activo('tipos_cirugia') ?>
                      <?= (es_admin() && !$n_tc) ? 'text-warning' : '' ?>">
                <i class="bi bi-scissors"></i> Tipos de Cirugía
                <?= (es_admin() && !$n_tc) ? '<span class="badge bg-warning text-dark ms-1" style="font-size:.6rem">!</span>' : '' ?>
            </a>
        </li>
        <li class="nav-item">
            <a href="<?= $base ?>/modules/mantenedores/insumos.php"
               class="nav-link <?= nav_activo('insumos') ?>
                      <?= (es_admin() && !$n_ins) ? 'text-warning' : '' ?>">
                <i class="bi bi-box-seam"></i> Insumos
                <?= (es_admin() && !$n_ins) ? '<span class="badge bg-warning text-dark ms-1" style="font-size:.6rem">!</span>' : '' ?>
            </a>
        </li>
        <li class="nav-item">
            <a href="<?= $base ?>/modules/mantenedores/precios.php"
               class="nav-link <?= nav_activo('precios') ?>
                      <?= (es_admin() && !$n_pr) ? 'text-warning' : '' ?>">
                <i class="bi bi-currency-dollar"></i> Precios
                <?= (es_admin() && !$n_pr) ? '<span class="badge bg-warning text-dark ms-1" style="font-size:.6rem">!</span>' : '' ?>
            </a>
        </li>
        <li class="nav-item">
            <a href="<?= $base ?>/modules/mantenedores/acreditaciones.php"
               class="nav-link <?= nav_activo('acreditaciones') ?>
                      <?= (es_admin() && !$n_ac) ? 'text-warning' : '' ?>">
                <i class="bi bi-person-check"></i> Acreditaciones
                <?= (es_admin() && !$n_ac) ? '<span class="badge bg-warning text-dark ms-1" style="font-size:.6rem">!</span>' : '' ?>
            </a>
        </li>

        <!-- ══ SECCIÓN 3: CONFIGURACIÓN ═══════════════════════════ -->
        <li><span class="nav-section">Configuración</span></li>
        <li class="nav-item">
            <a href="<?= $base ?>/modules/mantenedores/usuarios.php"
               class="nav-link <?= nav_activo('usuarios') ?>">
                <i class="bi bi-people"></i> Usuarios
            </a>
        </li>
        <li class="nav-item">
            <a href="<?= $base ?>/modules/configuracion/index.php"
               class="nav-link <?= nav_activo('configuracion') ?>">
                <i class="bi bi-gear"></i> Configuración
            </a>
        </li>
        <?php if (es_superadmin()): ?>
        <li class="nav-item">
            <a href="<?= $base ?>/modules/configuracion/auditoria.php"
               class="nav-link <?= nav_activo('auditoria') ?>">
                <i class="bi bi-shield-check"></i> Auditoría
            </a>
        </li>
        <?php endif; ?>

        <?php endif; // fin superadmin || admin ?>

        <li><span class="nav-section">Cuenta</span></li>
        <li class="nav-item">
            <a href="<?= APP_URL ?>/modules/auth/perfil.php" class="nav-link">
                <i class="bi bi-person-circle"></i> Mi Perfil
            </a>
        </li>
        <li class="nav-item">
            <a href="<?= APP_URL ?>/modules/auth/logout.php" class="nav-link">
                <i class="bi bi-box-arrow-left"></i> Cerrar Sesión
            </a>
        </li>
    </ul>
</nav>

<!-- ══ CONTENIDO PRINCIPAL ══════════════════════════════ -->
<div id="main-content">

    <!-- Topbar -->
    <header id="topbar">
        <button class="btn btn-sm btn-outline-secondary d-md-none me-2" id="btn-toggle-sidebar">
            <i class="bi bi-list"></i>
        </button>
        <span class="page-title"><?= sanitizar($titulo_pagina) ?></span>
        <div class="d-flex align-items-center gap-3 ms-auto">
            <span class="d-none d-md-flex align-items-center gap-2">
                <?php if ($inst_logo): ?>
                    <img src="<?= $inst_logo ?>" alt="<?= sanitizar($inst_nombre) ?>"
                         style="max-height:28px;max-width:120px;object-fit:contain"
                         title="<?= sanitizar($inst_nombre) ?>">
                <?php else: ?>
                    <i class="bi bi-hospital text-primary"></i>
                    <span class="text-muted small"><?= sanitizar($inst_nombre) ?></span>
                <?php endif; ?>
            </span>
            <!-- Contador de inactividad -->
            <div id="session-timer"
                 class="d-md-flex align-items-center gap-1 px-2 py-1 rounded-2"
                 style="background:#e2e8f0;font-size:.82rem;font-weight:700;color:#334155;min-width:72px;justify-content:center;border:1px solid #cbd5e1"
                 title="Tiempo de sesión restante">
                <i class="bi bi-clock-history" style="font-size:.85rem"></i>
                <span id="timer-display"><?= es_medico() ? '10:00' : '30:00' ?></span>
            </div>

            <div class="dropdown">
                <button class="btn btn-sm btn-light dropdown-toggle d-flex align-items-center gap-2"
                        data-bs-toggle="dropdown">
                    <i class="bi bi-person-circle fs-5"></i>
                    <span class="d-none d-md-inline"><?= $nombre ?></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow">
                    <li><span class="dropdown-item-text small text-muted"><?= $rol_txt ?></span></li>
                    <li><hr class="dropdown-divider my-1"></li>
                    <li><a class="dropdown-item" href="<?= APP_URL ?>/modules/auth/perfil.php">
                        <i class="bi bi-person me-2"></i>Mi Perfil</a></li>
                    <li><a class="dropdown-item text-danger" href="<?= APP_URL ?>/modules/auth/logout.php">
                        <i class="bi bi-box-arrow-left me-2"></i>Cerrar Sesión</a></li>
                </ul>
            </div>
        </div>
    </header>

    <!-- Flash messages -->
    <div class="px-4 pt-3">
        <?= flash_html() ?>
    </div>

    <!-- Contenido de la página -->
    <main class="p-4 flex-grow-1">
    <?php
}

function layout_footer(): void
{
    ?>
    </main>
    <footer class="text-center py-3 text-muted small border-top bg-white">
        <?= APP_NAME ?> v<?= APP_VERSION ?> &mdash; &copy; <?= date('Y') ?>
    </footer>
</div><!-- /#main-content -->

<style>
@keyframes pulsar {
    0%,100% { opacity:1; transform:scale(1); }
    50%      { opacity:.7; transform:scale(1.06); }
}
</style>
<!-- Bootstrap 5 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Contador de inactividad ───────────────────────────────
(function() {
    const MAX_INACTIVO = <?= es_medico() ? INACTIVIDAD_MEDICO : INACTIVIDAD_ADMIN ?>;
    const LOGOUT_URL   = '<?= APP_URL ?>/modules/auth/logout.php';
    const LOGIN_URL    = '<?= APP_URL ?>/index.php?msg=sesion_expirada&inactivo=1';

    let segundosRestantes = MAX_INACTIVO;
    let timer;
    const display    = document.getElementById('timer-display');
    const timerEl    = document.getElementById('session-timer');

    function resetTimer() {
        segundosRestantes = MAX_INACTIVO;
        if (timerEl) timerEl.classList.remove('text-danger','text-warning');
    }

    function tick() {
        segundosRestantes--;
        const min = Math.floor(segundosRestantes / 60);
        const sec = segundosRestantes % 60;
        if (display) {
            display.textContent = String(min).padStart(2,'0') + ':' + String(sec).padStart(2,'0');
        }
        // Alertas visuales progresivas
        if (timerEl) {
            const pct = segundosRestantes / MAX_INACTIVO;
            if (segundosRestantes <= 60) {
                // Rojo pulsante — menos de 1 minuto
                timerEl.style.background  = 'rgba(220,38,38,.85)';
                timerEl.style.color       = '#fff';
                timerEl.style.animation   = 'pulsar .8s ease-in-out infinite';
                timerEl.title = '⚠️ ¡Sesión expira en menos de 1 minuto!';
            } else if (segundosRestantes <= 180) {
                // Naranja — menos de 3 minutos
                timerEl.style.background = 'rgba(234,88,12,.8)';
                timerEl.style.color      = '#fff';
                timerEl.style.animation  = '';
                timerEl.title = 'Sesión expira en menos de 3 minutos';
            } else if (segundosRestantes <= 300) {
                // Amarillo — menos de 5 minutos
                timerEl.style.background = 'rgba(202,138,4,.75)';
                timerEl.style.color      = '#fff';
                timerEl.style.animation  = '';
                timerEl.title = 'Sesión expira en menos de 5 minutos';
            } else {
                timerEl.style.background  = '#e2e8f0';
                timerEl.style.color       = '#334155';
                timerEl.style.border      = '1px solid #cbd5e1';
                timerEl.style.animation   = '';
                timerEl.title = 'Tiempo de sesión restante';
            }
        }
        // Expirar
        if (segundosRestantes <= 0) {
            clearInterval(timer);
            alert('Su sesión ha expirado por inactividad. Será redirigido al login.');
            window.location.href = LOGIN_URL;
        }
    }

    // Resetear en cualquier interacción del usuario
    ['mousemove','keydown','click','scroll','touchstart'].forEach(ev => {
        document.addEventListener(ev, resetTimer, { passive: true });
    });

    timer = setInterval(tick, 1000);
})();
</script>
<!-- FullCalendar -->
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>
<!-- App JS -->
<script src="<?= APP_URL ?>/assets/js/app.js"></script>
</body>
</html>
    <?php
}
