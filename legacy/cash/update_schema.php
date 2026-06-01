<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/functions.php';

$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;
if ($perDatabase) {
    echo "per_database mode detected. Skipping schema updates.\n";
    exit;
}

try {
    // 1. Crear tabla de categorías
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS transaction_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(50) NOT NULL,
            type ENUM('income', 'expense') NOT NULL,
            description TEXT,
            is_active BOOLEAN DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    
    // Insertar categorías por defecto si no existen
    $stmt = $pdo->query("SELECT COUNT(*) FROM transaction_categories");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("
            INSERT INTO transaction_categories (name, type, description) VALUES
            ('Ventas', 'income', 'Ingresos por ventas directas'),
            ('Abonos', 'income', 'Abonos a créditos o apartados'),
            ('Servicios', 'income', 'Ingresos por servicios técnicos'),
            ('Otros Ingresos', 'income', 'Otros tipos de ingresos'),
            
            ('Proveedores', 'expense', 'Pago a proveedores'),
            ('Servicios Públicos', 'expense', 'Pago de luz, agua, internet, etc.'),
            ('Nómina', 'expense', 'Adelantos o pagos de nómina'),
            ('Insumos', 'expense', 'Compra de insumos de operación'),
            ('Mantenimiento', 'expense', 'Gastos de mantenimiento del local'),
            ('Alimentación', 'expense', 'Gastos de alimentación del personal'),
            ('Transporte', 'expense', 'Gastos de transporte'),
            ('Retiros', 'expense', 'Retiro de utilidades o caja menor'),
            ('Otros Gastos', 'expense', 'Otros gastos varios');
        ");
        echo "Categorías por defecto creadas.<br>";
    }

    // 2. Agregar columnas a cash_income
    $columns = $pdo->query("SHOW COLUMNS FROM cash_income")->fetchAll(PDO::FETCH_COLUMN);
    
    if (!in_array('category_id', $columns)) {
        $pdo->exec("ALTER TABLE cash_income ADD COLUMN category_id INT NULL AFTER payment_method");
        $pdo->exec("ALTER TABLE cash_income ADD CONSTRAINT fk_income_category FOREIGN KEY (category_id) REFERENCES transaction_categories(id) ON DELETE SET NULL");
        echo "Columna category_id agregada a cash_income.<br>";
    }
    
    if (!in_array('reference_number', $columns)) {
        $pdo->exec("ALTER TABLE cash_income ADD COLUMN reference_number VARCHAR(50) NULL AFTER amount");
        echo "Columna reference_number agregada a cash_income.<br>";
    }

    // 3. Agregar columnas a cash_expenses
    $columns = $pdo->query("SHOW COLUMNS FROM cash_expenses")->fetchAll(PDO::FETCH_COLUMN);
    
    if (!in_array('category_id', $columns)) {
        $pdo->exec("ALTER TABLE cash_expenses ADD COLUMN category_id INT NULL AFTER concept");
        $pdo->exec("ALTER TABLE cash_expenses ADD CONSTRAINT fk_expense_category FOREIGN KEY (category_id) REFERENCES transaction_categories(id) ON DELETE SET NULL");
        echo "Columna category_id agregada a cash_expenses.<br>";
    }
    
    if (!in_array('reference_number', $columns)) {
        $pdo->exec("ALTER TABLE cash_expenses ADD COLUMN reference_number VARCHAR(50) NULL AFTER amount");
        echo "Columna reference_number agregada a cash_expenses.<br>";
    }
    
    if (!in_array('receipt_image', $columns)) {
        $pdo->exec("ALTER TABLE cash_expenses ADD COLUMN receipt_image VARCHAR(255) NULL AFTER notes");
        echo "Columna receipt_image agregada a cash_expenses.<br>";
    }

    echo "Migración completada exitosamente.";

} catch (PDOException $e) {
    echo "Error en migración: " . $e->getMessage();
}
?>
