<?php
require_once '../config/init_public.php';
require_once '../config/company_settings.php';

$company_logo = 'system_logo.png';
$favicon_logo = 'system_logo.png';

if (isValidSession()) {
    header("Location: ../dashboard/index.php");
    exit();
}
$error = isset($_GET['error']) ? $_GET['error'] : '';
$csrfToken = SecurityEnhancements::generateCSRFToken();

$savedUsers = isset($_COOKIE['saved_users']) ? json_decode($_COOKIE['saved_users'], true) : [];
if (!is_array($savedUsers)) $savedUsers = [];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CORE - Iniciar Sesión</title>
    <link rel="icon" type="image/png" href="../assets/img/<?php echo htmlspecialchars($favicon_logo) . '?v=' . time(); ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../assets/css/modern-ui-enhancements.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <style>
        .rounded-btn { border-radius: 999px !important; }
        #registerModal .modal-content { border: none; border-radius: 24px; overflow: hidden; }
        #registerModal .modal-header { background-color: #111; color: #fff; border: none; padding: 1.5rem 2rem; }
        #registerModal .modal-body { padding: 2rem; }
        #registerModal .form-control { border-radius: 12px; padding: 12px 15px; border: 1px solid #e0e0e0; background-color: #fcfcfc; }
        #registerModal .form-control:focus { box-shadow: 0 0 0 4px rgba(13, 110, 253, 0.15); border-color: var(--bs-primary); background-color: #fff; }
    </style>
