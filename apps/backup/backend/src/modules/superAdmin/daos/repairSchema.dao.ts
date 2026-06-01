import { Inject, Injectable } from '@nestjs/common';
import type { RowDataPacket } from 'mysql2';
import type { MasterDbPool } from '../../../infrastructure/db/master.pool';
import { createTenantConnection } from '../../../infrastructure/db/tenant.connection';

export type RepairTenantResult = {
  tenantId: number;
  companyName: string;
  status: string;
  ok: number;
  fail: number;
  fails: Array<{ step: string; error: string }>;
};

@Injectable()
export class RepairSchemaDao {
  constructor(@Inject('MASTER_DB_POOL') private readonly pool: MasterDbPool) {}

  private ddls(): Array<{ step: string; sql: string }> {
    return [
      {
        step: 'inventory_products',
        sql: `
          CREATE TABLE IF NOT EXISTS inventory_products (
            id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            sku VARCHAR(100) NULL,
            name VARCHAR(255) NOT NULL,
            description TEXT NULL,
            sale_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            cost_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            current_stock DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            min_stock DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
            KEY idx_name (name),
            KEY idx_sku (sku),
            KEY idx_is_active (is_active)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        `,
      },
      {
        step: 'inventory_movements',
        sql: `
          CREATE TABLE IF NOT EXISTS inventory_movements (
            id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            product_id INT(10) UNSIGNED NOT NULL,
            movement_type ENUM('in','out','adjust') NOT NULL,
            quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            reference_type VARCHAR(50) NULL,
            reference_id INT(11) NULL,
            notes TEXT NULL,
            created_by INT(10) UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
            KEY idx_product_id (product_id),
            KEY idx_created_at (created_at)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        `,
      },
      {
        step: 'suppliers',
        sql: `
          CREATE TABLE IF NOT EXISTS suppliers (
            id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            supplier_code VARCHAR(50) NULL,
            supplier_type VARCHAR(50) NULL,
            company_name VARCHAR(255) NOT NULL,
            contact_name VARCHAR(255) NULL,
            tax_id VARCHAR(50) NULL,
            phone VARCHAR(50) NULL,
            mobile VARCHAR(50) NULL,
            email VARCHAR(255) NULL,
            website VARCHAR(255) NULL,
            address TEXT NULL,
            city VARCHAR(100) NULL,
            state VARCHAR(100) NULL,
            country VARCHAR(100) NULL,
            postal_code VARCHAR(20) NULL,
            payment_terms VARCHAR(100) NULL,
            credit_limit DECIMAL(12,2) NULL,
            discount_percentage DECIMAL(5,2) NULL,
            bank_name VARCHAR(100) NULL,
            account_number VARCHAR(100) NULL,
            account_type VARCHAR(50) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            rating TINYINT(1) NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
            KEY idx_company_name (company_name),
            KEY idx_tax_id (tax_id),
            KEY idx_is_active (is_active)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        `,
      },
      {
        step: 'purchase_orders',
        sql: `
          CREATE TABLE IF NOT EXISTS purchase_orders (
            id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            po_number VARCHAR(50) NOT NULL,
            supplier_id INT(10) UNSIGNED NOT NULL,
            order_date DATE NOT NULL,
            expected_date DATE NULL,
            payment_method VARCHAR(100) NULL,
            payment_terms VARCHAR(100) NULL,
            notes TEXT NULL,
            total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            payment_status ENUM('pending','partially_paid','paid') NOT NULL DEFAULT 'pending',
            status ENUM('draft','sent','received','cancelled') NOT NULL DEFAULT 'draft',
            created_by INT(10) UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
            KEY idx_po_number (po_number),
            KEY idx_supplier_id (supplier_id),
            KEY idx_created_at (created_at)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        `,
      },
      {
        step: 'supplier_payments',
        sql: `
          CREATE TABLE IF NOT EXISTS supplier_payments (
            id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            supplier_id INT(10) UNSIGNED NOT NULL,
            purchase_order_id INT(10) UNSIGNED NULL,
            payment_amount DECIMAL(12,2) NOT NULL,
            payment_method VARCHAR(100) NULL,
            payment_date DATE NOT NULL,
            reference_number VARCHAR(100) NULL,
            notes TEXT NULL,
            cash_session_id INT(10) UNSIGNED NULL,
            created_by INT(10) UNSIGNED NULL,
            request_id VARCHAR(64) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
            KEY idx_supplier_id (supplier_id),
            KEY idx_purchase_order_id (purchase_order_id),
            KEY idx_cash_session_id (cash_session_id)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        `,
      },
      {
        step: 'purchase_receipts',
        sql: `
          CREATE TABLE IF NOT EXISTS purchase_receipts (
            id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            receipt_number VARCHAR(50) NOT NULL,
            purchase_order_id INT(10) UNSIGNED NOT NULL,
            supplier_id INT(10) UNSIGNED NOT NULL,
            received_date DATE NOT NULL,
            notes TEXT NULL,
            total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            created_by INT(10) UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
            KEY idx_receipt_number (receipt_number),
            KEY idx_po_id (purchase_order_id),
            KEY idx_created_at (created_at)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        `,
      },
      {
        step: 'purchase_receipt_items',
        sql: `
          CREATE TABLE IF NOT EXISTS purchase_receipt_items (
            id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            receipt_id INT(10) UNSIGNED NOT NULL,
            product_id INT(10) UNSIGNED NOT NULL,
            quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            KEY idx_receipt_id (receipt_id),
            KEY idx_product_id (product_id)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        `,
      },
      {
        step: 'device_categories',
        sql: `
          CREATE TABLE IF NOT EXISTS device_categories (
            id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            description TEXT NULL,
            sort_order INT(11) NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
            UNIQUE KEY uq_name (name),
            KEY idx_active (active),
            KEY idx_sort (sort_order)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        `,
      },
      {
        step: 'services',
        sql: `
          CREATE TABLE IF NOT EXISTS services (
            id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            description TEXT NULL,
            device_category_id INT(10) UNSIGNED NOT NULL,
            base_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            estimated_time INT(11) NOT NULL DEFAULT 0,
            notes TEXT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
            UNIQUE KEY uq_name (name),
            KEY idx_active (active),
            KEY idx_category (device_category_id)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        `,
      },
      {
        step: 'order_statuses',
        sql: `
          CREATE TABLE IF NOT EXISTS order_statuses (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(64) NOT NULL,
            name VARCHAR(128) NOT NULL,
            emoji VARCHAR(32) NULL,
            color VARCHAR(16) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            UNIQUE KEY uniq_slug (slug)
          )
        `,
      },
      {
        step: 'technical_reports',
        sql: `
          CREATE TABLE IF NOT EXISTS technical_reports (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            report_title VARCHAR(255) NOT NULL,
            diagnosis TEXT NULL,
            procedure_taken TEXT NULL,
            introduction TEXT NULL,
            conclusion TEXT NULL,
            photos_json LONGTEXT NULL,
            created_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_order_id (order_id),
            KEY idx_created_at (created_at)
          )
        `,
      },
      {
        step: 'work_order_services',
        sql: `
          CREATE TABLE IF NOT EXISTS work_order_services (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            work_order_id INT NOT NULL,
            service_id INT NOT NULL,
            quantity DECIMAL(12,2) NOT NULL DEFAULT 1.00,
            service_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            total_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_order_id (work_order_id),
            KEY idx_service_id (service_id),
            KEY idx_created_at (created_at)
          )
        `,
      },
    ];
  }

