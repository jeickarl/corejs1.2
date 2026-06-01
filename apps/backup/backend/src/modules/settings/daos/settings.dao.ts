import { Inject, Injectable } from '@nestjs/common';
import type { RowDataPacket } from 'mysql2';
import type { MasterDbPool } from '../../../infrastructure/db/master.pool';
import { createTenantConnection } from '../../../infrastructure/db/tenant.connection';

export type CompanyConfigRow = {
  companyName: string;
  companyPhone: string;
  companyEmail: string;
  companyWebsite: string;
  companyAddress: string;
  logoUrl: string;
};

export type RegionalConfigRow = {
  currency: string;
  currencySymbol: string;
  taxEnabled: boolean;
  taxName: string;
  taxRate: number;
  invoiceDueDaysDefault: number;
};

export type PaymentMethodRow = {
  id: number;
  name: string;
  isDefault: boolean;
  isActive: boolean;
  createdAt: string;
};

export type PaymentAccountRow = {
  id: number;
  paymentMethodId: number;
  alias: string;
  accountNumber: string;
  accountType: string;
  holderName: string;
  holderId: string;
  isActive: boolean;
};

export type ClientPortalConfigRow = {
  enableLookupById: boolean;
  showTimeline: boolean;
  allowApproval: boolean;
  homeTitle: string;
  homeSubtitle: string;
  whatsappLink: string;
  addressText: string;
  hoursText: string;
  mapEmbedUrl: string;
};

export type DeviceTypeRow = {
  id: number;
  name: string;
  isActive: boolean;
  sortOrder: number;
  createdAt: string;
  updatedAt: string;
};

export type BrandRow = {
  id: number;
  name: string;
  isActive: boolean;
  createdAt: string;
  updatedAt: string;
};

export type ModelRow = {
  id: number;
  name: string;
  brandId: number | null;
  deviceTypeId: number | null;
  isActive: boolean;
  createdAt: string;
  updatedAt: string;
};

function asNumber(v: unknown): number {
  const n = Number(v ?? 0);
  if (!Number.isFinite(n)) return 0;
  return Math.round(n * 100) / 100;
}

function asBool(v: unknown): boolean {
  return String(v ?? '') === '1' || String(v ?? '').toLowerCase() === 'true';
}

@Injectable()
export class SettingsDao {
  constructor(@Inject('MASTER_DB_POOL') private readonly masterPool: MasterDbPool) {}

