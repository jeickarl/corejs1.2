<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo disponible por CLI.');
}
require_once __DIR__ . '/config/env_loader.php';
require_once __DIR__ . '/config/provisioning_service.php';

$license = $argv[1] ?? 'XTR2-ENBH-28VK';
$company = $argv[2] ?? 'PRUEBA001';
$adminName = $argv[3] ?? 'JUAN';
$email = $argv[4] ?? 'admin2@core.com';
$pass = $argv[5] ?? 'password';

try {
    $r = ProvisioningService::provisionFromMasterLicense($license, $company, $adminName, $email, $pass);
    echo "OK\n";
    echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    echo "ERR\n";
    echo $e->getMessage();
}
