<?php

class GoogleDrive {
    private $clientId;
    private $clientSecret;
    private $refreshToken;
    private $accessToken;
    private $parentId = null;
    private $verifySSL = false;
    
    const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    const UPLOAD_URL = 'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart';
    const API_URL = 'https://www.googleapis.com/drive/v3/files';

    public function __construct($clientId, $clientSecret, $refreshToken = null) {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->refreshToken = $refreshToken;
    }

    public function setParentId($parentId) {
        $this->parentId = $parentId ?: null;
    }

    public function setVerifySSL($verify) {
        $this->verifySSL = !!$verify;
    }

    /**
     * Obtiene URL de autorización para que el usuario inicie sesión
     */
    public function getAuthUrl($redirectUri) {
        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/drive.file',
            'access_type' => 'offline',
            'prompt' => 'consent' // Forzar refresh token
        ];
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }

    /**
     * Intercambia código por tokens
     */
    public function authenticate($code, $redirectUri) {
        $params = [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri
        ];

        $response = $this->makeRequest(self::TOKEN_URL, $params);
        
        if (isset($response['refresh_token'])) {
            $this->refreshToken = $response['refresh_token'];
        }
        
        if (isset($response['access_token'])) {
            $this->accessToken = $response['access_token'];
            return $response;
        }

        throw new Exception('Error autenticando: ' . json_encode($response));
    }

    /**
     * Obtiene nuevo access token usando refresh token
     */
    private function refreshAccessToken() {
        if (!$this->refreshToken) {
            throw new Exception('No hay refresh token disponible.');
        }

        $params = [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $this->refreshToken,
            'grant_type' => 'refresh_token'
        ];

        $response = $this->makeRequest(self::TOKEN_URL, $params);

        if (isset($response['access_token'])) {
            $this->accessToken = $response['access_token'];
        } else {
            // Manejo específico de errores comunes
            if (isset($response['error']) && $response['error'] === 'invalid_client') {
                throw new Exception('Error de autenticación: Las credenciales (Client ID/Secret) no coinciden con el token guardado. Por favor, vuelve a conectar tu cuenta (clic en "Obtener Código" y guarda el nuevo código).');
            }
            if (isset($response['error']) && $response['error'] === 'invalid_grant') {
                throw new Exception('Error de autenticación: El token ha expirado o fue revocado. Por favor, vuelve a conectar tu cuenta.');
            }
            throw new Exception('Error refrescando token: ' . json_encode($response));
        }
    }

    /**
     * Sube un archivo
     */
    public function uploadFile($filePath, $description = '') {
        if (!file_exists($filePath)) {
            throw new Exception("Archivo no encontrado: $filePath");
        }

        if (!$this->accessToken) {
            $this->refreshAccessToken();
        }

        $fileName = basename($filePath);
        $mimeType = $this->detectMimeType($filePath);
        
        // Metadatos
        $metadata = [
            'name' => $fileName,
            'description' => $description
        ];
        if ($this->parentId) {
            $metadata['parents'] = [$this->parentId];
        }

        $boundary = '-------' . md5(mt_rand() . microtime());
        
        $content = "--$boundary\r\n";
        $content .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
        $content .= json_encode($metadata) . "\r\n";
        $content .= "--$boundary\r\n";
        $content .= "Content-Type: $mimeType\r\n\r\n";
        $content .= file_get_contents($filePath) . "\r\n";
        $content .= "--$boundary--";

        $headers = [
            'Authorization: Bearer ' . $this->accessToken,
            'Content-Type: multipart/related; boundary=' . $boundary,
            'Content-Length: ' . strlen($content)
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, self::UPLOAD_URL);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $content);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->verifySSL ? true : false);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            throw new Exception('Curl Error: ' . curl_error($ch));
        }

        $json = json_decode($result, true);

        if ($httpCode >= 200 && $httpCode < 300) {
            return $json;
        } else {
            if ($httpCode === 401) {
                $this->refreshAccessToken();
                return $this->uploadFile($filePath, $description);
            }
            throw new Exception('Error subiendo archivo: ' . $result);
        }
    }

    private function makeRequest($url, $params) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->verifySSL ? true : false);
        
        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            throw new Exception('Curl Error: ' . curl_error($ch));
        }
        
        return json_decode($result, true);
    }

    private function detectMimeType($filePath) {
        $type = null;
        if (function_exists('finfo_open')) {
            $f = @finfo_open(FILEINFO_MIME_TYPE);
            if ($f) {
                $type = @finfo_file($f, $filePath);
                // finfo_close($f); // Deprecated in PHP 8.0+
            }
        }
        if (!$type && function_exists('mime_content_type')) {
            $type = @mime_content_type($filePath);
        }
        return $type ?: 'application/octet-stream';
    }
}