  private async ensureSchema(conn: Awaited<ReturnType<typeof createTenantConnection>>) {
    try {
      await conn.query(
        `
        CREATE TABLE IF NOT EXISTS system_config (
          config_key VARCHAR(128) NOT NULL PRIMARY KEY,
          config_value TEXT NULL,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP()
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        `,
      );
    } catch {
    }

    try {
      await conn.query(
        `
        CREATE TABLE IF NOT EXISTS company_config (
          id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
          company_name VARCHAR(255) NOT NULL DEFAULT '',
          company_phone VARCHAR(50) NOT NULL DEFAULT '',
          company_email VARCHAR(255) NOT NULL DEFAULT '',
          company_website VARCHAR(255) NOT NULL DEFAULT '',
          company_address VARCHAR(255) NOT NULL DEFAULT '',
          logo_url VARCHAR(255) NOT NULL DEFAULT '',
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP()
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        `,
      );
    } catch {
    }

    try {
      await conn.query(
        `
        CREATE TABLE IF NOT EXISTS payment_methods (
          id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          name VARCHAR(100) NOT NULL,
          is_default TINYINT(1) NOT NULL DEFAULT 0,
          is_active TINYINT(1) NOT NULL DEFAULT 1,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
          KEY idx_is_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        `,
      );
    } catch {
    }

    try {
      await conn.query(
        `
        CREATE TABLE IF NOT EXISTS payment_method_accounts (
          id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          payment_method_id INT(10) UNSIGNED NOT NULL,
          alias VARCHAR(100) NOT NULL DEFAULT '',
          account_number VARCHAR(100) NOT NULL DEFAULT '',
          account_type VARCHAR(50) NOT NULL DEFAULT '',
          holder_name VARCHAR(100) NOT NULL DEFAULT '',
          holder_id VARCHAR(50) NOT NULL DEFAULT '',
          is_active TINYINT(1) NOT NULL DEFAULT 1,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
          KEY idx_payment_method_id (payment_method_id),
          KEY idx_is_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        `,
      );
    } catch {
    }

    try {
      await conn.query(
        `
        CREATE TABLE IF NOT EXISTS device_types (
          id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          name VARCHAR(100) NOT NULL,
          sort_order INT(11) NOT NULL DEFAULT 0,
          is_active TINYINT(1) NOT NULL DEFAULT 1,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
          KEY idx_is_active (is_active),
          KEY idx_sort_order (sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        `,
      );
    } catch {
    }

    try {
      await conn.query(
        `
        CREATE TABLE IF NOT EXISTS brands (
          id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          name VARCHAR(100) NOT NULL,
          is_active TINYINT(1) NOT NULL DEFAULT 1,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
          KEY idx_is_active (is_active),
          KEY idx_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        `,
      );
    } catch {
    }

    try {
      await conn.query(
        `
        CREATE TABLE IF NOT EXISTS models (
          id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          name VARCHAR(100) NOT NULL,
          brand_id INT(10) UNSIGNED NULL,
          device_type_id INT(10) UNSIGNED NULL,
          is_active TINYINT(1) NOT NULL DEFAULT 1,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
          KEY idx_is_active (is_active),
          KEY idx_name (name),
          KEY idx_brand_id (brand_id),
          KEY idx_device_type_id (device_type_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        `,
      );
    } catch {
    }

    try {
      await conn.query(`ALTER TABLE models ADD COLUMN device_type_id INT(10) UNSIGNED NULL`);
    } catch {
    }
    try {
      await conn.query(`ALTER TABLE models ADD KEY idx_device_type_id (device_type_id)`);
    } catch {
    }

    try {
      const [rows] = await conn.query<RowDataPacket[]>('SELECT COUNT(*) as c FROM company_config');
      const c = Number(rows?.[0]?.c ?? 0);
      if (c === 0) {
        await conn.query('INSERT INTO company_config (company_name) VALUES (?)', ['']);
      }
    } catch {
    }

    try {
      const [rows] = await conn.query<RowDataPacket[]>('SELECT COUNT(*) as c FROM payment_methods');
      const c = Number(rows?.[0]?.c ?? 0);
      if (c === 0) {
        await conn.query(
          `INSERT INTO payment_methods (name, is_default, is_active) VALUES ('Efectivo', 1, 1), ('Transferencia', 0, 1), ('Tarjeta', 0, 1)`,
        );
      }
    } catch {
    }

    try {
      const [rows] = await conn.query<RowDataPacket[]>('SELECT COUNT(*) as c FROM device_types');
      const c = Number(rows?.[0]?.c ?? 0);
      if (c === 0) {
        await conn.query(`INSERT INTO device_types (name, sort_order, is_active) VALUES ('General', 0, 1)`);
      }
    } catch {
    }
  }

  private async getConfig(conn: Awaited<ReturnType<typeof createTenantConnection>>, key: string): Promise<string> {
    try {
      const [rows] = await conn.query<RowDataPacket[]>(
        'SELECT config_value FROM system_config WHERE config_key = ? LIMIT 1',
        [key],
      );
      return String(rows?.[0]?.config_value ?? '');
    } catch {
      return '';
    }
  }

  private async setConfig(conn: Awaited<ReturnType<typeof createTenantConnection>>, key: string, value: string) {
    try {
      await conn.execute(
        `
        INSERT INTO system_config (config_key, config_value, updated_at)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE config_value = VALUES(config_value), updated_at = NOW()
        `,
        [key, value],
      );
    } catch {
    }
  }

