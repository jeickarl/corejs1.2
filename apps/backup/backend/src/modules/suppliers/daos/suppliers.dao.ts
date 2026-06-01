import { Inject, Injectable } from '@nestjs/common';
import type { RowDataPacket } from 'mysql2';
import type { MasterDbPool } from '../../../infrastructure/db/master.pool';
import { createTenantConnection } from '../../../infrastructure/db/tenant.connection';

export type SupplierRow = {
  id: number;
  supplierCode: string;
  supplierType: string;
  companyName: string;
  contactName: string;
  taxId: string;
  phone: string;
  mobile: string;
  email: string;
  website: string;
  address: string;
  city: string;
  state: string;
  country: string;
  postalCode: string;
  paymentTerms: string;
  creditLimit: number | null;
  discountPercentage: number | null;
  bankName: string;
  accountNumber: string;
  accountType: string;
  isActive: boolean;
  rating: number | null;
  notes: string;
  createdAt: string;
  updatedAt: string;
};

type SupplierSchema = 'v2' | 'v1';

@Injectable()
export class SuppliersDao {
  constructor(@Inject('MASTER_DB_POOL') private readonly masterPool: MasterDbPool) {}

  private async ensureSchema(conn: Awaited<ReturnType<typeof createTenantConnection>>) {
    try {
      await conn.query(
        `
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
      );
    } catch {
    }
  }

  private async detectSchema(conn: Awaited<ReturnType<typeof createTenantConnection>>): Promise<SupplierSchema> {
    try {
      await conn.query('SELECT company_name FROM suppliers LIMIT 1');
      return 'v2';
    } catch {
      return 'v1';
    }
  }

  private mapRowV2(r: RowDataPacket): SupplierRow {
    return {
      id: Number(r.id),
      supplierCode: String(r.supplier_code ?? ''),
      supplierType: String(r.supplier_type ?? ''),
      companyName: String(r.company_name ?? ''),
      contactName: String(r.contact_name ?? ''),
      taxId: String(r.tax_id ?? ''),
      phone: String(r.phone ?? ''),
      mobile: String(r.mobile ?? ''),
      email: String(r.email ?? ''),
      website: String(r.website ?? ''),
      address: String(r.address ?? ''),
      city: String(r.city ?? ''),
      state: String(r.state ?? ''),
      country: String(r.country ?? ''),
      postalCode: String(r.postal_code ?? ''),
      paymentTerms: String(r.payment_terms ?? ''),
      creditLimit: r.credit_limit === undefined || r.credit_limit === null ? null : Number(r.credit_limit),
      discountPercentage:
        r.discount_percentage === undefined || r.discount_percentage === null ? null : Number(r.discount_percentage),
      bankName: String(r.bank_name ?? ''),
      accountNumber: String(r.account_number ?? ''),
      accountType: String(r.account_type ?? ''),
      isActive: Boolean(r.is_active ?? true),
      rating: r.rating === undefined || r.rating === null ? null : Number(r.rating),
      notes: String(r.notes ?? ''),
      createdAt: String(r.created_at ?? ''),
      updatedAt: String(r.updated_at ?? ''),
    };
  }

  private mapRowV1(r: RowDataPacket): SupplierRow {
    return {
      id: Number(r.id),
      supplierCode: String(r.supplier_code ?? ''),
      supplierType: String(r.supplier_type ?? ''),
      companyName: String(r.name ?? ''),
      contactName: String(r.contact_name ?? r.contact_person ?? ''),
      taxId: String(r.tax_id ?? ''),
      phone: String(r.phone ?? ''),
      mobile: String(r.mobile ?? ''),
      email: String(r.email ?? ''),
      website: String(r.website ?? ''),
      address: String(r.address ?? ''),
      city: String(r.city ?? ''),
      state: String(r.state ?? ''),
      country: String(r.country ?? ''),
      postalCode: String(r.postal_code ?? ''),
      paymentTerms: String(r.payment_terms ?? ''),
      creditLimit: r.credit_limit === undefined || r.credit_limit === null ? null : Number(r.credit_limit),
      discountPercentage:
        r.discount_percentage === undefined || r.discount_percentage === null ? null : Number(r.discount_percentage),
      bankName: String(r.bank_name ?? r.bank ?? ''),
      accountNumber: String(r.account_number ?? ''),
      accountType: String(r.account_type ?? ''),
      isActive: Boolean(r.is_active ?? true),
      rating: r.rating === undefined || r.rating === null ? null : Number(r.rating),
      notes: String(r.notes ?? ''),
      createdAt: String(r.created_at ?? ''),
      updatedAt: String(r.updated_at ?? ''),
    };
  }

  async list(input: {
    empresaId: number;
    search: string;
    onlyActive: boolean | null;
    limit: number;
    offset: number;
  }): Promise<{ rows: SupplierRow[]; total: number }> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const schema = await this.detectSchema(conn);
      const search = input.search.trim();
      const where: string[] = [];
      const params: Array<string | number> = [];

      const isActiveCol = 'is_active';
      if (input.onlyActive === true) {
        where.push(`${isActiveCol} = 1`);
      } else if (input.onlyActive === false) {
        where.push(`${isActiveCol} = 0`);
      }

      if (search) {
        const sp = `%${search}%`;
        if (schema === 'v2') {
          where.push('(company_name LIKE ? OR contact_name LIKE ? OR tax_id LIKE ? OR phone LIKE ? OR email LIKE ?)');
          params.push(sp, sp, sp, sp, sp);
        } else {
          where.push('(name LIKE ? OR contact_name LIKE ? OR tax_id LIKE ? OR phone LIKE ? OR email LIKE ?)');
          params.push(sp, sp, sp, sp, sp);
        }
      }

      const whereSql = where.length ? `WHERE ${where.join(' AND ')}` : '';
      const [countRows] = await conn.query<RowDataPacket[]>(
        `SELECT COUNT(*) as total FROM suppliers ${whereSql}`,
        params,
      );
      const total = Number(countRows?.[0]?.total ?? 0);

      const selectSql =
        schema === 'v2'
          ? `
          SELECT id, supplier_code, supplier_type, company_name, contact_name, tax_id, phone, mobile, email, website,
                 address, city, state, country, postal_code, payment_terms, credit_limit, discount_percentage, bank_name,
                 account_number, account_type, is_active, rating, notes, created_at, updated_at
          FROM suppliers
          ${whereSql}
          ORDER BY created_at DESC
          LIMIT ? OFFSET ?
          `
          : `
          SELECT *
          FROM suppliers
          ${whereSql}
          ORDER BY created_at DESC
          LIMIT ? OFFSET ?
          `;

      const [rows] = await conn.query<RowDataPacket[]>(selectSql, [...params, input.limit, input.offset]);
      return {
        total,
        rows: (rows ?? []).map((r) => (schema === 'v2' ? this.mapRowV2(r) : this.mapRowV1(r))),
      };
    } finally {
      await conn.end();
    }
  }

  async getById(input: { empresaId: number; id: number }): Promise<SupplierRow | null> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const schema = await this.detectSchema(conn);
      const selectSql =
        schema === 'v2'
          ? `
          SELECT id, supplier_code, supplier_type, company_name, contact_name, tax_id, phone, mobile, email, website,
                 address, city, state, country, postal_code, payment_terms, credit_limit, discount_percentage, bank_name,
                 account_number, account_type, is_active, rating, notes, created_at, updated_at
          FROM suppliers
          WHERE id = ?
          LIMIT 1
          `
          : `
          SELECT *
          FROM suppliers
          WHERE id = ?
          LIMIT 1
          `;
      const [rows] = await conn.query<RowDataPacket[]>(selectSql, [input.id]);
      const r = rows?.[0];
      if (!r) return null;
      return schema === 'v2' ? this.mapRowV2(r) : this.mapRowV1(r);
    } finally {
      await conn.end();
    }
  }

  async existsDuplicate(input: { empresaId: number; supplierCode: string | null; taxId: string | null; idToExclude?: number }): Promise<boolean> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const schema = await this.detectSchema(conn);
      const where: string[] = [];
      const params: Array<string | number> = [];

      if (input.supplierCode) {
        where.push('supplier_code = ?');
        params.push(input.supplierCode);
      }
      if (input.taxId) {
        where.push('tax_id = ?');
        params.push(input.taxId);
      }
      if (!where.length) return false;

      let sql = `SELECT id FROM suppliers WHERE (${where.join(' OR ')})`;
      if (input.idToExclude) {
        sql += ' AND id != ?';
        params.push(input.idToExclude);
      }
      sql += ' LIMIT 1';

      if (schema === 'v1') {
        const [rows] = await conn.query<RowDataPacket[]>(sql, params);
        return Boolean(rows?.[0]);
      }

      const [rows] = await conn.query<RowDataPacket[]>(sql, params);
      return Boolean(rows?.[0]);
    } finally {
      await conn.end();
    }
  }

  async create(input: {
    empresaId: number;
    supplierCode: string | null;
    supplierType: string | null;
    companyName: string;
    contactName: string | null;
    taxId: string | null;
    phone: string | null;
    mobile: string | null;
    email: string | null;
    website: string | null;
    address: string | null;
    city: string | null;
    state: string | null;
    country: string | null;
    postalCode: string | null;
    paymentTerms: string | null;
    creditLimit: number | null;
    discountPercentage: number | null;
    bankName: string | null;
    accountNumber: string | null;
    accountType: string | null;
    rating: number | null;
    notes: string | null;
  }): Promise<number> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const schema = await this.detectSchema(conn);
      if (schema === 'v2') {
        const [result] = await conn.execute(
          `
          INSERT INTO suppliers (
            supplier_code, supplier_type, company_name, contact_name, tax_id, phone, mobile, email, website,
            address, city, state, country, postal_code, payment_terms, credit_limit, discount_percentage,
            bank_name, account_number, account_type, is_active, rating, notes, created_at, updated_at
          )
          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, NOW(), NOW())
          `,
          [
            input.supplierCode,
            input.supplierType,
            input.companyName,
            input.contactName,
            input.taxId,
            input.phone,
            input.mobile,
            input.email,
            input.website,
            input.address,
            input.city,
            input.state,
            input.country,
            input.postalCode,
            input.paymentTerms,
            input.creditLimit,
            input.discountPercentage,
            input.bankName,
            input.accountNumber,
            input.accountType,
            input.rating,
            input.notes,
          ],
        );
        const anyRes = result as unknown as { insertId?: number };
        return Number(anyRes.insertId ?? 0);
      }

      const [result] = await conn.execute(
        `
        INSERT INTO suppliers (name, contact_name, tax_id, phone, email, address, city, bank, account_number, is_active, notes, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW(), NOW())
        `,
        [
          input.companyName,
          input.contactName,
          input.taxId,
          input.phone,
          input.email,
          input.address,
          input.city,
          input.bankName,
          input.accountNumber,
          input.notes,
        ],
      );
      const anyRes = result as unknown as { insertId?: number };
      return Number(anyRes.insertId ?? 0);
    } finally {
      await conn.end();
    }
  }

  async update(input: {
    empresaId: number;
    id: number;
    supplierCode: string | null;
    supplierType: string | null;
    companyName: string;
    contactName: string | null;
    taxId: string | null;
    phone: string | null;
    mobile: string | null;
    email: string | null;
    website: string | null;
    address: string | null;
    city: string | null;
    state: string | null;
    country: string | null;
    postalCode: string | null;
    paymentTerms: string | null;
    creditLimit: number | null;
    discountPercentage: number | null;
    bankName: string | null;
    accountNumber: string | null;
    accountType: string | null;
    isActive: boolean;
    rating: number | null;
    notes: string | null;
  }): Promise<boolean> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      const schema = await this.detectSchema(conn);
      if (schema === 'v2') {
        await conn.execute(
          `
          UPDATE suppliers
          SET supplier_code = ?, supplier_type = ?, company_name = ?, contact_name = ?, tax_id = ?, phone = ?, mobile = ?, email = ?, website = ?,
              address = ?, city = ?, state = ?, country = ?, postal_code = ?, payment_terms = ?, credit_limit = ?, discount_percentage = ?,
              bank_name = ?, account_number = ?, account_type = ?, is_active = ?, rating = ?, notes = ?, updated_at = NOW()
          WHERE id = ?
          `,
          [
            input.supplierCode,
            input.supplierType,
            input.companyName,
            input.contactName,
            input.taxId,
            input.phone,
            input.mobile,
            input.email,
            input.website,
            input.address,
            input.city,
            input.state,
            input.country,
            input.postalCode,
            input.paymentTerms,
            input.creditLimit,
            input.discountPercentage,
            input.bankName,
            input.accountNumber,
            input.accountType,
            input.isActive ? 1 : 0,
            input.rating,
            input.notes,
            input.id,
          ],
        );
        return true;
      }

      await conn.execute(
        `
        UPDATE suppliers
        SET name = ?, contact_name = ?, tax_id = ?, phone = ?, email = ?, address = ?, city = ?, bank = ?, account_number = ?, is_active = ?, notes = ?, updated_at = NOW()
        WHERE id = ?
        `,
        [
          input.companyName,
          input.contactName,
          input.taxId,
          input.phone,
          input.email,
          input.address,
          input.city,
          input.bankName,
          input.accountNumber,
          input.isActive ? 1 : 0,
          input.notes,
          input.id,
        ],
      );
      return true;
    } finally {
      await conn.end();
    }
  }

  async deactivate(input: { empresaId: number; id: number }): Promise<boolean> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureSchema(conn);
      await conn.execute('UPDATE suppliers SET is_active = 0, updated_at = NOW() WHERE id = ?', [input.id]);
      return true;
    } finally {
      await conn.end();
    }
  }
}
