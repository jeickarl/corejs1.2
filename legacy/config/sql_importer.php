<?php

final class SqlImporter
{
    public static function importFile(PDO $pdo, string $filePath): void
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new RuntimeException('No se pudo leer el archivo SQL.');
        }

        $content = file_get_contents($filePath);
        if (!is_string($content) || $content === '') {
            return;
        }

        $delimiter = ';';
        $buffer = '';
        $inBlockComment = false;

        $lines = preg_split("/\r\n|\n|\r/", $content);
        if (!is_array($lines)) {
            return;
        }

        foreach ($lines as $line) {
            $line = (string)$line;
            $trim = ltrim($line);

            if ($inBlockComment) {
                if (strpos($trim, '*/') !== false) {
                    $inBlockComment = false;
                }
                continue;
            }

            if (strpos($trim, '/*') === 0) {
                if (strpos($trim, '*/') === false) {
                    $inBlockComment = true;
                }
                continue;
            }

            if (strpos($trim, '--') === 0) {
                continue;
            }

            if (preg_match('/^\s*DELIMITER\s+(.+)\s*$/i', $line, $m)) {
                $delimiter = (string)$m[1];
                $buffer = '';
                continue;
            }

            $buffer .= $line . "\n";

            $ready = rtrim($buffer);
            if ($delimiter !== '' && substr($ready, -strlen($delimiter)) === $delimiter) {
                $statement = substr($ready, 0, -strlen($delimiter));
                $statement = trim($statement);
                $buffer = '';

                if ($statement === '') {
                    continue;
                }

                try {
                    if (preg_match('/^\s*CALL\s+/i', $statement)) {
                        $st = $pdo->query($statement);
                        if ($st) {
                            do {
                                try { $st->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $__) {}
                            } while ($st->nextRowset());
                            $st->closeCursor();
                        }
                    } else {
                        $pdo->exec($statement);
                    }
                } catch (Throwable $e) {
                    $snippet = substr(preg_replace('/\s+/', ' ', $statement), 0, 180);
                    throw new RuntimeException('Error ejecutando migración SQL: ' . $e->getMessage() . ' | SQL: ' . $snippet);
                }
            }
        }
    }
}

