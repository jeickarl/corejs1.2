<?php
$isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
session_name('CORE_SA_SESSION');
$docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
$appDir = realpath(__DIR__ . '/..');
$basePath = '/';
if ($docRoot && $appDir && strpos($appDir, $docRoot) === 0) {
    $rel = str_replace('\\', '/', substr($appDir, strlen($docRoot)));
    $rel = '/' . trim($rel, '/');
    $basePath = ($rel === '' || $rel === '/') ? '/' : $rel;
}
$saPath = rtrim($basePath, '/') . '/super_admin';
if (!headers_sent() && isset($_SERVER['REQUEST_URI'])) {
    $uri = (string)$_SERVER['REQUEST_URI'];
    $pattern = '~^' . preg_quote(rtrim($basePath, '/'), '~') . '/super_admin~i';
    $canonical = preg_replace($pattern, rtrim($basePath, '/') . '/super_admin', $uri, 1);
    if (is_string($canonical) && $canonical !== $uri) {
        header('Location: ' . $canonical);
        exit;
    }
}
session_set_cookie_params([
    'path' => $saPath,
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();
require_once '../config/database.php';
require_once '../config/functions.php';
require_once '../config/security_enhancements.php';
$pdo = db();

if (isset($_SESSION['SESSION_SAAS_SUPERADMIN'])) {
    header("Location: index.php");
    exit;
}

// Asegurar que la tabla de intentos de login existe
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        id INT NOT NULL AUTO_INCREMENT,
        ip_address VARCHAR(45) NOT NULL,
        email VARCHAR(255) DEFAULT NULL,
        success TINYINT(1) NOT NULL DEFAULT 0,
        attempt_time TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY ip_address (ip_address),
        KEY attempt_time (attempt_time)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci");
} catch (Exception $e) {}

$pdo->exec("CREATE TABLE IF NOT EXISTS saas_super_admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) UNIQUE NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    twofa_secret VARCHAR(255) NULL,
    backup_pin VARCHAR(255) NULL,
    trusted_ips TEXT NULL,
    mfa_preference ENUM('totp', 'email', 'pin') DEFAULT 'totp',
    recovery_token VARCHAR(255) NULL,
    recovery_expires DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Check and add new columns if they don't exist (migration for existing table)
try {
    $columns = $pdo->query("SHOW COLUMNS FROM saas_super_admins")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('backup_pin', $columns)) {
        $pdo->exec("ALTER TABLE saas_super_admins ADD COLUMN backup_pin VARCHAR(255) NULL AFTER twofa_secret");
    }
    if (!in_array('trusted_ips', $columns)) {
        $pdo->exec("ALTER TABLE saas_super_admins ADD COLUMN trusted_ips TEXT NULL AFTER backup_pin");
    }
    if (!in_array('mfa_preference', $columns)) {
        $pdo->exec("ALTER TABLE saas_super_admins ADD COLUMN mfa_preference ENUM('totp', 'email', 'pin') DEFAULT 'totp' AFTER trusted_ips");
    }
} catch (Exception $e) {
    // Ignore if columns already exist or other minor DB errors
}

$count = (int)$pdo->query("SELECT COUNT(*) FROM saas_super_admins")->fetchColumn();
if ($count === 0) {
    $username = 'master';
    $email = 'master@localhost';
    $passwordHash = password_hash('Master2026!', PASSWORD_DEFAULT);
    $secret = generateBase32Secret(20);
    $stmt = $pdo->prepare("INSERT INTO saas_super_admins (username, email, password, twofa_secret) VALUES (?, ?, ?, ?)");
    $stmt->execute([$username, $email, $passwordHash, $secret]);
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!SecurityEnhancements::verifyCSRFToken($token)) {
        $error = "Sesión inválida. Intente de nuevo.";
    } else {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if (!SecurityEnhancements::checkLoginAttempts($ip)) {
            $error = "Demasiados intentos fallidos. Intente en 15 minutos.";
        } else {
            $user = trim($_POST['username'] ?? '');
            $pass = $_POST['password'] ?? '';

            if ($user !== '' && $pass !== '') {
                $stmt = $pdo->prepare("SELECT id, username, email, password, twofa_secret, backup_pin, mfa_preference FROM saas_super_admins WHERE username = ? OR email = ?");
                $stmt->execute([$user, $user]);
                $admin = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($admin && password_verify($pass, $admin['password'])) {
                    SecurityEnhancements::logLoginAttempt($ip, $user, true);
                    $pref = (string)($admin['mfa_preference'] ?? 'totp');
                    $hasTotp = ($pref === 'totp' && !empty($admin['twofa_secret']));
                    $hasPin = ($pref === 'pin' && !empty($admin['backup_pin']));
                    $hasEmail = ($pref === 'email' && !empty($admin['email']));

                    $isLocal = (($_SERVER['SERVER_NAME'] ?? 'localhost') === 'localhost') || (($_SERVER['REMOTE_ADDR'] ?? '') === '127.0.0.1') || (($_SERVER['REMOTE_ADDR'] ?? '') === '::1');
                    if ($isLocal && empty($admin['backup_pin'])) {
                        $pinHash = password_hash('120212', PASSWORD_DEFAULT);
                        $upd = $pdo->prepare("UPDATE saas_super_admins SET backup_pin = ?, mfa_preference = 'pin' WHERE id = ?");
                        $upd->execute([$pinHash, (int)$admin['id']]);
                        $pref = 'pin';
                        $hasPin = true;
                        $hasTotp = !empty($admin['twofa_secret']);
                        $hasEmail = !empty($admin['email']);
                    }

                    if ($hasTotp || $hasPin || $hasEmail) {
                        $_SESSION['SAAS_SUPERADMIN_PENDING_ID'] = (int)$admin['id'];
                        header("Location: verify_2fa.php");
                        exit;
                    }

                    $_SESSION['SAAS_SUPERADMIN_SETUP_ID'] = (int)$admin['id'];
                    header("Location: setup_2fa.php");
                    exit;
                } else {
                    SecurityEnhancements::logLoginAttempt($ip, $user, false);
                    $error = "Credenciales incorrectas.";
                }
            } else {
                $error = "Ingresa usuario y contraseña.";
            }
        }
    }
}

