<?php
declare(strict_types=1);

function fail(string $msg, int $code = 1): void {
    fwrite(STDERR, $msg . PHP_EOL);
    exit($code);
}

$inDir = $argv[1] ?? '';
$outDir = $argv[2] ?? '';
$tablesRaw = $argv[3] ?? '';
if ($inDir === '' || $outDir === '' || $tablesRaw === '') {
    fail('Uso: php filter_backup_sql_tables.php <carpeta_entrada> <carpeta_salida> <tabla1,tabla2,...>');
}
$tables = array_values(array_filter(array_map(function($t){ return strtolower(trim($t)); }, explode(',', $tablesRaw))));
if (empty($tables)) {
    fail('Debe indicar al menos una tabla');
}
$allow = array_fill_keys($tables, true);

$inDir = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $inDir), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
$outDir = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $outDir), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

$manifestPath = $inDir . 'manifest.json';
$sqlCandidates = glob($inDir . '*.sql') ?: [];
if (!is_file($manifestPath) || empty($sqlCandidates)) {
    fail('Faltan archivos en la carpeta de entrada');
}
$sqlPath = $sqlCandidates[0];

$manifest = json_decode((string)@file_get_contents($manifestPath), true);
if (!is_array($manifest)) {
    fail('manifest.json inválido');
}
$manifest['tables'] = array_values(array_filter((array)($manifest['tables'] ?? []), function($t) use ($allow){
    return isset($allow[strtolower((string)$t)]);
}));

$raw = (string)@file_get_contents($sqlPath);
if ($raw === '') fail('SQL vacío');

$lines = preg_split("/\\r\\n|\\n|\\r/", $raw);
$outLines = [];
foreach ($lines as $line) {
    $t = ltrim((string)$line);
    if ($t === '') continue;
    if (stripos($t, 'set names') === 0 || stripos($t, 'set foreign_key_checks') === 0) {
        $outLines[] = $t;
        continue;
    }
    if (stripos($t, 'replace into') === 0) {
        if (preg_match('/^REPLACE\\s+INTO\\s+`([^`]+)`/i', $t, $m)) {
            $tbl = strtolower((string)$m[1]);
            if (isset($allow[$tbl])) {
                $outLines[] = $t;
            }
        }
        continue;
    }
}

if (!is_dir($outDir)) {
    if (!@mkdir($outDir, 0755, true) && !is_dir($outDir)) {
        fail('No se pudo crear salida');
    }
}
file_put_contents($outDir . 'manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
file_put_contents($outDir . basename($sqlPath), implode(PHP_EOL, $outLines) . PHP_EOL);
fwrite(STDOUT, 'OK: ' . $outDir . PHP_EOL);
