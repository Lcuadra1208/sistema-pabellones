<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/brevo.php';
requiere_login(ROL_ADMIN_HOSPITAL);

$usuario  = usuario_actual();
$id_inst  = usuario_institucion();
$error    = '';
$exito    = '';
$tab      = $_GET['tab'] ?? (es_superadmin() ? 'email' : 'logo');

// ════════════════════════════════════════════════════════════
// POST
// ════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_validar()) {
    $af = $_POST['accion_form'] ?? '';

    // ── Guardar config email (solo superadmin) ────────────
    if ($af === 'guardar_email' && es_superadmin()) {
        $campos = [
            'brevo_api_key'    => sanitizar($_POST['brevo_api_key']    ?? ''),
            'brevo_from_email' => sanitizar($_POST['brevo_from_email'] ?? ''),
            'brevo_from_name'  => sanitizar($_POST['brevo_from_name']  ?? ''),
            'email_activo'     => isset($_POST['email_activo']) ? '1' : '0',
        ];
        foreach ($campos as $clave => $valor) {
            Database::query(
                "INSERT INTO configuracion_sistema (clave, valor) VALUES (?,?)
                 ON DUPLICATE KEY UPDATE valor=VALUES(valor)",
                [$clave, $valor]
            );
        }
        audit('config_email', 'configuracion_sistema', 0);
        $exito = 'Configuración de email guardada correctamente.';
    }

    // ── Test de email ─────────────────────────────────────
    if ($af === 'test_email' && es_superadmin()) {
        $dest = sanitizar($_POST['test_email_dest'] ?? '');
        if (!filter_var($dest, FILTER_VALIDATE_EMAIL)) {
            $error = 'Ingrese un email válido para la prueba.';
        } else {
            $html = BrevoMailer::templateBase(
                '✅ Email de prueba',
                "<p style='color:#475569'>Este es un email de prueba desde <strong>SistemaPabellones</strong>.</p>
                 <p style='color:#475569'>Si recibe este mensaje, la configuración de Brevo está funcionando correctamente.</p>"
            );
            $result = BrevoMailer::enviar($dest, 'Prueba', 'Test de email — SistemaPabellones', $html);
            if ($result['ok']) {
                $exito = 'Email de prueba enviado a '.$dest.' correctamente.';
            } else {
                $error = 'Error al enviar: '.$result['msg'];
            }
        }
    }

    // ── Subir logo (admin de institución) ────────────────
    if ($af === 'subir_logo' && $id_inst) {
        if (!isset($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
            $error = 'Error al subir el archivo. Intente nuevamente.';
        } else {
            $file     = $_FILES['logo'];
            $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed  = ['jpg','jpeg','png','svg','webp'];
            $max_size = 2 * 1024 * 1024; // 2 MB

            if (!in_array($ext, $allowed)) {
                $error = 'Solo se permiten imágenes JPG, PNG, SVG o WebP.';
            } elseif ($file['size'] > $max_size) {
                $error = 'El archivo no debe superar 2 MB.';
            } else {
                // Verificar que es realmente una imagen
                $img_info = @getimagesize($file['tmp_name']);
                if (!$img_info && $ext !== 'svg') {
                    $error = 'El archivo no es una imagen válida.';
                } else {
                    $nombre_archivo = 'logo_inst_'.$id_inst.'_'.time().'.'.$ext;
                    $destino        = APP_ROOT.'/uploads/logos/'.$nombre_archivo;

                    // Eliminar logo anterior
                    $inst_actual = Database::fetchOne("SELECT logo FROM instituciones WHERE id=?", [$id_inst]);
                    if ($inst_actual['logo']) {
                        $logo_anterior = APP_ROOT.'/uploads/logos/'.basename($inst_actual['logo']);
                        if (file_exists($logo_anterior)) @unlink($logo_anterior);
                    }

                    if (move_uploaded_file($file['tmp_name'], $destino)) {
                        Database::query(
                            "UPDATE instituciones SET logo=? WHERE id=?",
                            ['uploads/logos/'.$nombre_archivo, $id_inst]
                        );
                        audit('subir_logo', 'instituciones', $id_inst);
                        $exito = 'Logo actualizado correctamente.';
                    } else {
                        $error = 'No se pudo guardar el archivo. Verifique permisos de la carpeta uploads/logos/';
                    }
                }
            }
        }
    }

    // ── Eliminar logo ─────────────────────────────────────
    if ($af === 'eliminar_logo' && $id_inst) {
        $inst = Database::fetchOne("SELECT logo FROM instituciones WHERE id=?", [$id_inst]);
        if ($inst['logo']) {
            $path = APP_ROOT.'/uploads/logos/'.basename($inst['logo']);
            if (file_exists($path)) @unlink($path);
            Database::query("UPDATE instituciones SET logo=NULL WHERE id=?", [$id_inst]);
            $exito = 'Logo eliminado.';
        }
    }
}