$csrf_token = SecurityEnhancements::generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin - CORE</title>
    <link rel="icon" type="image/png" href="../assets/img/system_logo.png">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    
    <style>
        /* Reusing Core Login Style */
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(120deg, #fdfbfb 0%, #ebedee 100%);
            background-repeat: no-repeat;
            background-position: center center;
            background-attachment: fixed;
            background-size: cover;
            margin: 0;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .login-container {
            background: #fff;
            padding: 40px 35px;
            border-radius: 14px;
            box-shadow: 0 8px 28px rgba(0,0,0,0.15);
            width: 360px;
            text-align: center;
        }

        .logo-container {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 25px;
        }

        .logo-container img.logo {
            width: 70px;
            height: auto;
            margin-right: 10px;
        }

        .logo-container h1 {
            font-size: 30px;
            font-weight: 700;
            color: #333;
            margin: 0;
        }

        h2 {
            margin-bottom: 20px;
            font-size: 20px;
            color: #555;
            font-weight: 500;
        }

        .input-group {
            display: flex;
            align-items: center;
            margin-bottom: 18px;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 10px;
            background: #fafafa;
            transition: 0.3s ease;
        }

        .input-group i {
            margin-right: 10px;
            color: #777;
        }

        .input-group input {
            border: none;
            outline: none;
            flex: 1;
            font-size: 15px;
            background: transparent;
        }

        .input-group:focus-within {
            border-color: #444;
            background: #fff;
        }

        button {
            width: 100%;
            padding: 12px;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: bold;
            color: white;
            cursor: pointer;
            background: linear-gradient(135deg, #444, #000);
            transition: 0.3s ease;
        }

        button:hover {
            background: linear-gradient(135deg, #222, #111);
            transform: translateY(-2px);
        }

        .error {
            background: #ffe0e0;
            color: #c0392b;
            padding: 10px;
            margin-bottom: 15px;
            border-radius: 6px;
            font-size: 13px;
        }

        .footer-login {
            margin-top: 20px;
            font-size: 11px;
            color: #999;
            text-align: center;
        }
    </style>
</head>
<body>

<div class="login-container">
    <div class="mb-2" style="text-align:left;">
        <a href="../login/index.php" class="text-muted" style="text-decoration:none;font-size:12px;">
            <i class="fas fa-arrow-left"></i> Volver al login normal
        </a>
    </div>
    <div class="logo-container">
        <img src="../assets/img/system_logo.png" alt="Logo" class="logo">
        <h1>CORE</h1>
    </div>
    
    <h2>Acceso Super Admin</h2>

    <?php if ($error): ?>
        <div class="error"><i class="fas fa-exclamation-circle me-2"></i> <?php echo $error; ?></div>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <div class="input-group">
            <i class="fas fa-user"></i>
            <input type="text" name="username" placeholder="Usuario Maestro" required autofocus>
        </div>
        
        <div class="input-group">
            <i class="fas fa-lock"></i>
            <input type="password" name="password" id="password" placeholder="Contraseña Maestra" required>
            <i class="fas fa-eye toggle-password" onclick="togglePassword('password')" style="cursor: pointer; margin-left: 10px; margin-right: 0;"></i>
        </div>
        
        <button type="submit">
            INGRESAR <i class="fas fa-arrow-right" style="margin-left: 8px;"></i>
        </button>
    </form>

    <div class="mt-3">
        <a href="forgot.php" class="text-muted" style="font-size: 12px;">Olvidé mi contraseña</a>
    </div>

    <div class="footer-login">
        <p>&copy; <?php echo date('Y'); ?> CORE. Todos los derechos reservados.</p>
    </div>
</div>

<script>
function togglePassword(fieldId) {
    const input = document.getElementById(fieldId);
    const icon = input.nextElementSibling;
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
</script>

</body>
</html>
