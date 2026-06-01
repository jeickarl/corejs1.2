<?php
require_once '../config/session.php';
require_once '../config/functions.php';
require_once '../config/database.php';

// Registrar actividad de logout si hay usuario logueado
if (isset($_SESSION['user_id'])) {
    logActivity($_SESSION['user_id'], 'LOGOUT', 'users', $_SESSION['user_id']);
}

// Destruir sesión de forma segura
destroySession();

// Redirigir al login
header("Location: index.php");
exit();
?>