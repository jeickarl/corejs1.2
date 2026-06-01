<?php
declare(strict_types=1);

function fail(string $msg, int $code = 1): void {
    fwrite(STDERR, $msg . PHP_EOL);
    exit($code);
}

function readFileStrict(string $path): string {
    $raw = @file_get_contents($path);
    if ($raw === false) {
        fail('No se pudo leer: ' . $path);
    }
    return $raw;
}

function ensureDir(string $dir): void {
    if (is_dir($dir)) return;
    if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
        fail('No se pudo crear la carpeta: ' . $dir);
    }
}

function splitSqlStatements(string $sql): array {
    $out = [];
    $buf = '';
    $len = strlen($sql);
    $inSingle = false;
    $inDouble = false;
    $inBacktick = false;
    $escape = false;
    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        $buf .= $ch;
        if ($escape) {
            $escape = false;
            continue;
        }
        if ($ch === '\\') {
            if ($inSingle || $inDouble) {
                $escape = true;
            }
            continue;
        }
        if ($ch === "'" && !$inDouble && !$inBacktick) {
            $inSingle = !$inSingle;
            continue;
        }
        if ($ch === '"' && !$inSingle && !$inBacktick) {
            $inDouble = !$inDouble;
            continue;
        }
        if ($ch === '`' && !$inSingle && !$inDouble) {
            $inBacktick = !$inBacktick;
            continue;
        }
        if ($ch === ';' && !$inSingle && !$inDouble && !$inBacktick) {
            $stmt = trim($buf);
            if ($stmt !== '') $out[] = $stmt;
            $buf = '';
        }
    }
    $tail = trim($buf);
    if ($tail !== '') $out[] = $tail;
    return $out;
}

function splitSqlCsv(string $s): array {
    $out = [];
    $buf = '';
    $len = strlen($s);
    $inSingle = false;
    $inDouble = false;
    $escape = false;
    for ($i = 0; $i < $len; $i++) {
        $ch = $s[$i];
        if ($escape) {
            $buf .= $ch;
            $escape = false;
            continue;
        }
        if ($ch === '\\' && ($inSingle || $inDouble)) {
            $buf .= $ch;
            $escape = true;
            continue;
        }
        if ($ch === "'" && !$inDouble) {
            $buf .= $ch;
            $inSingle = !$inSingle;
            continue;
        }
        if ($ch === '"' && !$inSingle) {
            $buf .= $ch;
            $inDouble = !$inDouble;
            continue;
        }
        if ($ch === ',' && !$inSingle && !$inDouble) {
            $out[] = trim($buf);
            $buf = '';
            continue;
        }
        $buf .= $ch;
    }
    $t = trim($buf);
    if ($t !== '') $out[] = $t;
    return $out;
}

function normalizeTable(string $t): string {
    $t = trim($t);
    $t = trim($t, '`');
    return strtolower($t);
}

function convertInsert(string $stmt, int $fromTenant, int $toTenant): ?string {
    $s = ltrim($stmt);
    if (stripos($s, 'insert into') !== 0) return null;

    $s = preg_replace('/^INSERT\s+INTO/i', 'REPLACE INTO', $s);

    if (!preg_match('/REPLACE\s+INTO\s+`([^`]+)`\s*\((.*?)\)\s*VALUES\s*\((.*)\)\s*;?\s*$/is', $s, $m)) {
        return $stmt;
    }

    $tableRaw = (string)$m[1];
    $colsRaw = (string)$m[2];
    $valsRaw = (string)$m[3];

    $cols = splitSqlCsv($colsRaw);
    $vals = splitSqlCsv($valsRaw);
    if (count($cols) !== count($vals) || count($cols) === 0) {
        return $stmt;
    }

    $keepCols = [];
    $keepVals = [];
    foreach ($cols as $i => $c) {
        $cNorm = normalizeTable($c);
        if ($cNorm === 'tenant_id') {
            continue;
        }
        $keepCols[] = $c;
        $v = $vals[$i];
        $v = str_replace('uploads/' . $fromTenant . '/', 'uploads/' . $toTenant . '/', $v);
        $v = str_replace('uploads\\' . $fromTenant . '\\', 'uploads\\' . $toTenant . '\\', $v);
        $keepVals[] = $v;
    }

    $table = '`' . str_replace('`', '', $tableRaw) . '`';
    return 'REPLACE INTO ' . $table . ' (' . implode(',', $keepCols) . ') VALUES (' . implode(',', $keepVals) . ');';
}

$inDir = $argv[1] ?? '';
if ($inDir === '') {
    fail('Uso: php convert_backup_to_new_system.php <carpeta_backup> [carpeta_salida] [tenant_destino]');
}
$inDir = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $inDir), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
$outDir = $argv[2] ?? ($inDir . '..' . DIRECTORY_SEPARATOR . basename(rtrim($inDir, DIRECTORY_SEPARATOR)) . '_converted');
$outDir = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $outDir), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

$manifestPath = $inDir . 'manifest.json';
$sqlPath = null;
$sqlCandidates = glob($inDir . '*.sql') ?: [];
if (count($sqlCandidates) > 0) $sqlPath = $sqlCandidates[0];
if (!$sqlPath || !is_file($sqlPath)) {
    fail('No se encontró archivo .sql dentro de: ' . $inDir);
}
if (!is_file($manifestPath)) {
    fail('No se encontró manifest.json dentro de: ' . $inDir);
}

$manifest = json_decode(readFileStrict($manifestPath), true);
if (!is_array($manifest)) {
    fail('manifest.json inválido');
}
$fromTenant = (int)($manifest['tenant_id'] ?? 0);
if ($fromTenant <= 0) $fromTenant = 1;
$toTenant = isset($argv[3]) ? (int)$argv[3] : 1;
if ($toTenant <= 0) $toTenant = 1;

$sql = readFileStrict($sqlPath);
$stmts = splitSqlStatements($sql);

$out = [];
$out[] = 'SET NAMES utf8mb4;';
$out[] = 'SET FOREIGN_KEY_CHECKS=0;';

foreach ($stmts as $st) {
    $stTrim = trim($st);
    if ($stTrim === '') continue;
    if (stripos($stTrim, 'insert into') === 0) {
        $converted = convertInsert($stTrim, $fromTenant, $toTenant);
        if ($converted) $out[] = $converted;
        continue;
    }
}

$out[] = 'SET FOREIGN_KEY_CHECKS=1;';

$manifest['tenant_id'] = $toTenant;
$manifest['scope'] = 'tenant';
$manifest['type'] = $manifest['type'] ?? 'zip';
$manifest['converted_from_tenant_id'] = $fromTenant;

ensureDir($outDir);
$outManifestPath = $outDir . 'manifest.json';
$outSqlPath = $outDir . basename($sqlPath);

file_put_contents($outManifestPath, json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
file_put_contents($outSqlPath, implode(PHP_EOL, $out) . PHP_EOL);

fwrite(STDOUT, 'OK: ' . $outDir . PHP_EOL);