</head>
<body class="bg-light login-page-bg">
    <div class="container d-flex align-items-center justify-content-center min-vh-100">
        <div class="card shadow-lg border-0 rounded-4 w-100" style="max-width: 400px;">
            <div class="card-body p-4 p-md-5 text-center">
                <div class="d-flex align-items-center justify-content-center mb-4">
                    <img src="../assets/img/<?php echo htmlspecialchars($company_logo) . '?v=' . time(); ?>" alt="CORE Logo" height="50" class="me-2" id="coreLogo">
                    <h1 class="fw-bold mb-0 text-dark" style="font-size: 2rem; letter-spacing: -1px;">CORE</h1>
                </div>

                <h4 class="mb-4 text-secondary fw-semibold">Iniciar Sesión</h4>

                <?php if ($error): ?>
                    <div class="alert alert-danger p-2 small mb-4" role="alert">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <div id="savedUsersContainer" class="<?php echo empty($savedUsers) ? 'd-none' : ''; ?>">
                    <h5 class="mb-3 text-secondary text-start fs-6">Elige una cuenta</h5>
                    <div class="list-group mb-3 text-start">
                        <?php foreach($savedUsers as $su): ?>
                        <button type="button" class="list-group-item list-group-item-action d-flex align-items-center py-2 border-0 shadow-sm mb-2 rounded-3" onclick="selectSavedUser('<?php echo htmlspecialchars($su['email']); ?>')">
                            <div class="rounded-circle bg-dark text-white d-flex align-items-center justify-content-center me-3" style="width: 40px; height: 40px; font-weight: bold; font-size: 1.2rem;">
                                <?php echo htmlspecialchars(strtoupper(substr($su['name'] ?: $su['email'], 0, 1))); ?>
                            </div>
                            <div class="flex-grow-1 overflow-hidden">
                                <div class="fw-bold text-truncate text-dark" style="font-size: 0.95rem;"><?php echo htmlspecialchars($su['name'] ?: explode('@', $su['email'])[0]); ?></div>
                                <div class="text-muted small text-truncate"><?php echo htmlspecialchars($su['email']); ?></div>
                            </div>
                        </button>
                        <?php endforeach; ?>

                        <div class="dropdown-divider mb-2"></div>
                        <button type="button" class="list-group-item list-group-item-action d-flex align-items-center py-2 border-0 bg-transparent rounded-3 text-dark fw-semibold" onclick="showNewAccountForm()">
                            <div class="d-flex align-items-center justify-content-center me-3" style="width: 40px; text-align: center;">
                                <i class="fa fa-user-circle fa-lg text-secondary"></i>
                            </div>
                            Usar otra cuenta
                        </button>
                    </div>
                </div>

                <div id="loginFormContainer" class="<?php echo !empty($savedUsers) ? 'd-none' : ''; ?>">
                    <div class="d-flex align-items-center justify-content-center mb-4 d-none" id="selectedUserHeader">
                        <div class="bg-light px-3 py-2 rounded-pill d-inline-flex align-items-center border position-relative" style="padding-right: 2.5rem !important; cursor: pointer;" onclick="showSavedUsers()" title="Cambiar de cuenta">
                            <i class="fa fa-user-circle text-secondary me-2 fs-5"></i>
                            <span id="selectedUserEmail" class="fw-bold text-secondary small"></span>
                            <i class="fa fa-chevron-down ms-2 position-absolute end-0 me-3 text-muted" style="font-size: 0.75rem;"></i>
                        </div>
                    </div>

                    <form action="process.php" method="POST" autocomplete="on">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

                        <div class="input-group mb-3 login-input-group rounded-3 overflow-hidden border" id="emailInputGroup">
                            <span class="input-group-text bg-white border-0 text-muted"><i class="fa fa-user"></i></span>
                            <input type="email" class="form-control border-0 px-2 py-3" name="email" id="loginEmail" placeholder="Correo electrónico" autocomplete="username" required>
                        </div>

                        <div class="input-group mb-3 login-input-group rounded-3 overflow-hidden border">
                            <span class="input-group-text bg-white border-0 text-muted"><i class="fa fa-lock"></i></span>
                            <input type="password" class="form-control border-0 px-2 py-3" name="password" id="loginPassword" placeholder="Contraseña" autocomplete="current-password" required>
                            <span class="input-group-text bg-white border-0 text-muted" style="cursor: pointer;" onclick="togglePassword('loginPassword', this.querySelector('i'))">
                                <i class="fas fa-eye"></i>
                            </span>
                        </div>

                        <div class="form-check text-start mb-4">
                            <input class="form-check-input" type="checkbox" id="remember_me" name="remember_me" value="1">
                            <label class="form-check-label text-muted small" for="remember_me">
                                No cerrar sesión automáticamente
                            </label>
                        </div>

                        <button type="submit" class="no-theme btn-dark w-100 py-2 fs-6 fw-bold rounded-3 mb-3 hover-shadow-lg transition-all">Ingresar</button>

                        <div class="d-flex justify-content-center gap-4">
                            <a href="forgot_password.php" class="text-decoration-none small text-muted hover-primary">¿Olvidaste tu contraseña?</a>
                            <a href="#" class="text-decoration-none small text-muted hover-primary" data-bs-toggle="modal" data-bs-target="#registerModal">¿Crear empresa?</a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card-footer bg-white border-0 text-center py-3">
                <p class="small text-muted mb-0">© <?php echo date("Y"); ?> CORE. Todos los derechos reservados.</p>
            </div>
        </div>
    </div>

    <div class="modal fade" id="registerModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content shadow-lg">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold"><i class="bi bi-rocket-takeoff me-2"></i>Registrar Nueva Empresa</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body bg-light">
                    <div class="row g-0">
                        <div class="col-lg-5 d-none d-lg-flex align-items-center justify-content-center bg-white rounded-start p-4 border-end">
                            <div class="text-center">
                                <img src="../assets/img/system_logo.png" alt="Logo" class="img-fluid mb-3" style="max-height: 100px;">
                                <h4 class="fw-bold text-dark mb-2">Core</h4>
                                <p class="text-muted small">Tu centro de operaciones digital.</p>
                                <ul class="list-unstyled text-start mt-4 small text-secondary">
                                    <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i>Configuración instantánea</li>
                                    <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i>Acceso inmediato</li>
                                    <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i>Seguridad garantizada</li>
                                </ul>
                            </div>
                        </div>
                        <div class="col-lg-7">
                            <div class="p-4 bg-white h-100 rounded-end">
                                <div id="registerAlert" class="alert d-none" role="alert"></div>

                                <form id="registerForm" autocomplete="off">
                                    <input type="hidden" name="ajax" value="1">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

                                    <div class="mb-3">
                                        <label class="form-label fw-bold small text-uppercase text-muted">Código de Licencia</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light border-end-0"><i class="bi bi-key"></i></span>
                                            <input type="text" name="license_code" class="form-control border-start-0 ps-0" required placeholder="XXXX-XXXX-XXXX" inputmode="numeric" maxlength="14" autocomplete="off">
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-12 mb-3">
                                            <label class="form-label fw-bold small text-uppercase text-muted">Nombre de la Empresa</label>
                                            <div class="input-group">
                                                <span class="input-group-text bg-light border-end-0"><i class="bi bi-building"></i></span>
                                                <input type="text" name="company_name" class="form-control border-start-0 ps-0" required placeholder="Ej. Taller Central">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label fw-bold small text-uppercase text-muted">Email Administrador</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light border-end-0"><i class="bi bi-envelope"></i></span>
                                            <input type="email" name="admin_email" class="form-control border-start-0 ps-0" required placeholder="admin@empresa.com" autocomplete="email">
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label fw-bold small text-uppercase text-muted">Nombre del Usuario Admin</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light border-end-0"><i class="bi bi-person"></i></span>
                                            <input type="text" name="admin_name" class="form-control border-start-0 ps-0" required placeholder="Ej. Juan Pérez">
                                        </div>
                                    </div>

                                    <div class="mb-4">
                                        <label class="form-label fw-bold small text-uppercase text-muted">Contraseña</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light border-end-0"><i class="bi bi-lock"></i></span>
                                            <input type="password" name="admin_password" class="form-control border-start-0 ps-0" required placeholder="********" id="adminPwdModal" minlength="6" autocomplete="new-password">
                                            <button class="btn btn-outline-secondary" type="button" id="togglePwdModal"><i class="bi bi-eye"></i></button>
                                        </div>
                                    </div>

                                    <div class="d-grid">
                                        <button type="submit" class="btn btn-dark rounded-btn py-2 fw-bold shadow-sm" id="btnRegister">
                                            <span class="spinner-border spinner-border-sm d-none me-2" role="status" aria-hidden="true"></span>
                                            Registrar y Comenzar
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