  private async ensureColumns(conn: Awaited<ReturnType<typeof createTenantConnection>>) {
    try {
      await conn.query('SELECT verification_code FROM work_orders LIMIT 1');
    } catch {
      try {
        await conn.query(`ALTER TABLE work_orders ADD COLUMN verification_code VARCHAR(20) NULL AFTER order_number`);
      } catch {
      }
    }

    try {
      await conn.query('SELECT approval_status FROM work_orders LIMIT 1');
    } catch {
      try {
        await conn.query(`ALTER TABLE work_orders ADD COLUMN approval_status VARCHAR(32) NULL AFTER verification_code`);
      } catch {
      }
    }
  }

  async repairTenant(tenantId: number): Promise<RepairTenantResult> {
    const [rows] = await this.pool.query<RowDataPacket[]>(
      `SELECT id, nombre, estado FROM empresas WHERE id = ? LIMIT 1`,
      [tenantId],
    );
    const e = rows?.[0];
    const companyName = String(e?.nombre ?? '');
    const status = String(e?.estado ?? '');
    if (!e) {
      return { tenantId, companyName: '', status: '', ok: 0, fail: 1, fails: [{ step: 'empresa', error: 'No encontrada' }] };
    }

    const conn = await createTenantConnection(this.pool, tenantId);
    try {
      const fails: Array<{ step: string; error: string }> = [];
      let ok = 0;
      for (const d of this.ddls()) {
        try {
          await conn.query(d.sql);
          ok++;
        } catch (ex) {
          fails.push({ step: d.step, error: ex instanceof Error ? ex.message : 'Error' });
        }
      }
      await this.ensureColumns(conn);
      return { tenantId, companyName, status, ok, fail: fails.length, fails };
    } finally {
      await conn.end();
    }
  }

  async repairAllActive(): Promise<RepairTenantResult[]> {
    const [rows] = await this.pool.query<RowDataPacket[]>(
      `SELECT id FROM empresas WHERE estado = 'active' ORDER BY id ASC LIMIT 500`,
    );
    const ids = (rows ?? []).map((r) => Number(r.id ?? 0)).filter((n) => Number.isFinite(n) && n > 0);
    const out: RepairTenantResult[] = [];
    for (const id of ids) {
      out.push(await this.repairTenant(id));
    }
    return out;
  }
}

