<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Solo disponible por CLI.\n";
    exit(1);
}

require_once __DIR__ . '/../config/env_loader.php';

function out(string $line): void
{
    echo $line . PHP_EOL;
}

function parseSqlValues(string $valuesRaw): array
{
    $s = trim($valuesRaw);
    if ($s !== '' && $s[0] === '(' && substr($s, -1) === ')') {
        $s = substr($s, 1, -1);
    }

    $out = [];
    $buf = '';
    $inQuote = false;
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $ch = $s[$i];
        if ($inQuote) {
            if ($ch === "\\") {
                $buf .= $ch;
                if ($i + 1 < $len) {
                    $buf .= $s[$i + 1];
                    $i++;
                }
                continue;
            }
            if ($ch === "'") {
                if ($i + 1 < $len && $s[$i + 1] === "'") {
                    $buf .= "''";
                    $i++;
                    continue;
                }
                $inQuote = false;
                $buf .= $ch;
                continue;
            }
            $buf .= $ch;
            continue;
        }

        if ($ch === "'") {
            $inQuote = true;
            $buf .= $ch;
            continue;
        }

        if ($ch === ',') {
            $out[] = trim($buf);
            $buf = '';
            continue;
        }

        $buf .= $ch;
    }
    if (trim($buf) !== '' || $valuesRaw !== '') {
        $out[] = trim($buf);
    }
    return $out;
}

function decodeSqlLiteral(string $v): mixed
{
    $v = trim($v);
    if ($v === '' || strtoupper($v) === 'NULL') {
        return null;
    }
    if ($v[0] === "'" && substr($v, -1) === "'") {
        $inner = substr($v, 1, -1);
        $inner = str_replace("''", "'", $inner);
        $inner = preg_replace('/\\\\(.)/s', '$1', $inner);
        return $inner;
    }
    if (is_numeric($v)) {
        return (strpos($v, '.') !== false) ? (float)$v : (int)$v;
    }
    return $v;
}

function normalizeKey(string $s): string
{
    $s = trim($s);
    $s = mb_strtolower($s, 'UTF-8');
    $s = preg_replace('/\s+/u', ' ', $s);
    return (string)$s;
}

$backupPath = $argv[1] ?? '';
$tenantDb = $argv[2] ?? '';
$targetTenantId = isset($argv[3]) ? (int)$argv[3] : 1;

if ($backupPath === '' || $tenantDb === '') {
    out('Uso: php saas/restore_catalog_catalogs_from_backup.php <backup.sql> <tenant_db_name> [tenant_id]');
    exit(2);
}
if (!is_file($backupPath) || !is_readable($backupPath)) {
    out('ERROR: No se puede leer el archivo: ' . $backupPath);
    exit(3);
}
if ($targetTenantId <= 0) {
    out('ERROR: tenant_id inválido.');
    exit(4);
}

$host = getenv('TENANT_DB_HOST');
if (!is_string($host) || trim($host) === '') {
    $host = getenv('MASTER_DB_HOST');
}
$host = (is_string($host) && trim($host) !== '') ? trim($host) : 'localhost';

$port = getenv('TENANT_DB_PORT');
if (!is_string($port) || trim($port) === '') {
    $port = getenv('MASTER_DB_PORT');
}
$port = (int)((is_string($port) && trim($port) !== '') ? trim($port) : '3306');

$adminUser = getenv('PROVISION_DB_ADMIN_USER');
if (!is_string($adminUser) || trim($adminUser) === '') {
    $adminUser = getenv('MASTER_DB_USER');
}
$adminUser = (is_string($adminUser) && trim($adminUser) !== '') ? trim($adminUser) : 'root';

$adminPass = getenv('PROVISION_DB_ADMIN_PASS');
if (!is_string($adminPass)) {
    $adminPass = getenv('MASTER_DB_PASS');
}
$adminPass = is_string($adminPass) ? $adminPass : '';

