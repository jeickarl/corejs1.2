<?php
require_once __DIR__ . '/config/init_public.php';
$csrfToken = SecurityEnhancements::generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Core | Gestión Inteligente para tu Negocio</title>
    <link rel="icon" type="image/png" href="assets/img/system_logo.png">
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    
    <style>
        :root {
            --primary-color: #0d6efd;
            --secondary-color: #6c757d;
            --dark-color: #000000;
            --light-bg: #f8f9fa;
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            background-color: var(--light-bg);
            overflow-x: hidden;
        }

        /* General Styling */
        .rounded-custom {
            border-radius: 20px !important;
        }
        
        .rounded-btn {
            border-radius: 50px !important;
        }
        
        .shadow-soft {
            box-shadow: 0 10px 30px rgba(0,0,0,0.08) !important;
        }

        /* Navbar */
        .navbar {
            padding-top: 1rem;
            padding-bottom: 1rem;
            background-color: rgba(255, 255, 255, 0.95) !important;
            backdrop-filter: blur(10px);
        }
        
        .navbar-brand {
            font-weight: 700;
            font-size: 1.8rem;
            color: var(--dark-color) !important;
            letter-spacing: -0.5px;
        }
        
        .nav-link {
            font-weight: 500;
            color: #555 !important;
            margin: 0 10px;
            transition: color 0.3s;
        }
        
        .nav-link:hover {
            color: var(--primary-color) !important;
        }

        /* Hero Section */
        .hero-section {
            background: white;
            padding: 120px 0 80px;
            position: relative;
            overflow: hidden;
        }
        
        .hero-blob {
            position: absolute;
            top: -50%;
            right: -20%;
            width: 800px;
            height: 800px;
            background: linear-gradient(45deg, #e0f2fe, #f0f9ff);
            border-radius: 50%;
            z-index: 0;
            filter: blur(80px);
            opacity: 0.7;
        }

        /* Cards */
        .feature-card {
            border: none;
            background: white;
            padding: 2.5rem;
            border-radius: 24px;
            transition: all 0.3s cubic-bezier(0.165, 0.84, 0.44, 1);
            height: 100%;
            position: relative;
            z-index: 1;
        }
        
        .feature-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
        }
        
        .feature-icon {
            width: 60px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 16px;
            background: rgba(13, 110, 253, 0.1);
            color: var(--primary-color);
            font-size: 1.8rem;
            margin-bottom: 1.5rem;
            margin-left: auto;
            margin-right: auto;
        }

        /* CTA Section */
        .cta-section {
            background-color: var(--dark-color);
            color: white;
            border-radius: 30px;
            margin: 40px 20px;
            padding: 80px 40px;
            position: relative;
            overflow: hidden;
        }

        /* Footer */
        footer {
            background-color: white;
            padding: 60px 0 30px;
            border-top: 1px solid #eee;
        }
        
        /* Modal Customization */
        .modal-content {
            border: none;
            border-radius: 24px;
            overflow: hidden;
        }
        
        .modal-header {
            background-color: var(--dark-color);
            color: white;
            border: none;
            padding: 1.5rem 2rem;
        }
        
        .modal-body {
            padding: 2rem;
        }
        
        .form-control {
            border-radius: 12px;
            padding: 12px 15px;
            border: 1px solid #e0e0e0;
            background-color: #fcfcfc;
        }
        
        .form-control:focus {
            box-shadow: 0 0 0 4px rgba(13, 110, 253, 0.15);
            border-color: var(--primary-color);
            background-color: white;
        }
    </style>
