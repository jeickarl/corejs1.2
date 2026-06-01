<?php
/**
 * Script para registrar una nueva empresa (Tenant) en el sistema SaaS.
 * Valida licencia antes de crear la infraestructura.
 */

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/security_enhancements.php';

$isAjax = isset($_POST['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

// Protección: la vista HTML es para Super Admin. El alta vía AJAX (landing) se permite con licencia válida.
if (!isset($_SESSION['SAAS_SUPERADMIN_ID']) && !($isAjax && $_SERVER["REQUEST_METHOD"] === "POST")) {
    header('Location: ../super_admin/login.php');
    exit;
}

function respondJson($success, $message, $messageType = 'danger') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => (bool)$success,
        'message' => (string)$message,
        'messageType' => (string)$messageType
    ]);
    exit;
}

function ensureSignupAttemptsTable(PDO $pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS signup_attempts (
        id INT NOT NULL AUTO_INCREMENT,
        ip_address VARCHAR(45) NOT NULL,
        email VARCHAR(255) DEFAULT NULL,
        success TINYINT(1) NOT NULL DEFAULT 0,
        attempt_time TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY ip_address (ip_address),
        KEY attempt_time (attempt_time)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci");
}

function canAttemptSignup(PDO $pdo, $ip, $windowSeconds, $maxAttempts) {
    $pdo->prepare("DELETE FROM signup_attempts WHERE attempt_time < DATE_SUB(NOW(), INTERVAL ? SECOND)")->execute([(int)$windowSeconds]);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM signup_attempts WHERE ip_address = ? AND attempt_time > DATE_SUB(NOW(), INTERVAL ? SECOND)");
    $stmt->execute([(string)$ip, (int)$windowSeconds]);
    $count = (int)$stmt->fetchColumn();
    return $count < (int)$maxAttempts;
}

function logSignupAttempt(PDO $pdo, $ip, $email, $success) {
    $stmt = $pdo->prepare("INSERT INTO signup_attempts (ip_address, email, success, attempt_time) VALUES (?, ?, ?, NOW())");
    $stmt->execute([(string)$ip, $email !== '' ? (string)$email : null, $success ? 1 : 0]);
}

$message = "";
$messageType = "info"; // info, success, danger

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $companyName = trim($_POST['company_name']);
    $adminEmail = trim($_POST['admin_email']);
    $adminName = trim($_POST['admin_name'] ?? '');
    $adminPassword = $_POST['admin_password'];
    $licenseCode = strtoupper(trim($_POST['license_code']));

    if ($isAjax) {
        $token = $_POST['csrf_token'] ?? '';
        if (!SecurityEnhancements::verifyCSRFToken($token)) {
            respondJson(false, 'Sesión inválida. Recarga la página e inténtalo de nuevo.');
        }
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            ensureSignupAttemptsTable($pdo);
            if (!canAttemptSignup($pdo, $ip, 900, 20)) {
                respondJson(false, 'Demasiados intentos. Inténtalo de nuevo en unos minutos.');
            }
        } catch (Throwable $e) {
        }
    }

    if (empty($companyName) || empty($adminEmail) || empty($adminPassword) || empty($licenseCode) || empty($adminName)) {
        $message = "Todos los campos son obligatorios, incluyendo el código de licencia.";
        $messageType = "danger";
    } elseif (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        $message = "El formato del correo electrónico no es válido.";
        $messageType = "danger";
    } else {
        try {
            $perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
            if (!$perDatabase) {
                $saasMode = getenv('SAAS_DB_MODE');
                $saasMode = is_string($saasMode) ? strtolower(trim($saasMode)) : '';
                $perDatabase = ($saasMode === 'per_database' || $saasMode === 'per-db' || $saasMode === 'perdb');
            }
            if ($perDatabase) {
                require_once __DIR__ . '/../config/provisioning_service.php';
                $skipLegacySingleDb = true;

                try {
                    $result = ProvisioningService::provisionFromMasterLicense($licenseCode, $companyName, $adminName, $adminEmail, $adminPassword);
                    $message = "¡Registro exitoso! Empresa creada y lista para operar.";
                    $messageType = "success";
                    if ($isAjax) {
                        try {
                            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                            logSignupAttempt($pdo, $ip, $adminEmail, true);
                        } catch (Throwable $e) {
                        }
                    }
                } catch (Throwable $e) {
                    if (isset($_SESSION['SAAS_SUPERADMIN_ID'])) {
                        $message = "Error: " . $e->getMessage();
                    } else {
                        $m = (string)$e->getMessage();
                        try {
                            error_log('[SAAS] create_tenant ajax error: ' . $m);
                        } catch (Throwable $eLog) {
                        }
                        $safeMessages = [
                            'El código de licencia es inválido o ya fue utilizado.',
                            'El email ya está registrado en otra empresa.',
                            'No hay bases de datos disponibles. Intenta más tarde.',
                            'Demasiados intentos. Inténtalo de nuevo en unos minutos.',
                            'Formato de licencia inválido.',
                        ];
                        $message = "Error: " . (in_array($m, $safeMessages, true) ? $m : 'No se pudo completar el registro. Intenta más tarde.');
                    }
                    $messageType = "danger";
                    if ($isAjax) {
                        try {
                            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                            logSignupAttempt($pdo, $ip, $adminEmail, false);
                        } catch (Throwable $e2) {
                        }
                    }
                }

                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => $messageType === 'success',
                        'message' => $message,
                        'messageType' => $messageType
                    ]);
                    exit;
                }
            }

            if (!empty($skipLegacySingleDb)) {
                throw new Exception('__SKIP_LEGACY_SINGLE_DB__');
            }

            if (!preg_match('/^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $licenseCode)) {
                throw new Exception(isset($_SESSION['SAAS_SUPERADMIN_ID']) ? 'Formato de licencia inválido.' : 'El código de licencia es inválido o ya fue utilizado.');
            }

            // --- 1. Validar Licencia ---
            $stmt = $pdo->prepare("SELECT id, status, license_type, expires_at FROM saas_licenses WHERE license_code = ?");
            $stmt->execute([$licenseCode]);
            $license = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$license || $license['status'] !== 'active') {
                throw new Exception(isset($_SESSION['SAAS_SUPERADMIN_ID'])
                    ? (!$license ? "El código de licencia no existe." : "Esta licencia ya ha sido utilizada o está inactiva.")
                    : "El código de licencia es inválido o ya fue utilizado.");
            }
            if (!empty($license['license_type']) && $license['license_type'] === 'trial') {
                if (empty($license['expires_at']) || strtotime($license['expires_at']) < time()) {
                    throw new Exception(isset($_SESSION['SAAS_SUPERADMIN_ID']) ? "La licencia de prueba ha expirado." : "El código de licencia es inválido o ya fue utilizado.");
                }
            }

            // --- 2. Validar Email Único ---
            $stmt = $pdo->prepare("SELECT id FROM saas_users_lookup WHERE email = ?");
            $stmt->execute([$adminEmail]);
            if ($stmt->fetch()) {
                throw new Exception("El email ya está registrado en otra empresa.");
            }

            // --- 3. Preparar Datos del Tenant ---
            // Generar slug único para el tenant (usado en URLs o referencias internas)
            $cleanName = preg_replace('/[^a-z0-9]/', '', strtolower($companyName));
            $slug = $cleanName . "_" . substr(uniqid(), -4);
            
            // --- INICIO DE TRANSACCIÓN ---
            $pdo->beginTransaction();

            // 4. Registrar Tenant en Tabla Única (reutiliza huecos desde id=1)
            $freeId = getMinimalFreeTenantId($pdo, 1);
            if ($freeId) {
                // Limpiar restos de uploads/storage si existieran para ese ID
                try { deletePathRecursive(getTenantUploadsFsById((int)$freeId)); } catch (Throwable $e) {}
                try { deletePathRecursive(getTenantStorageFsById((int)$freeId)); } catch (Throwable $e) {}
                $stmt = $pdo->prepare("INSERT INTO tenants (id, company_name, slug, status, created_at) VALUES (?, ?, ?, 'active', NOW())");
                $stmt->execute([$freeId, $companyName, $slug]);
                $tenantId = (int)$freeId;
            } else {
                $stmt = $pdo->prepare("INSERT INTO tenants (company_name, slug, status, created_at) VALUES (?, ?, 'active', NOW())");
                $stmt->execute([$companyName, $slug]);
                $tenantId = (int)$pdo->lastInsertId();
            }

            // 5. Registrar Usuario Admin (Vinculado al Tenant)
            $passwordHash = password_hash($adminPassword, PASSWORD_DEFAULT);
            $stmtUser = $pdo->prepare("INSERT INTO users (tenant_id, name, email, password, role, active, created_at) VALUES (?, ?, ?, ?, 'admin', 1, NOW())");
            $stmtUser->execute([$tenantId, $adminName, $adminEmail, $passwordHash]);
            $userId = $pdo->lastInsertId();

            // 6. Configuración Inicial de la Empresa (Company Settings)
            $stmtSettings = $pdo->prepare("INSERT INTO company_settings (tenant_id, company_name, company_email, company_address, default_currency, date_format, number_format, created_at, updated_at) VALUES (?, ?, ?, 'Dirección de tu empresa', 'USD', 'd/m/Y', 'en_US', NOW(), NOW())");
            $stmtSettings->execute([$tenantId, $companyName, $adminEmail]);

            // 6.1. Identidad de Empresa para UI (Company Config) — usado por la mayoría de pantallas y el portal
            try {
                $stmtCfg = $pdo->prepare("INSERT INTO company_config (tenant_id, company_name, company_email, company_address, created_at, updated_at) VALUES (?, ?, ?, 'Dirección de tu empresa', NOW(), NOW())");
                $stmtCfg->execute([$tenantId, $companyName, $adminEmail]);
            } catch (Throwable $e) {
                // silencioso: si la tabla no existe o falla, no interrumpe el alta
            }

            // 7. Marcar Licencia como Usada (Si aplica)
            // Nota: saas_licenses es tabla global, usamos tenant_id para referencia
            $stmtLicense = $pdo->prepare("UPDATE saas_licenses SET status = 'used', tenant_id = ?, used_at = NOW() WHERE id = ?");
            $stmtLicense->execute([$tenantId, $license['id']]);

            // 8. Crear Directorios de Almacenamiento
            $storagePath = __DIR__ . '/../storage/tenants/' . $tenantId;
            if (!file_exists($storagePath)) {
                mkdir($storagePath, 0777, true);
                mkdir($storagePath . '/logos', 0777, true);
                mkdir($storagePath . '/brands', 0777, true);
                mkdir($storagePath . '/orders', 0777, true);
            }

            // Confirmar Transacción
            $pdo->commit();
            
            try {
                ensureDefaultOrderStatuses($tenantId);
                ensureDefaultTenantCatalogs($tenantId);
                ensureBrandsFromGlobalAssets($tenantId);
            } catch (Throwable $e) {}
            
            // --- 9. Enviar Correo de Bienvenida (Usando Plantilla) ---
            // Simular sesión de tenant para cfg_get
            // Nota: cfg_get lee de system_config. Si no hay config para este tenant, usará defaults.
            // Idealmente deberíamos insertar configs base en system_config para el nuevo tenant aquí.
            
            // ... Resto del código de envío de correo igual ...
            $loginUrl = getSystemBaseUrl() . 'index.php';
            
            // Cargar plantillas de configuración
            $defaults = [
                'email_tpl_welcome_subject' => 'Bienvenido a CORE - Registro Exitoso',
                'email_tpl_welcome_preheader' => 'Tus credenciales de acceso',
                'email_tpl_welcome_html' => '<p>Bienvenido {{company_name}}. Tu usuario es {{admin_email}}</p>' // Fallback mínimo
            ];
            
            $subject = cfg_get('email_tpl_welcome_subject', $defaults['email_tpl_welcome_subject']);
            $preheader = cfg_get('email_tpl_welcome_preheader', $defaults['email_tpl_welcome_preheader']);
            $html = cfg_get('email_tpl_welcome_html', $defaults['email_tpl_welcome_html']);

            // Variables globales y específicas
            $support = cfg_get('smtp_from_email', 'soporte@core.local');
            $brandName = cfg_get('smtp_from_name', 'Core');
            $logoUrl = getSystemBaseUrl() . 'assets/img/system_logo.png';
            $brandLogoEnabled = cfg_get('email_brand_logo_enabled', '0') === '1';

            $vars = [
                '{{company_name}}' => htmlspecialchars($companyName),
                '{{admin_email}}' => $adminEmail,
                '{{admin_password}}' => $adminPassword,
                '{{login_url}}' => $loginUrl,
                '{{support_email}}' => $support,
                '{{brand_name}}' => $brandName,
                '{{brand_logo_url}}' => $logoUrl
            ];

            // Reemplazar variables
            $emailBody = strtr($html, $vars);
            if (!$brandLogoEnabled) {
                $emailBody = preg_replace('/<img[^>]*(brand_logo_url|system_logo\.png)[^>]*>/i', '', $emailBody);
            }
            
            // Insertar preheader oculto si existe
            if ($preheader) {
                $hiddenPreheader = '<span style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">' . htmlspecialchars($preheader) . '</span>';
                $emailBody = $hiddenPreheader . $emailBody;
            }
            
            // Convertir a texto plano para AltBody
            $altBody = strip_tags(str_replace(['<br>', '</p>'], ["\n", "\n\n"], $emailBody));

            // Intentar enviar el correo
            $emailSent = sendSystemEmail($adminEmail, $subject, $emailBody, true, $altBody);
            $emailStatusMsg = $emailSent ? " y se ha enviado un correo de confirmación" : ". (Nota: No se pudo enviar el correo de bienvenida)";

            $message = "¡Registro exitoso! Empresa creada, licencia activada" . $emailStatusMsg . ".";
            $messageType = "success";
            if ($isAjax) {
                try {
                    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                    logSignupAttempt($pdo, $ip, $adminEmail, true);
                } catch (Throwable $e) {
                }
            }

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e->getMessage() !== '__SKIP_LEGACY_SINGLE_DB__') {
                $message = "Error: " . $e->getMessage();
                $messageType = "danger";
                if ($isAjax) {
                    try {
                        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                        logSignupAttempt($pdo, $ip, $adminEmail, false);
                    } catch (Throwable $e2) {
                    }
                }
            }
        }

        // Return JSON for AJAX requests
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => $messageType === 'success',
                'message' => $message,
                'messageType' => $messageType
            ]);
            exit;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Registro de Nueva Empresa</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card shadow">
                    <div class="card-header bg-success text-white">
                        <h4 class="mb-0">Registrar Nueva Empresa</h4>
                    </div>
                    <div class="card-body">
                        <?php if ($message): ?>
                            <div class="alert alert-<?php echo $messageType; ?>"><?php echo $message; ?></div>
                        <?php endif; ?>

                        <?php if ($messageType !== 'success'): ?>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(SecurityEnhancements::generateCSRFToken()); ?>">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Código de Licencia</label>
                                <input type="text" name="license_code" class="form-control" required placeholder="XXXX-XXXX-XXXX" value="<?php echo isset($_POST['license_code']) ? htmlspecialchars($_POST['license_code']) : ''; ?>">
                                <small class="text-muted">Ingresa el código de licencia que adquiriste.</small>
                            </div>
                            <hr>
                            <div class="mb-3">
                                <label class="form-label">Nombre de la Empresa</label>
                                <input type="text" name="company_name" class="form-control" required placeholder="Ej. Tienda Juan" value="<?php echo isset($_POST['company_name']) ? htmlspecialchars($_POST['company_name']) : ''; ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Email del Administrador</label>
                                <input type="email" name="admin_email" class="form-control" required placeholder="admin@empresa.com" value="<?php echo isset($_POST['admin_email']) ? htmlspecialchars($_POST['admin_email']) : ''; ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Nombre del Usuario Admin</label>
                                <input type="text" name="admin_name" class="form-control" required placeholder="Ej. Juan Pérez" value="<?php echo isset($_POST['admin_name']) ? htmlspecialchars($_POST['admin_name']) : ''; ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Contraseña</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-lock"></i></span>
                                    <input type="password" name="admin_password" class="form-control" required id="adminPwdPage">
                                    <button class="btn btn-outline-secondary" type="button" id="togglePwdPage"><i class="bi bi-eye"></i></button>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-success w-100">Registrar y Activar</button>
                        </form>
                        <?php else: ?>
                            <div class="text-center">
                                <p class="lead">Tu empresa ha sido configurada correctamente.</p>
                                <a href="../index.php" class="btn btn-primary">Ir al Login</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="mt-3 text-center">
                    <a href="../index.php" class="text-decoration-none">Volver al Inicio</a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
<script>
try {
    var t = document.getElementById('togglePwdPage');
    var p = document.getElementById('adminPwdPage');
    if (t && p) {
        t.addEventListener('click', function(){
            var isText = p.type === 'text';
            p.type = isText ? 'password' : 'text';
            var i = t.querySelector('i');
            if (i) { i.className = isText ? 'bi bi-eye' : 'bi bi-eye-slash'; }
        });
    }
} catch(e) {}
</script>
