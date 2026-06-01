<?php
// Evitar acceso directo
if (!defined('MUDO_ACCESS') && !isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit;
}
?>

<form id="appearanceForm">
    <div class="card border-0 shadow-sm mb-4" style="border-radius: 1rem;">
        <div class="card-header bg-white border-bottom-0 pt-4 ps-4" style="border-radius: 1rem 1rem 0 0;">
            <h5 class="mb-0 text-dark">
                <i class="fas fa-moon me-2 text-dark"></i>Apariencia
            </h5>
            <p class="text-muted small mb-0 ps-1">Elige el modo del sistema.</p>
        </div>
        <div class="card-body p-4">
            <?php
                $current_mode = $system_config['theme_mode'] ?? 'light';
            ?>
            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <label class="form-label small text-muted">Modo del Tema</label>
                    <div class="d-flex align-items-center gap-3">
                        <label class="me-3">
                            <input type="radio" name="theme_mode" value="light" <?= $current_mode === 'light' ? 'checked' : '' ?>> Claro
                        </label>
                        <label>
                            <input type="radio" name="theme_mode" value="dark" <?= $current_mode === 'dark' ? 'checked' : '' ?>> Oscuro
                        </label>
                    </div>
                </div>
            </div>
            
            <div class="row mt-4">
                <div class="col-12 text-end">
                    <button type="submit" class="btn btn-dark rounded-pill px-4 shadow-sm" id="btnSaveAppearance">
                        <i class="fas fa-save me-2"></i>Guardar Apariencia
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
document.getElementById('appearanceForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSaveAppearance');
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Guardando...';
    btn.disabled = true;

    const formData = new FormData(this);
    formData.append('action', 'update_appearance');
    try {
        const m = document.querySelector('meta[name="csrf-token"]');
        if (m) formData.append('csrf_token', m.getAttribute('content'));
    } catch(e) {}

    fetch('config_operations.php', {
        method: 'POST',
        headers: { 'Accept': 'application/json' },
        body: formData,
        credentials: 'same-origin'
    })
    .then(window.parseJsonResponse)
    .then(data => {
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: 'Apariencia actualizada',
                text: 'El sistema recargará para aplicar el nuevo modo.',
                showConfirmButton: false,
                timer: 1500
            }).then(() => {
                window.location.reload();
            });
        } else {
            Swal.fire('Error', data.message || 'Error al actualizar apariencia', 'error');
            btn.innerHTML = originalText;
            btn.disabled = false;
        }
    })
    .catch(error => {
        Swal.fire('Error', 'Error de conexión', 'error');
        btn.innerHTML = originalText;
        btn.disabled = false;
    });
});
</script>
