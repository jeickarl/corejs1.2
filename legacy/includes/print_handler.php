<?php
/**
 * Manejador de impresión de documentos - Versión simplificada
 * Redirige a los archivos de impresión específicos
 */

require_once '../config/database.php';
require_once '../config/auth.php';

// Verificar que el usuario esté logueado
requireLogin();

// Verificar parámetros requeridos
if (!isset($_GET['type']) || !isset($_GET['id'])) {
    die('Parámetros faltantes');
}

$type = $_GET['type'];
$id = (int)$_GET['id'];

// Redirigir según el tipo
switch ($type) {
    case 'work_order':
        header('Location: ../orders/print_order.php?id=' . $id);
        break;
    case 'sale':
        header('Location: ../sales/print_sale.php?id=' . $id);
        break;
    case 'receipt':
        header('Location: ../sales/print_receipt.php?id=' . $id);
        break;
    case 'equipment_receipt':
        // Compatibilidad: redirigimos al formato único de Orden de Servicio
        header('Location: ../orders/print_order.php?id=' . $id);
        break;
    default:
        die('Tipo de documento no válido');
}

exit;