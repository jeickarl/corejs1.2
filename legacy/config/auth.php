<?php
require_once __DIR__ . '/session.php';
// Simple authentication check
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ../login/');
        exit;
    }
}
?>