  async getClientPortalConfig(input: { empresaId: number }): Promise<ClientPortalConfigRow> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const enableLookupById = await this.getConfig(conn, 'client_portal_enable_lookup_by_id');
      const showTimeline = await this.getConfig(conn, 'client_portal_show_timeline');
      const allowApproval = await this.getConfig(conn, 'client_portal_allow_approval');
      const homeTitle = await this.getConfig(conn, 'client_portal_home_title');
      const homeSubtitle = await this.getConfig(conn, 'client_portal_home_subtitle');
      const whatsappLink = await this.getConfig(conn, 'client_portal_whatsapp_link');
      const addressText = await this.getConfig(conn, 'client_portal_address_text');
      const hoursText = await this.getConfig(conn, 'client_portal_hours_text');
      const mapEmbedUrl = await this.getConfig(conn, 'client_portal_map_embed_url');
      return {
        enableLookupById: asBool(enableLookupById),
        showTimeline: asBool(showTimeline),
        allowApproval: asBool(allowApproval),
        homeTitle: homeTitle || '',
        homeSubtitle: homeSubtitle || '',
        whatsappLink: whatsappLink || '',
        addressText: addressText || '',
        hoursText: hoursText || '',
        mapEmbedUrl: mapEmbedUrl || '',
      };
    } finally {
      await conn.end();
    }
  }

  async updateClientPortalConfig(input: { empresaId: number } & ClientPortalConfigRow): Promise<boolean> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      await this.setConfig(conn, 'client_portal_enable_lookup_by_id', input.enableLookupById ? '1' : '0');
      await this.setConfig(conn, 'client_portal_show_timeline', input.showTimeline ? '1' : '0');
      await this.setConfig(conn, 'client_portal_allow_approval', input.allowApproval ? '1' : '0');
      await this.setConfig(conn, 'client_portal_home_title', input.homeTitle);
      await this.setConfig(conn, 'client_portal_home_subtitle', input.homeSubtitle);
      await this.setConfig(conn, 'client_portal_whatsapp_link', input.whatsappLink);
      await this.setConfig(conn, 'client_portal_address_text', input.addressText);
      await this.setConfig(conn, 'client_portal_hours_text', input.hoursText);
      await this.setConfig(conn, 'client_portal_map_embed_url', input.mapEmbedUrl);
      return true;
    } catch {
      return false;
    } finally {
      await conn.end();
    }
  }

  async listDeviceTypes(input: { empresaId: number; search?: string; onlyActive?: boolean }): Promise<DeviceTypeRow[]> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const q = (input.search ?? '').trim();
      const whereActive = input.onlyActive ? 'AND is_active = 1' : '';
      const whereSearch = q ? 'AND name LIKE ?' : '';
      const params: unknown[] = [];
      if (q) params.push(`%${q}%`);
      const [rows] = await conn.query<RowDataPacket[]>(
        `
        SELECT id, name, is_active, sort_order, created_at, updated_at
        FROM device_types
        WHERE 1=1
          ${whereActive}
          ${whereSearch}
        ORDER BY sort_order ASC, name ASC, id ASC
        LIMIT 500
        `,
        params,
      );
      return (rows ?? []).map((r) => ({
        id: Number(r.id),
        name: String(r.name ?? ''),
        isActive: Number(r.is_active ?? 1) === 1,
        sortOrder: Number(r.sort_order ?? 0),
        createdAt: String(r.created_at ?? ''),
        updatedAt: String(r.updated_at ?? ''),
      }));
    } finally {
      await conn.end();
    }
  }

  async createDeviceType(input: {
    empresaId: number;
    name: string;
    sortOrder: number;
    isActive: boolean;
  }): Promise<number> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const [r] = await conn.query<{ insertId: number } & RowDataPacket[]>(
        `
        INSERT INTO device_types (name, sort_order, is_active)
        VALUES (?, ?, ?)
        `,
        [input.name, Number(input.sortOrder ?? 0), input.isActive ? 1 : 0],
      );
      return Number((r as unknown as { insertId: number })?.insertId ?? 0);
    } finally {
      await conn.end();
    }
  }

  async updateDeviceType(input: {
    empresaId: number;
    id: number;
    name: string;
    sortOrder: number;
    isActive: boolean;
  }): Promise<boolean> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const [r] = await conn.query<RowDataPacket[]>(
        `
        UPDATE device_types
        SET name = ?, sort_order = ?, is_active = ?, updated_at = NOW()
        WHERE id = ?
        LIMIT 1
        `,
        [input.name, Number(input.sortOrder ?? 0), input.isActive ? 1 : 0, input.id],
      );
      const affected = Number((r as unknown as { affectedRows?: number })?.affectedRows ?? 0);
      return affected > 0;
    } finally {
      await conn.end();
    }
  }

  async deactivateDeviceType(input: { empresaId: number; id: number }): Promise<boolean> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const [r] = await conn.query<RowDataPacket[]>(
        `UPDATE device_types SET is_active = 0, updated_at = NOW() WHERE id = ? LIMIT 1`,
        [input.id],
      );
      const affected = Number((r as unknown as { affectedRows?: number })?.affectedRows ?? 0);
      return affected > 0;
    } finally {
      await conn.end();
    }
  }

  async listBrands(input: { empresaId: number; search?: string; onlyActive?: boolean }): Promise<BrandRow[]> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const q = (input.search ?? '').trim();
      const whereActive = input.onlyActive ? 'AND is_active = 1' : '';
      const whereSearch = q ? 'AND name LIKE ?' : '';
      const params: unknown[] = [];
      if (q) params.push(`%${q}%`);
      const [rows] = await conn.query<RowDataPacket[]>(
        `
        SELECT id, name, is_active, created_at, updated_at
        FROM brands
        WHERE 1=1
          ${whereActive}
          ${whereSearch}
        ORDER BY name ASC, id ASC
        LIMIT 500
        `,
        params,
      );
      return (rows ?? []).map((r) => ({
        id: Number(r.id),
        name: String(r.name ?? ''),
        isActive: Number(r.is_active ?? 1) === 1,
        createdAt: String(r.created_at ?? ''),
        updatedAt: String(r.updated_at ?? ''),
      }));
    } finally {
      await conn.end();
    }
  }

  async createBrand(input: { empresaId: number; name: string; isActive: boolean }): Promise<number> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const [r] = await conn.query<{ insertId: number } & RowDataPacket[]>(
        `INSERT INTO brands (name, is_active) VALUES (?, ?)`,
        [input.name, input.isActive ? 1 : 0],
      );
      return Number((r as unknown as { insertId: number })?.insertId ?? 0);
    } finally {
      await conn.end();
    }
  }

  async updateBrand(input: { empresaId: number; id: number; name: string; isActive: boolean }): Promise<boolean> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const [r] = await conn.query<RowDataPacket[]>(
        `UPDATE brands SET name = ?, is_active = ?, updated_at = NOW() WHERE id = ? LIMIT 1`,
        [input.name, input.isActive ? 1 : 0, input.id],
      );
      const affected = Number((r as unknown as { affectedRows?: number })?.affectedRows ?? 0);
      return affected > 0;
    } finally {
      await conn.end();
    }
  }

  async deactivateBrand(input: { empresaId: number; id: number }): Promise<boolean> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const [r] = await conn.query<RowDataPacket[]>(
        `UPDATE brands SET is_active = 0, updated_at = NOW() WHERE id = ? LIMIT 1`,
        [input.id],
      );
      const affected = Number((r as unknown as { affectedRows?: number })?.affectedRows ?? 0);
      return affected > 0;
    } finally {
      await conn.end();
    }
  }

  async listModels(input: {
    empresaId: number;
    search?: string;
    brandId?: number | null;
    deviceTypeId?: number | null;
    onlyActive?: boolean;
  }): Promise<ModelRow[]> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const q = (input.search ?? '').trim();
      const brandId = input.brandId ? Number(input.brandId) : null;
      const deviceTypeId = input.deviceTypeId ? Number(input.deviceTypeId) : null;
      const whereActive = input.onlyActive ? 'AND is_active = 1' : '';
      const whereSearch = q ? 'AND name LIKE ?' : '';
      const whereBrand = brandId ? 'AND brand_id = ?' : '';
      const whereType = deviceTypeId ? 'AND device_type_id = ?' : '';
      const params: unknown[] = [];
      if (q) params.push(`%${q}%`);
      if (brandId) params.push(brandId);
      if (deviceTypeId) params.push(deviceTypeId);
      const [rows] = await conn.query<RowDataPacket[]>(
        `
        SELECT id, name, brand_id, device_type_id, is_active, created_at, updated_at
        FROM models
        WHERE 1=1
          ${whereActive}
          ${whereSearch}
          ${whereBrand}
          ${whereType}
        ORDER BY name ASC, id ASC
        LIMIT 500
        `,
        params,
      );
      return (rows ?? []).map((r) => ({
        id: Number(r.id),
        name: String(r.name ?? ''),
        brandId: r.brand_id === null || r.brand_id === undefined ? null : Number(r.brand_id),
        deviceTypeId: r.device_type_id === null || r.device_type_id === undefined ? null : Number(r.device_type_id),
        isActive: Number(r.is_active ?? 1) === 1,
        createdAt: String(r.created_at ?? ''),
        updatedAt: String(r.updated_at ?? ''),
      }));
    } finally {
      await conn.end();
    }
  }

  async createModel(input: {
    empresaId: number;
    name: string;
    brandId: number | null;
    deviceTypeId: number | null;
    isActive: boolean;
  }): Promise<number> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const [r] = await conn.query<{ insertId: number } & RowDataPacket[]>(
        `INSERT INTO models (name, brand_id, device_type_id, is_active) VALUES (?, ?, ?, ?)`,
        [input.name, input.brandId ?? null, input.deviceTypeId ?? null, input.isActive ? 1 : 0],
      );
      return Number((r as unknown as { insertId: number })?.insertId ?? 0);
    } finally {
      await conn.end();
    }
  }

  async updateModel(input: {
    empresaId: number;
    id: number;
    name: string;
    brandId: number | null;
    deviceTypeId: number | null;
    isActive: boolean;
  }): Promise<boolean> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const [r] = await conn.query<RowDataPacket[]>(
        `UPDATE models SET name = ?, brand_id = ?, device_type_id = ?, is_active = ?, updated_at = NOW() WHERE id = ? LIMIT 1`,
        [input.name, input.brandId ?? null, input.deviceTypeId ?? null, input.isActive ? 1 : 0, input.id],
      );
      const affected = Number((r as unknown as { affectedRows?: number })?.affectedRows ?? 0);
      return affected > 0;
    } finally {
      await conn.end();
    }
  }

  async deactivateModel(input: { empresaId: number; id: number }): Promise<boolean> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const [r] = await conn.query<RowDataPacket[]>(
        `UPDATE models SET is_active = 0, updated_at = NOW() WHERE id = ? LIMIT 1`,
        [input.id],
      );
      const affected = Number((r as unknown as { affectedRows?: number })?.affectedRows ?? 0);
      return affected > 0;
    } finally {
      await conn.end();
    }
  }

  async getCompany(input: { empresaId: number }): Promise<CompanyConfigRow> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const [rows] = await conn.query<RowDataPacket[]>(
        'SELECT company_name, company_phone, company_email, company_website, company_address, logo_url FROM company_config ORDER BY id ASC LIMIT 1',
      );
      const r = rows?.[0] ?? {};
      return {
        companyName: String(r.company_name ?? ''),
        companyPhone: String(r.company_phone ?? ''),
        companyEmail: String(r.company_email ?? ''),
        companyWebsite: String(r.company_website ?? ''),
        companyAddress: String(r.company_address ?? ''),
        logoUrl: String(r.logo_url ?? ''),
      };
    } finally {
      await conn.end();
    }
  }

  async updateCompany(input: { empresaId: number } & CompanyConfigRow): Promise<boolean> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      await conn.execute(
        `
        UPDATE company_config
        SET company_name = ?, company_phone = ?, company_email = ?, company_website = ?, company_address = ?, logo_url = ?, updated_at = NOW()
        ORDER BY id ASC
        LIMIT 1
        `,
        [
          input.companyName,
          input.companyPhone,
          input.companyEmail,
          input.companyWebsite,
          input.companyAddress,
          input.logoUrl,
        ],
      );
      return true;
    } catch {
      return false;
    } finally {
      await conn.end();
    }
  }

  async getRegional(input: { empresaId: number }): Promise<RegionalConfigRow> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const currency = await this.getConfig(conn, 'currency');
      const currencySymbol = await this.getConfig(conn, 'currency_symbol');
      const taxEnabled = await this.getConfig(conn, 'tax_enabled');
      const taxName = await this.getConfig(conn, 'tax_name');
      const taxRate = await this.getConfig(conn, 'tax_rate');
      const invoiceDueDaysDefault = await this.getConfig(conn, 'invoice_due_days_default');
      return {
        currency: currency || 'COP',
        currencySymbol: currencySymbol || '$',
        taxEnabled: asBool(taxEnabled),
        taxName: taxName || 'IVA',
        taxRate: asNumber(taxRate || 0),
        invoiceDueDaysDefault: Math.max(0, Math.floor(asNumber(invoiceDueDaysDefault || 0))),
      };
    } finally {
      await conn.end();
    }
  }

  async updateRegional(input: { empresaId: number } & RegionalConfigRow): Promise<boolean> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      await this.setConfig(conn, 'currency', input.currency);
      await this.setConfig(conn, 'currency_symbol', input.currencySymbol);
      await this.setConfig(conn, 'tax_enabled', input.taxEnabled ? '1' : '0');
      await this.setConfig(conn, 'tax_name', input.taxName);
      await this.setConfig(conn, 'tax_rate', String(asNumber(input.taxRate)));
      await this.setConfig(conn, 'invoice_due_days_default', String(Math.max(0, Math.floor(input.invoiceDueDaysDefault))));
      return true;
    } catch {
      return false;
    } finally {
      await conn.end();
    }
  }

  async getWhatsappTemplates(input: { empresaId: number }): Promise<{
    reception: string;
    ready: string;
    delivery: string;
    sale: string;
  }> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      return {
        reception: await this.getConfig(conn, 'whatsapp_template_reception'),
        ready: await this.getConfig(conn, 'whatsapp_template_ready'),
        delivery: await this.getConfig(conn, 'whatsapp_template_delivery'),
        sale: await this.getConfig(conn, 'whatsapp_template_sale'),
      };
    } finally {
      await conn.end();
    }
  }

  async updateWhatsappTemplates(input: {
    empresaId: number;
    reception: string;
    ready: string;
    delivery: string;
    sale: string;
  }): Promise<boolean> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      await this.setConfig(conn, 'whatsapp_template_reception', input.reception);
      await this.setConfig(conn, 'whatsapp_template_ready', input.ready);
      await this.setConfig(conn, 'whatsapp_template_delivery', input.delivery);
      await this.setConfig(conn, 'whatsapp_template_sale', input.sale);
      return true;
    } catch {
      return false;
    } finally {
      await conn.end();
    }
  }

  async getAppearance(input: { empresaId: number }): Promise<{ themeMode: 'light' | 'dark' }> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const themeMode = (await this.getConfig(conn, 'theme_mode')).trim().toLowerCase();
      return { themeMode: themeMode === 'dark' ? 'dark' : 'light' };
    } finally {
      await conn.end();
    }
  }

  async updateAppearance(input: { empresaId: number; themeMode: 'light' | 'dark' }): Promise<boolean> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      await this.setConfig(conn, 'theme_mode', input.themeMode);
      await this.setConfig(conn, 'theme_color', 'black');
      return true;
    } catch {
      return false;
    } finally {
      await conn.end();
    }
  }

  async listPaymentMethods(input: { empresaId: number; onlyActive?: boolean }): Promise<PaymentMethodRow[]> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const where = input.onlyActive ? 'WHERE is_active = 1' : '';
      const [rows] = await conn.query<RowDataPacket[]>(
        `
        SELECT id, name, is_default, is_active, created_at
        FROM payment_methods
        ${where}
        ORDER BY is_default DESC, name ASC
        `,
      );
      return (rows ?? []).map((r) => ({
        id: Number(r.id),
        name: String(r.name ?? ''),
        isDefault: Number(r.is_default ?? 0) === 1,
        isActive: Number(r.is_active ?? 1) === 1,
        createdAt: String(r.created_at ?? ''),
      }));
    } finally {
      await conn.end();
    }
  }

  async createPaymentMethod(input: { empresaId: number; name: string; isDefault: boolean; isActive: boolean }): Promise<number> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      if (input.isDefault) {
        try {
          await conn.query('UPDATE payment_methods SET is_default = 0');
        } catch {
        }
      }
      const [r] = await conn.query<{ insertId: number } & RowDataPacket[]>(
        `INSERT INTO payment_methods (name, is_default, is_active) VALUES (?, ?, ?)`,
        [input.name, input.isDefault ? 1 : 0, input.isActive ? 1 : 0],
      );
      return Number((r as unknown as { insertId: number })?.insertId ?? 0);
    } finally {
      await conn.end();
    }
  }

  async updatePaymentMethod(input: {
    empresaId: number;
    id: number;
    name: string;
    isDefault: boolean;
    isActive: boolean;
  }): Promise<boolean> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      if (input.isDefault) {
        try {
          await conn.query('UPDATE payment_methods SET is_default = 0');
        } catch {
        }
      }
      const [r] = await conn.query<RowDataPacket[]>(
        `UPDATE payment_methods SET name = ?, is_default = ?, is_active = ?, updated_at = NOW() WHERE id = ? LIMIT 1`,
        [input.name, input.isDefault ? 1 : 0, input.isActive ? 1 : 0, input.id],
      );
      const affected = Number((r as unknown as { affectedRows?: number })?.affectedRows ?? 0);
      return affected > 0;
    } finally {
      await conn.end();
    }
  }

  async deactivatePaymentMethod(input: { empresaId: number; id: number }): Promise<boolean> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const [r] = await conn.query<RowDataPacket[]>(
        `UPDATE payment_methods SET is_active = 0, is_default = 0, updated_at = NOW() WHERE id = ? LIMIT 1`,
        [input.id],
      );
      const affected = Number((r as unknown as { affectedRows?: number })?.affectedRows ?? 0);
      return affected > 0;
    } finally {
      await conn.end();
    }
  }

  async listPaymentAccounts(input: {
    empresaId: number;
    paymentMethodId: number;
    onlyActive?: boolean;
  }): Promise<PaymentAccountRow[]> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const where = input.onlyActive ? 'AND a.is_active = 1' : '';
      const [rows] = await conn.query<RowDataPacket[]>(
        `
        SELECT a.id, a.payment_method_id, a.alias, a.account_number, a.account_type, a.holder_name, a.holder_id, a.is_active
        FROM payment_method_accounts a
        WHERE a.payment_method_id = ?
        ${where}
        ORDER BY a.id DESC
        `,
        [input.paymentMethodId],
      );
      return (rows ?? []).map((r) => ({
        id: Number(r.id),
        paymentMethodId: Number(r.payment_method_id),
        alias: String(r.alias ?? ''),
        accountNumber: String(r.account_number ?? ''),
        accountType: String(r.account_type ?? ''),
        holderName: String(r.holder_name ?? ''),
        holderId: String(r.holder_id ?? ''),
        isActive: Number(r.is_active ?? 1) === 1,
      }));
    } finally {
      await conn.end();
    }
  }

  async createPaymentAccount(input: {
    empresaId: number;
    paymentMethodId: number;
    alias: string;
    accountNumber: string;
    accountType: string;
    holderName: string;
    holderId: string;
    isActive: boolean;
  }): Promise<number> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const [r] = await conn.query<{ insertId: number } & RowDataPacket[]>(
        `
        INSERT INTO payment_method_accounts (
          payment_method_id, alias, account_number, account_type, holder_name, holder_id, is_active
        )
        VALUES (?, ?, ?, ?, ?, ?, ?)
        `,
        [
          input.paymentMethodId,
          input.alias,
          input.accountNumber,
          input.accountType,
          input.holderName,
          input.holderId,
          input.isActive ? 1 : 0,
        ],
      );
      return Number((r as unknown as { insertId: number })?.insertId ?? 0);
    } finally {
      await conn.end();
    }
  }

  async updatePaymentAccount(input: {
    empresaId: number;
    id: number;
    paymentMethodId: number;
    alias: string;
    accountNumber: string;
    accountType: string;
    holderName: string;
    holderId: string;
    isActive: boolean;
  }): Promise<boolean> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const [r] = await conn.query<RowDataPacket[]>(
        `
        UPDATE payment_method_accounts
        SET payment_method_id = ?, alias = ?, account_number = ?, account_type = ?, holder_name = ?, holder_id = ?, is_active = ?, updated_at = NOW()
        WHERE id = ?
        LIMIT 1
        `,
        [
          input.paymentMethodId,
          input.alias,
          input.accountNumber,
          input.accountType,
          input.holderName,
          input.holderId,
          input.isActive ? 1 : 0,
          input.id,
        ],
      );
      const affected = Number((r as unknown as { affectedRows?: number })?.affectedRows ?? 0);
      return affected > 0;
    } finally {
      await conn.end();
    }
  }

  async deactivatePaymentAccount(input: { empresaId: number; id: number }): Promise<boolean> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const [r] = await conn.query<RowDataPacket[]>(
        `UPDATE payment_method_accounts SET is_active = 0, updated_at = NOW() WHERE id = ? LIMIT 1`,
        [input.id],
      );
      const affected = Number((r as unknown as { affectedRows?: number })?.affectedRows ?? 0);
      return affected > 0;
    } finally {
      await conn.end();
    }
  }
}
