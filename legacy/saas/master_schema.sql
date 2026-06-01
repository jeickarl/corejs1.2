-- Tabla para registrar las empresas (inquilinos)
CREATE TABLE IF NOT EXISTS saas_tenants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_name VARCHAR(255) NOT NULL,
    db_name VARCHAR(64) NOT NULL UNIQUE,
    status ENUM('active', 'suspended') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- Tabla para mapear usuarios a empresas (para saber a qué DB enviarlos)
CREATE TABLE IF NOT EXISTS saas_users_lookup (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE, -- El email debe ser único en todo el sistema SaaS
    tenant_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES saas_tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- Tabla para gestionar licencias
CREATE TABLE IF NOT EXISTS saas_licenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    license_code VARCHAR(50) NOT NULL UNIQUE,
    status ENUM('active', 'used', 'expired') DEFAULT 'active',
    tenant_id INT DEFAULT NULL, -- Empresa que usó la licencia
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    used_at TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (tenant_id) REFERENCES saas_tenants(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;
