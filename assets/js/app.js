/**
 * app.js — Scripts globales Sistema Pabellones
 */

// ── Toggle sidebar en móvil ───────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    const btnToggle = document.getElementById('btn-toggle-sidebar');
    const sidebar   = document.getElementById('sidebar');
    if (btnToggle && sidebar) {
        btnToggle.addEventListener('click', () => sidebar.classList.toggle('open'));
    }

    // Auto-cerrar alertas flash después de 5 segundos
    document.querySelectorAll('.alert.alert--success, .alert.alert--info').forEach(el => {
        setTimeout(() => {
            const bsAlert = bootstrap.Alert.getOrCreateInstance(el);
            if (bsAlert) bsAlert.close();
        }, 5000);
    });

    // Tooltip Bootstrap — activar en TODOS los elementos con title=
    // Incluye botones de ícono en toda la aplicación
    document.querySelectorAll('[title]').forEach(el => {
        // Excluir inputs y selects (el title en ellos es texto de ayuda nativo)
        if (['INPUT','SELECT','TEXTAREA','OPTION'].includes(el.tagName)) return;
        new bootstrap.Tooltip(el, {
            placement: 'top',
            trigger:   'hover',
            delay:     { show: 300, hide: 100 },
        });
    });
});

// ── Confirmar eliminación ─────────────────────────────────
function confirmarEliminar(url, nombre = 'este registro') {
    if (confirm(`¿Está seguro que desea eliminar ${nombre}?\nEsta acción no se puede deshacer.`)) {
        window.location.href = url;
    }
}

// ── Confirmación genérica ─────────────────────────────────
function confirmar(mensaje, url) {
    if (confirm(mensaje)) window.location.href = url;
}

// ── Formatear RUT chileno ─────────────────────────────────
function formatearRut(input) {
    let rut = input.value.replace(/[^0-9kK]/g, '').toUpperCase();
    if (rut.length > 1) {
        const cuerpo = rut.slice(0, -1);
        const dv     = rut.slice(-1);
        const formateado = cuerpo.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        input.value = formateado + '-' + dv;
    } else {
        input.value = rut;
    }
}

// ── AJAX helper ───────────────────────────────────────────
async function fetchJSON(url, datos = {}) {
    const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(datos),
    });
    if (!res.ok) throw new Error('Error de red: ' + res.status);
    return res.json();
}