$pdo = new PDO(
    "mysql:host={$host};port={$port};dbname={$tenantDb};charset=utf8mb4",
    $adminUser,
    $adminPass,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

$brandsRows = [];
$typesRows = [];
$modelsRows = [];

$fh = fopen($backupPath, 'rb');
if (!$fh) {
    out('ERROR: No se pudo abrir el archivo.');
    exit(5);
}
while (($line = fgets($fh)) !== false) {
    $line = trim($line);
    if ($line === '' || stripos($line, 'INSERT INTO') !== 0) {
        continue;
    }
    if (!preg_match('/^INSERT INTO\\s+`(?P<table>[a-zA-Z0-9_]+)`\\s*\\((?P<cols>[^)]+)\\)\\s*VALUES\\s*(?P<vals>\\(.+\\))\\s*;?$/', $line, $m)) {
        continue;
    }
    $table = (string)$m['table'];
    if (!in_array($table, ['brands', 'device_types', 'models'], true)) {
        continue;
    }
    $colsRaw = (string)$m['cols'];
    $valsRaw = (string)$m['vals'];

    $cols = array_map(static function (string $c): string {
        $c = trim($c);
        $c = trim($c, '`');
        return $c;
    }, explode(',', $colsRaw));
    $valsParts = parseSqlValues($valsRaw);
    if (count($cols) !== count($valsParts)) {
        continue;
    }
    $row = [];
    foreach ($cols as $i => $col) {
        $row[$col] = decodeSqlLiteral((string)$valsParts[$i]);
    }
    if ($table === 'brands') {
        $brandsRows[] = $row;
    } elseif ($table === 'device_types') {
        $typesRows[] = $row;
    } elseif ($table === 'models') {
        $modelsRows[] = $row;
    }
}
fclose($fh);

if (!$brandsRows && !$typesRows && !$modelsRows) {
    out('No se encontraron inserts de brands/device_types/models en el backup.');
    exit(0);
}

out('Restaurando catálogos desde backup...');
out('DB=' . $tenantDb . ' tenant_id=' . $targetTenantId);

$pdo->exec('SET FOREIGN_KEY_CHECKS=0');

$brandIdMap = [];
$typeIdMap = [];

$selectBrand = $pdo->prepare("SELECT id FROM brands WHERE tenant_id = ? AND LOWER(TRIM(name)) = ? LIMIT 1");
$insertBrand = $pdo->prepare("INSERT INTO brands (name, description, logo_path, logo, is_active, created_at, updated_at, tenant_id) VALUES (?, ?, ?, ?, ?, NOW(), NOW(), ?)");

foreach ($brandsRows as $r) {
    $oldId = (int)($r['id'] ?? 0);
    $name = (string)($r['name'] ?? '');
    if ($oldId <= 0 || trim($name) === '') {
        continue;
    }
    $key = normalizeKey($name);
    $selectBrand->execute([$targetTenantId, $key]);
    $existingId = (int)($selectBrand->fetchColumn() ?: 0);
    if ($existingId > 0) {
        $brandIdMap[$oldId] = $existingId;
        continue;
    }
    $insertBrand->execute([
        $name,
        $r['description'] ?? null,
        $r['logo_path'] ?? null,
        $r['logo'] ?? null,
        isset($r['is_active']) ? (int)$r['is_active'] : 1,
        $targetTenantId
    ]);
    $newId = (int)$pdo->lastInsertId();
    if ($newId > 0) {
        $brandIdMap[$oldId] = $newId;
    }
}

$selectType = $pdo->prepare("SELECT id FROM device_types WHERE tenant_id = ? AND LOWER(TRIM(name)) = ? LIMIT 1");
$insertType = $pdo->prepare("INSERT INTO device_types (name, description, is_visible, is_active, sort_order, created_at, updated_at, tenant_id) VALUES (?, ?, ?, ?, ?, NOW(), NOW(), ?)");
$updateType = $pdo->prepare("UPDATE device_types SET description = ?, is_visible = ?, is_active = ?, sort_order = ?, updated_at = NOW() WHERE id = ? AND tenant_id = ?");

foreach ($typesRows as $r) {
    $oldId = (int)($r['id'] ?? 0);
    $name = (string)($r['name'] ?? '');
    if ($oldId <= 0 || trim($name) === '') {
        continue;
    }
    $key = normalizeKey($name);
    $selectType->execute([$targetTenantId, $key]);
    $existingId = (int)($selectType->fetchColumn() ?: 0);
    $desc = $r['description'] ?? null;
    $isVisible = isset($r['is_visible']) ? (int)$r['is_visible'] : 1;
    $isActive = isset($r['is_active']) ? (int)$r['is_active'] : 1;
    $sortOrder = isset($r['sort_order']) ? (int)$r['sort_order'] : 0;

    if ($existingId > 0) {
        $typeIdMap[$oldId] = $existingId;
        $updateType->execute([$desc, $isVisible, $isActive, $sortOrder, $existingId, $targetTenantId]);
        continue;
    }
    $insertType->execute([$name, $desc, $isVisible, $isActive, $sortOrder, $targetTenantId]);
    $newId = (int)$pdo->lastInsertId();
    if ($newId > 0) {
        $typeIdMap[$oldId] = $newId;
    }
}

$selectModel = $pdo->prepare("SELECT id FROM models WHERE tenant_id = ? AND LOWER(TRIM(name)) = ? AND brand_id = ? AND device_type_id = ? LIMIT 1");
$insertModel = $pdo->prepare("INSERT INTO models (name, brand_id, device_type_id, description, is_active, created_at, updated_at, tenant_id) VALUES (?, ?, ?, ?, ?, NOW(), NOW(), ?)");
$updateModel = $pdo->prepare("UPDATE models SET description = ?, is_active = ?, updated_at = NOW() WHERE id = ? AND tenant_id = ?");

$inserted = 0;
$updated = 0;
$skipped = 0;

foreach ($modelsRows as $r) {
    $name = (string)($r['name'] ?? '');
    if (trim($name) === '') {
        continue;
    }
    $oldBrandId = (int)($r['brand_id'] ?? 0);
    $oldTypeId = (int)($r['device_type_id'] ?? 0);
    $brandId = (int)($brandIdMap[$oldBrandId] ?? 0);
    $typeId = (int)($typeIdMap[$oldTypeId] ?? 0);
    if ($brandId <= 0 || $typeId <= 0) {
        $skipped++;
        continue;
    }
    $key = normalizeKey($name);
    $selectModel->execute([$targetTenantId, $key, $brandId, $typeId]);
    $existingId = (int)($selectModel->fetchColumn() ?: 0);

    $desc = $r['description'] ?? null;
    $isActive = isset($r['is_active']) ? (int)$r['is_active'] : 1;

    if ($existingId > 0) {
        $updateModel->execute([$desc, $isActive, $existingId, $targetTenantId]);
        $updated++;
        continue;
    }
    $insertModel->execute([$name, $brandId, $typeId, $desc, $isActive, $targetTenantId]);
    $inserted++;
}

$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

out('OK');
out('brands_map=' . count($brandIdMap));
out('types_map=' . count($typeIdMap));
out('models_inserted=' . $inserted);
out('models_updated=' . $updated);
out('models_skipped=' . $skipped);