// ── Datos actuales ────────────────────────────────────────
$config_email = [];
if (es_superadmin()) {
    $rows = Database::fetchAll("SELECT clave, valor FROM configuracion_sistema");
    foreach ($rows as $r) $config_email[$r['clave']] = $r['valor'];
}

$institucion = $id_inst
    ? Database::fetchOne("SELECT id, nombre, logo FROM instituciones WHERE id=?", [$id_inst])
    : null;

// Logo URL para mostrar
$logo_url = '';
if ($institucion && $institucion['logo']) {
    $logo_url = APP_URL.'/'.$institucion['logo'];
}

layout_header('Configuración del Sistema');
?>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4">
    <?php if (es_superadmin()): ?>
    <li class="nav-item">
        <a class="nav-link <?= $tab==='email'?'active':'' ?>" href="?tab=email">
            <i class="bi bi-envelope-gear me-1"></i> Email (Brevo)
        </a>
    </li>
    <?php endif; ?>
    <?php if ($id_inst): ?>
    <li class="nav-item">
        <a class="nav-link <?= $tab==='logo'?'active':'' ?>" href="?tab=logo">
            <i class="bi bi-image me-1"></i> Logo Institucional
        </a>
    </li>
    <?php endif; ?>
</ul>

<?php if ($error): ?>
<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?= sanitizar($error) ?></div>
<?php endif; ?>
<?php if ($exito): ?>
<div class="alert alert-success"><i class="bi bi-check-circle me-2"></i><?= sanitizar($exito) ?></div>
<?php endif; ?>

