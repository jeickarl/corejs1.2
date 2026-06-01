<?php

final class Crypto
{
    private const CIPHER = 'aes-256-gcm';

    public static function encrypt(string $plaintext): array
    {
        $key = self::getKey();
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false || $tag === '') {
            throw new RuntimeException('Error cifrando datos.');
        }

        return [
            'enc' => base64_encode($ciphertext),
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
        ];
    }

    public static function decrypt(string $encB64, string $ivB64, string $tagB64): string
    {
        $key = self::getKey();
        $ciphertext = base64_decode($encB64, true);
        $iv = base64_decode($ivB64, true);
        $tag = base64_decode($tagB64, true);

        if ($ciphertext === false || $iv === false || $tag === false) {
            throw new RuntimeException('Datos cifrados inválidos.');
        }

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plaintext === false) {
            throw new RuntimeException('No se pudo descifrar los datos.');
        }

        return $plaintext;
    }

    private static function getKey(): string
    {
        $raw = getenv('MASTER_DB_KEY');
        if (!is_string($raw) || trim($raw) === '') {
            $envLocalPath = __DIR__ . DIRECTORY_SEPARATOR . '.env.local';
            if (is_file($envLocalPath) && is_readable($envLocalPath)) {
                $envLocal = file_get_contents($envLocalPath);
                if (is_string($envLocal) && preg_match('/^\s*MASTER_DB_KEY\s*=\s*(.*?)\s*$/mi', $envLocal, $m)) {
                    $raw = trim((string)$m[1]);
                    if ($raw !== '' && ($raw[0] === '"' || $raw[0] === "'")) {
                        $q = $raw[0];
                        if (substr($raw, -1) === $q) {
                            $raw = substr($raw, 1, -1);
                        }
                    }
                }
            }
        }
        if (!is_string($raw) || trim($raw) === '') {
            throw new RuntimeException('Falta configuración de cifrado.');
        }
        $raw = trim($raw);

        $decoded = base64_decode($raw, true);
        if (is_string($decoded) && strlen($decoded) === 32) {
            return $decoded;
        }

        return hash('sha256', $raw, true);
    }
}

