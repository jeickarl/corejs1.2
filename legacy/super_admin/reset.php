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
session_set_cookie_params([
    'path' => $saPath,
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();
require_once '../config/database.php';

$pdo = db();
$token = $_GET['token'] ?? '';
$error = '';
$info = '';

if ($token === '') {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm'] ?? '';
    if (strlen($password) < 8 || $password !== $confirm) {
        $error = "La contraseña debe tener al menos 8 caracteres y coincidir.";
    } else {
        $stmt = $pdo->prepare("SELECT id FROM saas_super_admins WHERE recovery_token = ? AND recovery_expires >= NOW()");
        $stmt->execute([$token]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($admin) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $upd = $pdo->prepare("UPDATE saas_super_admins SET password = ?, recovery_token = NULL, recovery_expires = NULL WHERE id = ?");
            $upd->execute([$hash, (int)$admin['id']]);
            $info = "Contraseña actualizada. Ahora puedes iniciar sesión.";
        } else {
            $error = "Token inválido o expirado.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Restablecer Contraseña</title>
    <link rel="icon" type="image/png" href="../assets/img/system_logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background:#f8f9fa; display:flex; align-items:center; justify-content:center; height:100vh; margin:0; }
        .box { background:#fff; padding:30px; border-radius:14px; box-shadow:0 8px 28px rgba(0,0,0,0.15); width:380px; }
    </style>
</head>
<body>
    <div class="box">
        <div class="mb-2">
            <a href="../login/index.php" class="text-muted" style="text-decoration:none;font-size:12px;">
                <i class="fas fa-arrow-left"></i> Volver al login normal
            </a>
        </div>
        <h5 class="mb-3">Restablecer Contraseña</h5>
        <?php if ($error): ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>
        <?php if ($info): ?><div class="alert alert-success"><?php echo $info; ?></div><?php endif; ?>
        <form method="post">
            <div class="mb-3">
                <label class="form-label">Nueva contraseña</label>
                <input type="password" name="password" class="form-control" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Confirmar contraseña</label>
                <input type="password" name="confirm" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-dark w-100">Actualizar</button>
        </form>
        <div class="mt-3 text-center">
            <a href="login.php" class="text-muted">Volver al login</a>
        </div>
    </div>
</body>
</html>