<?php if ($tab === 'email' && es_superadmin()): ?>
<!-- ══ TAB EMAIL ══════════════════════════════════════════ -->
<div class="row g-4">
    <div class="col-md-7">
        <div class="pab-card p-4">
            <h6 class="fw-bold mb-1"><i class="bi bi-envelope-gear me-2 text-primary"></i>Configuración Brevo</h6>
            <p class="text-muted small mb-4">
                Configure su cuenta de <a href="https://www.brevo.com" target="_blank">Brevo</a>
                para habilitar el envío de notificaciones automáticas por email.
            </p>
            <form method="POST">
                <?= csrf_campo() ?>
                <input type="hidden" name="accion_form" value="guardar_email">
                <div class="mb-3">
                    <label class="form-label fw-semibold">API Key de Brevo <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-key"></i></span>
                        <input type="password" name="brevo_api_key" id="inp_apikey"
                               class="form-control font-monospace"
                               value="<?= sanitizar($config_email['brevo_api_key'] ?? '') ?>"
                               placeholder="xkeysib-...">
                        <button type="button" title="Ver detalle" class="btn btn-outline-secondary"
                                onclick="toggleApiKey()">
                            <i class="bi bi-eye" id="ico_apikey"></i>
                        </button>
                    </div>
                    <div class="form-text">
                        Obtener en: Brevo → Configuración → Claves API → Crear nueva clave API
                    </div>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Email remitente</label>
                        <input type="email" name="brevo_from_email" class="form-control"
                               value="<?= sanitizar($config_email['brevo_from_email'] ?? '') ?>"
                               placeholder="no-reply@miorganizacion.cl">
                        <div class="form-text">Debe estar verificado en Brevo.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Nombre remitente</label>
                        <input type="text" name="brevo_from_name" class="form-control"
                               value="<?= sanitizar($config_email['brevo_from_name'] ?? '') ?>"
                               placeholder="Red de Salud XYZ">
                    </div>
                </div>
                <div class="form-check form-switch mt-4 mb-4">
                    <input class="form-check-input" type="checkbox" name="email_activo"
                           id="chk_email" role="switch"
                           <?= ($config_email['email_activo'] ?? '0') === '1' ? 'checked' : '' ?>>
                    <label class="form-check-label fw-semibold" for="chk_email">
                        Habilitar envío de emails
                    </label>
                    <div class="text-muted small">Al deshabilitar, el sistema no enviará notificaciones aunque la configuración esté completa.</div>
                </div>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save me-1"></i> Guardar configuración
                </button>
            </form>
        </div>
    </div>
    <div class="col-md-5">
        <!-- Estado actual -->
        <div class="pab-card p-4 mb-3">
            <h6 class="fw-bold mb-3"><i class="bi bi-info-circle me-2"></i>Estado actual</h6>
            <?php
            $tiene_key  = !empty($config_email['brevo_api_key']);
            $tiene_from = !empty($config_email['brevo_from_email']);
            $activo     = ($config_email['email_activo'] ?? '0') === '1';
            $items = [
                ['API Key configurada',   $tiene_key],
                ['Email remitente',        $tiene_from],
                ['Envío habilitado',       $activo],
            ];
            foreach ($items as [$label, $ok]):
            ?>
            <div class="d-flex align-items-center gap-2 mb-2">
                <i class="bi bi-<?= $ok ? 'check-circle-fill text-success' : 'x-circle-fill text-danger' ?>"></i>
                <span class="small <?= $ok ? '' : 'text-muted' ?>"><?= $label ?></span>
            </div>
            <?php endforeach; ?>
            <?php if ($tiene_key && $activo): ?>
            <div class="alert alert-success py-2 mt-3 mb-0 small">
                <i class="bi bi-check-circle me-1"></i> Sistema listo para enviar emails.
            </div>
            <?php else: ?>
            <div class="alert alert-warning py-2 mt-3 mb-0 small">
                <i class="bi bi-exclamation-triangle me-1"></i> Complete la configuración para habilitar emails.
            </div>
            <?php endif; ?>
        </div>

        <!-- Test de email -->
        <div class="pab-card p-4">
            <h6 class="fw-bold mb-3"><i class="bi bi-send me-2"></i>Enviar email de prueba</h6>
            <form method="POST">
                <?= csrf_campo() ?>
                <input type="hidden" name="accion_form" value="test_email">
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Destinatario de prueba</label>
                    <input type="email" name="test_email_dest" class="form-control form-control-sm"
                           placeholder="correo@ejemplo.com"
                           value="<?= sanitizar($usuario['email']) ?>">
                </div>
                <button type="submit" class="btn btn-outline-primary btn-sm w-100"
                        <?= (!$tiene_key || !$activo) ? 'disabled' : '' ?>>
                    <i class="bi bi-send me-1"></i> Enviar prueba
                </button>
                <?php if (!$tiene_key || !$activo): ?>
                <div class="text-muted small mt-1 text-center">Configure y habilite el email primero.</div>
                <?php endif; ?>
            </form>
        </div>

        <!-- Qué emails se envían -->
        <div class="pab-card p-4 mt-3">
            <h6 class="fw-bold mb-3"><i class="bi bi-bell me-2"></i>Notificaciones automáticas</h6>
            <ul class="list-unstyled small text-muted mb-0">
                <li class="mb-1"><i class="bi bi-dot text-success"></i> Médico: solicitud aprobada</li>
                <li class="mb-1"><i class="bi bi-dot text-danger"></i> Médico: solicitud rechazada</li>
                <li class="mb-1"><i class="bi bi-dot text-primary"></i> Admin: nueva solicitud pendiente</li>
            </ul>
        </div>
    </div>
</div>

<script>
function toggleApiKey() {
    const inp = document.getElementById('inp_apikey');
    const ico = document.getElementById('ico_apikey');
    if (inp.type === 'password') {
        inp.type = 'text';
        ico.className = 'bi bi-eye-slash';
    } else {
        inp.type = 'password';
        ico.className = 'bi bi-eye';
    }
}
</script>

