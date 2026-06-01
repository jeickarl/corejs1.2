<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Google Drive Auth Callback</title>
    <link rel="icon" type="image/png" href="../../assets/img/system_logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; display: flex; align-items: center; justify-content: center; height: 100vh; }
        .card { max-width: 500px; width: 100%; border-radius: 1rem; box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15); }
    </style>
</head>
<body>
    <div class="card p-4 text-center">
        <div class="mb-3">
            <i class="fab fa-google-drive text-success" style="font-size:64px;"></i>
        </div>
        
        <?php if (isset($_GET['code'])): ?>
            <h4 class="mb-3 text-success">¡Autorización Exitosa!</h4>
            <p class="text-muted">Copia este código y pégalo en la configuración del sistema:</p>
            
            <div class="input-group mb-3">
                <input type="text" class="form-control text-center font-monospace" value="<?php echo htmlspecialchars($_GET['code']); ?>" id="authCode" readonly>
                <button class="btn btn-primary" type="button" onclick="copyCode()">Copiar</button>
            </div>
            
            <p class="small text-muted">Puedes cerrar esta ventana.</p>

            <script>
                function copyCode() {
                    const copyText = document.getElementById("authCode");
                    copyText.select();
                    copyText.setSelectionRange(0, 99999);
                    navigator.clipboard.writeText(copyText.value);
                    alert("Código copiado al portapapeles");
                }
                
                // Intentar enviar a ventana padre
                if (window.opener) {
                    window.opener.postMessage({ type: 'GOOGLE_AUTH_CODE', code: '<?php echo $_GET['code']; ?>' }, '*');
                    document.write('<p class="text-success small">El código se ha enviado automáticamente a la ventana principal.</p>');
                    setTimeout(() => window.close(), 2000);
                }
            </script>

        <?php elseif (isset($_GET['error'])): ?>
            <h4 class="mb-3 text-danger">Error de Autorización</h4>
            <p class="text-danger"><?php echo htmlspecialchars($_GET['error']); ?></p>
            <a href="javascript:window.close()" class="btn btn-secondary">Cerrar</a>
        <?php else: ?>
            <p class="text-muted">Esperando parámetros...</p>
        <?php endif; ?>
    </div>
</body>
</html>
