<?php
require_once '../config/session.php';
require_once '../config/functions.php';
require_once '../config/database.php';
requireAuth();
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) {
    header('Location: index.php?error=ID%20de%20factura%20inv%C3%A1lido');
    exit();
}
$qs = [];
$qs[] = 'open_modal=' . $id;
if (isset($_GET['share']) && $_GET['share'] !== '') {
    $qs[] = 'share=' . urlencode($_GET['share']);
}
$url = 'index.php?' . implode('&', $qs);
header('Location: ' . $url);
exit();