<?php elseif ($tab === 'logo' && $id_inst): ?>
<!-- ══ TAB LOGO ═══════════════════════════════════════════ -->
<div class="row g-4">
    <div class="col-md-5">
        <div class="pab-card p-4">
            <h6 class="fw-bold mb-1"><i class="bi bi-image me-2 text-primary"></i>Logo actual</h6>
            <p class="text-muted small mb-4"><?= sanitizar($institucion['nombre'] ?? '') ?></p>

            <?php if ($logo_url): ?>
            <div class="text-center mb-4 p-3 bg-light rounded">
                <img src="<?= $logo_url ?>" alt="Logo institucional"
                     style="max-height:120px;max-width:100%;object-fit:contain">
            </div>
            <form method="POST" class="text-center">
                <?= csrf_campo() ?>
                <input type="hidden" name="accion_form" value="eliminar_logo">
                <button type="submit" class="btn btn-sm btn-outline-danger"
                        onclick="return confirm('¿Eliminar el logo actual?')">
                    <i class="bi bi-trash3 me-1"></i> Eliminar logo
                </button>
            </form>
            <?php else: ?>
            <div class="text-center py-4 text-muted">
                <i class="bi bi-image fs-1 d-block mb-2 opacity-25"></i>
                Sin logo configurado
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-md-7">
        <div class="pab-card p-4">
            <h6 class="fw-bold mb-1"><i class="bi bi-upload me-2"></i>Subir nuevo logo</h6>
            <p class="text-muted small mb-4">
                El logo aparecerá en los emails enviados a médicos y en futuras pantallas del sistema.
            </p>
            <form method="POST" enctype="multipart/form-data">
                <?= csrf_campo() ?>
                <input type="hidden" name="accion_form" value="subir_logo">

                <!-- Drop zone -->
                <div id="dropzone"
                     class="border border-2 border-dashed rounded-3 p-4 text-center mb-3"
                     style="cursor:pointer;transition:border-color .2s"
                     onclick="document.getElementById('inp_logo').click()"
                     ondragover="event.preventDefault();this.style.borderColor='#1a6fa3'"
                     ondragleave="this.style.borderColor=''"
                     ondrop="handleDrop(event)">
                    <i class="bi bi-cloud-upload fs-2 text-muted d-block mb-2"></i>
                    <div class="fw-semibold text-muted">Arrastre su logo aquí o haga clic</div>
                    <div class="small text-muted mt-1">JPG, PNG, SVG o WebP — máximo 2 MB</div>
                    <div id="preview_nombre" class="mt-2 small text-primary d-none"></div>
                </div>
                <img id="preview_img" src="" alt="" class="d-none rounded mb-3"
                     style="max-height:80px;max-width:100%;object-fit:contain">

                <input type="file" id="inp_logo" name="logo" accept=".jpg,.jpeg,.png,.svg,.webp"
                       class="d-none" onchange="previsualizarLogo(this)">

                <div class="d-flex gap-2">
                    <button type="submit" id="btn_subir" class="btn btn-primary" disabled>
                        <i class="bi bi-upload me-1"></i> Subir logo
                    </button>
                    <button type="button" class="btn btn-outline-secondary"
                            onclick="document.getElementById('inp_logo').click()">
                        <i class="bi bi-folder2-open me-1"></i> Buscar archivo
                    </button>
                </div>
            </form>
        </div>

        <div class="pab-card p-3 mt-3">
            <div class="small text-muted">
                <i class="bi bi-lightbulb text-warning me-2"></i>
                <strong>Recomendaciones:</strong> Use un logo en formato PNG con fondo transparente o SVG.
                Dimensiones sugeridas: mínimo 200×60 px, máximo 600×200 px.
            </div>
        </div>
    </div>
</div>

<script>
function previsualizarLogo(input) {
    const file = input.files[0];
    if (!file) return;
    document.getElementById('preview_nombre').textContent = file.name;
    document.getElementById('preview_nombre').classList.remove('d-none');
    document.getElementById('btn_subir').disabled = false;

    if (file.type.startsWith('image/') || file.name.endsWith('.svg')) {
        const reader = new FileReader();
        reader.onload = e => {
            const img = document.getElementById('preview_img');
            img.src = e.target.result;
            img.classList.remove('d-none');
        };
        reader.readAsDataURL(file);
    }
}

function handleDrop(e) {
    e.preventDefault();
    document.getElementById('dropzone').style.borderColor = '';
    const files = e.dataTransfer.files;
    if (files.length > 0) {
        const inp = document.getElementById('inp_logo');
        const dt  = new DataTransfer();
        dt.items.add(files[0]);
        inp.files = dt.files;
        previsualizarLogo(inp);
    }
}
</script>
<?php endif; ?>

<?php layout_footer(); ?>