</head>
<body>

    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg fixed-top shadow-sm">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="#">
                <img src="assets/img/system_logo.png" alt="Logo" style="height: 45px;" class="me-2">
                Core
            </a>
            <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto ms-lg-4">
                    <li class="nav-item"><a class="nav-link" href="#features">Características</a></li>
                    <li class="nav-item"><a class="nav-link" href="#pricing">Planes</a></li>
                    <li class="nav-item"><a class="nav-link" href="#contact">Contacto</a></li>
                </ul>
                <div class="d-flex gap-3 align-items-center">
                    <a href="login/index.php" class="text-decoration-none text-dark fw-medium">Iniciar Sesión</a>
                    <button onclick="openRegisterModal()" class="btn btn-dark rounded-btn px-4 py-2 fw-medium shadow-sm">Registrar Empresa</button>
                </div>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero-section text-center d-flex align-items-center" style="min-height: 90vh;">
        <div class="hero-blob"></div>
        <div class="container position-relative" style="z-index: 1;">
            <div class="row justify-content-center">
                <div class="col-lg-9">
                    <span class="badge bg-light text-primary border rounded-pill px-3 py-2 mb-4 fw-medium">v1.1 Beta</span>
                    <h1 class="display-3 fw-bold mb-4 text-dark" style="letter-spacing: -1px; line-height: 1.1;">
                        Sistema de gestión para tu <span class="text-primary">Taller de Reparaciones</span>
                    </h1>
                    <p class="lead text-secondary mb-5 mx-auto" style="max-width: 700px;">
                        Gestiona órdenes, inventario y clientes en una sola plataforma unificada. 
                        Diseñado meticulosamente para ser bonito, rápido y eficiente.
                    </p>
                    <div class="d-flex justify-content-center gap-3 flex-wrap">
                        <button onclick="openRegisterModal()" class="btn btn-primary btn-lg rounded-btn px-5 py-3 shadow-soft fw-bold">Comenzar Gratis</button>
                        <a href="#features" class="btn btn-outline-secondary btn-lg rounded-btn px-5 py-3 fw-bold bg-white">Ver Demo</a>
                    </div>
                    
                    <div class="mt-5 pt-4">
                        <div class="rounded-custom shadow-soft border overflow-hidden">
                            <img src="assets/img/dashboard_preview.png" class="d-block w-100" alt="Dashboard">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section id="features" class="py-5 bg-light">
        <div class="container py-5">
            <div class="text-center mb-5">
                <h2 class="fw-bold display-6 mb-3">Todo en un solo lugar</h2>
                <p class="text-muted lead">Herramientas poderosas con un diseño que te encantará usar.</p>
            </div>
            <div class="row g-4">
                <!-- Feature 1 -->
                <div class="col-md-4">
                    <div class="card feature-card shadow-soft">
                        <div class="card-body text-center">
                            <div class="feature-icon">
                                <i class="bi bi-clipboard-check"></i>
                            </div>
                            <h5 class="card-title fw-bold mb-3">Gestión de Órdenes</h5>
                            <p class="card-text text-muted">Control total desde la recepción hasta la entrega. Estados personalizables y seguimiento en tiempo real.</p>
                        </div>
                    </div>
                </div>
                <!-- Feature 2 -->
                <div class="col-md-4">
                    <div class="card feature-card shadow-soft">
                        <div class="card-body text-center">
                            <div class="feature-icon">
                                <i class="bi bi-box-seam"></i>
                            </div>
                            <h5 class="card-title fw-bold mb-3">Inventario Inteligente</h5>
                            <p class="card-text text-muted">Stock sincronizado, alertas de bajo inventario.</p>
                        </div>
                    </div>
                </div>
                <!-- Feature 3 -->
                <div class="col-md-4">
                    <div class="card feature-card shadow-soft">
                        <div class="card-body text-center">
                            <div class="feature-icon">
                                <i class="bi bi-people"></i>
                            </div>
                            <h5 class="card-title fw-bold mb-3">CRM de Clientes</h5>
                            <p class="card-text text-muted">Conoce a tus clientes. Historial completo de servicios, equipos y preferencias de contacto.</p>
                        </div>
                    </div>
                </div>
                <!-- Feature 4 -->
                <div class="col-md-4">
                    <div class="card feature-card shadow-soft">
                        <div class="card-body text-center">
                            <div class="feature-icon">
                                <i class="bi bi-graph-up-arrow"></i>
                            </div>
                            <h5 class="card-title fw-bold mb-3">Finanzas Claras</h5>
                            <p class="card-text text-muted">Reportes detallados de ingresos y gastos. Toma decisiones basadas en datos reales.</p>
                        </div>
                    </div>
                </div>
                <!-- Feature 5 -->
                <div class="col-md-4">
                    <div class="card feature-card shadow-soft">
                        <div class="card-body text-center">
                            <div class="feature-icon">
                                <i class="bi bi-whatsapp"></i>
                            </div>
                            <h5 class="card-title fw-bold mb-3">Notificaciones</h5>
                            <p class="card-text text-muted">Envía actualizaciones de los estados por WhatsApp.</p>
                        </div>
                    </div>
                </div>
                <!-- Feature 6 -->
                <div class="col-md-4">
                    <div class="card feature-card shadow-soft">
                        <div class="card-body text-center">
                            <div class="feature-icon">
                                <i class="bi bi-shield-lock"></i>
                            </div>
                            <h5 class="card-title fw-bold mb-3">Seguridad y Backup</h5>
                            <p class="card-text text-muted">Tus datos están protegidos. Copias de seguridad automáticas y control de acceso por roles.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Pricing/CTA Section -->
    <div class="container">
        <section id="pricing" class="cta-section text-center">
            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <h2 class="fw-bold mb-4">Listo para transformar tu negocio?</h2>
                    <p class="lead mb-5 opacity-75">Únete a cientos de talleres que ya usan Core para gestionar sus operaciones diarias con estilo y eficiencia.</p>
                    <div class="d-flex justify-content-center gap-3 flex-wrap">
                        <button onclick="openRegisterModal()" class="btn btn-light btn-lg rounded-btn px-5 py-3 text-dark fw-bold shadow-lg">Registrar mi Empresa Ahora</button>
                        <a href="mailto:ventas@core.com" class="btn btn-outline-light btn-lg rounded-btn px-5 py-3 fw-bold">Contactar Ventas</a>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <!-- Footer -->
    <footer>
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-6 text-center text-md-start mb-3 mb-md-0">
                    <div class="d-flex align-items-center justify-content-center justify-content-md-start mb-2">
                        <img src="assets/img/system_logo.png" alt="Logo" style="height: 30px;" class="me-2 grayscale">
                        <span class="fw-bold fs-5">Core</span>
                    </div>
                    <p class="text-muted small mb-0">&copy; <?php echo date('Y'); ?> Core System. Todos los derechos reservados.</p>
                </div>
                <div class="col-md-6 text-center text-md-end">
                    <a href="#" class="text-muted me-3 text-decoration-none"><i class="bi bi-facebook fs-5"></i></a>
                    <a href="#" class="text-muted me-3 text-decoration-none"><i class="bi bi-instagram fs-5"></i></a>
                    <a href="#" class="text-muted text-decoration-none"><i class="bi bi-twitter-x fs-5"></i></a>
                </div>
            </div>
        </div>
    </footer>

    <!-- Registration Modal -->
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
                                <img src="assets/img/system_logo.png" alt="Logo" class="img-fluid mb-3" style="max-height: 100px;">
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
                                
                                <form id="registerForm">
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
                                            <input type="email" name="admin_email" class="form-control border-start-0 ps-0" required placeholder="admin@empresa.com">
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
                                            <input type="password" name="admin_password" class="form-control border-start-0 ps-0" required placeholder="********" id="adminPwdModal">
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        function openRegisterModal() {
            const myModal = new bootstrap.Modal(document.getElementById('registerModal'));
            myModal.show();
        }

        (function(){
            var lic = document.querySelector('input[name="license_code"]');
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
            
            // UI State: Loading
            btn.disabled = true;
            spinner.classList.remove('d-none');
            alertBox.classList.add('d-none');
            
            fetch('saas/create_tenant.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: '¡Registro Exitoso!',
                        text: data.message,
                        confirmButtonText: 'Ir al Login',
                        allowOutsideClick: false
                    }).then((result) => {
                        if (result.isConfirmed) {
                            window.location.href = 'login/index.php';
                        }
                    });
                    this.reset();
                } else {
                    alertBox.className = 'alert alert-danger';
                    alertBox.textContent = data.message;
                    alertBox.classList.remove('d-none');
                }
            })
            .catch(error => {
                console.error('Error:', error);
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