<script>
    const savedUsersContainer = document.getElementById('savedUsersContainer');
    const loginFormContainer = document.getElementById('loginFormContainer');
    const emailInput = document.getElementById('loginEmail') || document.querySelector('input[name="email"]');
    const passwordInput = document.getElementById('loginPassword');
    const emailInputGroup = document.getElementById('emailInputGroup');
    const selectedUserHeader = document.getElementById('selectedUserHeader');
    const selectedUserEmail = document.getElementById('selectedUserEmail');

    <?php if ($error && !empty($savedUsers)): ?>
        savedUsersContainer.classList.add('d-none');
        loginFormContainer.classList.remove('d-none');
        const lastErr = "<?php echo htmlspecialchars($error); ?>";
        if (lastErr.includes('Credenciales')) {
            emailInputGroup.classList.remove('d-none');
        }
    <?php endif; ?>

    function selectSavedUser(email) {
        emailInput.value = email;
        emailInput.readOnly = true;
        try { emailInput.setAttribute('autocomplete', 'username'); } catch (_) {}
        savedUsersContainer.classList.add('d-none');
        loginFormContainer.classList.remove('d-none');

        selectedUserHeader.classList.remove('d-none');
        selectedUserEmail.textContent = email;

        emailInputGroup.classList.remove('d-none');

        passwordInput.value = '';
        passwordInput.focus();
        try {
            setTimeout(function() { passwordInput.focus(); }, 0);
        } catch (_) {}
    }

    function showNewAccountForm() {
        emailInput.value = '';
        emailInput.readOnly = false;
        savedUsersContainer.classList.add('d-none');
        loginFormContainer.classList.remove('d-none');

        selectedUserHeader.classList.add('d-none');
        emailInputGroup.classList.remove('d-none');
        passwordInput.value = '';
        emailInput.focus();
    }

    function showSavedUsers() {
        loginFormContainer.classList.add('d-none');
        savedUsersContainer.classList.remove('d-none');
        passwordInput.value = '';
    }

    function togglePassword(fieldId, icon) {
        const input = document.getElementById(fieldId);
        if (input.type === "password") {
            input.type = "text";
            icon.classList.remove("fa-eye");
            icon.classList.add("fa-eye-slash");
        } else {
            input.type = "password";
            icon.classList.remove("fa-eye-slash");
            icon.classList.add("fa-eye");
        }
    }

    document.addEventListener('keydown', function(event) {
        if (event.ctrlKey && event.altKey && (event.key === 'k' || event.key === 'K')) {
            event.preventDefault();
            window.location.href = '../super_admin/login.php';
        }
    });
    (function(){
        try {
            if (!(window.matchMedia && window.matchMedia('(pointer: coarse)').matches)) return;
            var logo = document.getElementById('coreLogo');
            if (!logo) return;
            var taps = 0, timer = null;
            function reset(){ taps = 0; if (timer) { clearTimeout(timer); timer = null; } }
            function onTap(){
                taps++;
                if (taps === 1) { timer = setTimeout(reset, 4000); }
                if (taps >= 8) {
                    reset();
                    window.location.replace('../super_admin/login.php');
                }
            }
            logo.addEventListener('touchend', function(ev){
                if (ev.touches && ev.touches.length > 0) return;
                onTap();
            }, { passive: true });
        } catch(_){}
    })();

    (function(){
        var lic = document.querySelector('#registerModal input[name="license_code"]');
        if (lic) {
            var fmt = function(v){
                v = String(v || '').toUpperCase().replace(/[^A-Z0-9]/g,'');
                var parts = [];
                for (var i=0; i<v.length && parts.length<3; i+=4) { parts.push(v.substring(i, i+4)); }
                return parts.join('-').substring(0, 14);
            };
            lic.addEventListener('input', function(e){
                var after = fmt(e.target.value);
                e.target.value = after;
                try { e.target.setSelectionRange(after.length, after.length); } catch(_){}
            });
            lic.addEventListener('paste', function(e){
                e.preventDefault();
                var text = (e.clipboardData || window.clipboardData).getData('text');
                lic.value = fmt(text);
            });
        }
        var toggle = document.getElementById('togglePwdModal');
        var pwd = document.getElementById('adminPwdModal');
        if (toggle && pwd) {
            toggle.addEventListener('click', function(){
                var isText = pwd.type === 'text';
                pwd.type = isText ? 'password' : 'text';
                var icon = toggle.querySelector('i');
                if (icon) { icon.className = isText ? 'bi bi-eye' : 'bi bi-eye-slash'; }
            });
        }
    })();

    document.getElementById('registerForm').addEventListener('submit', function(e) {
        e.preventDefault();

        const btn = document.getElementById('btnRegister');
        const spinner = btn.querySelector('.spinner-border');
        const alertBox = document.getElementById('registerAlert');
        const formData = new FormData(this);

        btn.disabled = true;
        spinner.classList.remove('d-none');
        alertBox.classList.add('d-none');

        fetch('../saas/create_tenant.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(response => response.json())
        .then(data => {
            if (data && data.success) {
                alertBox.className = 'alert alert-success';
                alertBox.textContent = data.message || 'Empresa creada correctamente.';
                alertBox.classList.remove('d-none');
                try {
                    showNewAccountForm();
                    const adminEmail = this.querySelector('input[name="admin_email"]').value.trim();
                    if (emailInput && adminEmail) { emailInput.value = adminEmail; }
                } catch (_e) {}
                try {
                    const modalEl = document.getElementById('registerModal');
                    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
                    setTimeout(function(){
                        modal.hide();
                        setTimeout(function(){
                            if (passwordInput) { passwordInput.focus(); }
                        }, 250);
                    }, 600);
                } catch (_e) {}
                try { this.reset(); } catch (_e) {}
            } else {
                alertBox.className = 'alert alert-danger';
                alertBox.textContent = (data && data.message) ? data.message : 'No se pudo crear la empresa.';
                alertBox.classList.remove('d-none');
            }
        })
        .catch(() => {
            alertBox.className = 'alert alert-danger';
            alertBox.textContent = 'Ocurrió un error inesperado. Inténtalo de nuevo.';
            alertBox.classList.remove('d-none');
        })
        .finally(() => {
            btn.disabled = false;
            spinner.classList.add('d-none');
        });
    });
</script>

</body>
</html>

