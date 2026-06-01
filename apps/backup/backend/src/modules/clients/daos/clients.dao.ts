import { Inject, Injectable } from '@nestjs/common';
import type { RowDataPacket } from 'mysql2';
import type { MasterDbPool } from '../../../infrastructure/db/master.pool';
import { createTenantConnection } from '../../../infrastructure/db/tenant.connection';

function digitsOnly(v: string): string {
  return v.replace(/\D/g, '');
}

export type ClientRow = {
  id: number;
  clientType: string;
  firstName: string;
  companyName: string;
  taxId: string;
  legalRepresentative: string;
  phone: string;
  email: string;
  idNumber: string;
  address: string;
  notes: string;
  clientNumber: number | null;
  createdAt: string;
};

@Injectable()
export class ClientsDao {
  constructor(@Inject('MASTER_DB_POOL') private readonly masterPool: MasterDbPool) {}

  private async ensureClientNumber(conn: Awaited<ReturnType<typeof createTenantConnection>>) {
    try {
      await conn.query('SELECT client_number FROM clients LIMIT 1');
      return;
    } catch {
    }
    try {
      await conn.query(
        'ALTER TABLE clients ADD COLUMN client_number INT(11) NOT NULL DEFAULT 0 AFTER id',
      );
    } catch {
    }
  }

  private async nextClientNumber(conn: Awaited<ReturnType<typeof createTenantConnection>>) {
    try {
      const [rows] = await conn.query<RowDataPacket[]>(
        'SELECT MAX(client_number) as m FROM clients',
      );
      const maxDb = Number(rows?.[0]?.m ?? 0);
      return maxDb + 1;
    } catch {
      return 0;
    }
  }

  async list(input: {
    empresaId: number;
    search: string;
    limit: number;
    offset: number;
  }): Promise<{ rows: ClientRow[]; total: number }> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureClientNumber(conn);
      const search = input.search.trim();
      const where: string[] = [];
      const params: Array<string | number> = [];
      if (search) {
        where.push(
          '(first_name LIKE ? OR company_name LIKE ? OR email LIKE ? OR phone LIKE ? OR id_number LIKE ?)',
        );
        const sp = `%${search}%`;
        params.push(sp, sp, sp, sp, sp);
      }
      const whereSql = where.length ? `WHERE ${where.join(' AND ')}` : '';
      const [countRows] = await conn.query<RowDataPacket[]>(
        `SELECT COUNT(*) as total FROM clients ${whereSql}`,
        params,
      );
      const total = Number(countRows?.[0]?.total ?? 0);
      const [rows] = await conn.query<RowDataPacket[]>(
        `
        SELECT id, client_type, first_name, company_name, tax_id, legal_representative, phone, email, id_number, address, notes, created_at, client_number
        FROM clients
        ${whereSql}
        ORDER BY created_at DESC
        LIMIT ? OFFSET ?
        `,
        [...params, input.limit, input.offset],
      );
      return {
        total,
        rows: (rows ?? []).map((r) => ({
          id: Number(r.id),
          clientType: String(r.client_type ?? ''),
          firstName: String(r.first_name ?? ''),
          companyName: String(r.company_name ?? ''),
          taxId: String(r.tax_id ?? ''),
          legalRepresentative: String(r.legal_representative ?? ''),
          phone: String(r.phone ?? ''),
          email: String(r.email ?? ''),
          idNumber: String(r.id_number ?? ''),
          address: String(r.address ?? ''),
          notes: String(r.notes ?? ''),
          clientNumber:
            r.client_number === undefined || r.client_number === null
              ? null
              : Number(r.client_number),
          createdAt: String(r.created_at ?? ''),
        })),
      };
    } finally {
      await conn.end();
    }
  }

  async getById(input: { empresaId: number; id: number }): Promise<ClientRow | null> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      await this.ensureClientNumber(conn);
      const [rows] = await conn.query<RowDataPacket[]>(
        `
        SELECT id, client_type, first_name, company_name, tax_id, legal_representative, phone, email, id_number, address, notes, created_at, client_number
        FROM clients
        WHERE id = ?
        LIMIT 1
        `,
        [input.id],
      );
      const r = rows?.[0];
      if (!r) return null;
      return {
        id: Number(r.id),
        clientType: String(r.client_type ?? ''),
        firstName: String(r.first_name ?? ''),
        companyName: String(r.company_name ?? ''),
        taxId: String(r.tax_id ?? ''),
        legalRepresentative: String(r.legal_representative ?? ''),
        phone: String(r.phone ?? ''),
        email: String(r.email ?? ''),
        idNumber: String(r.id_number ?? ''),
        address: String(r.address ?? ''),
        notes: String(r.notes ?? ''),
        clientNumber:
          r.client_number === undefined || r.client_number === null ? null : Number(r.client_number),
        createdAt: String(r.created_at ?? ''),
      };
    } finally {
      await conn.end();
    }
  }

  async existsDuplicate(input: {
    empresaId: number;
    idToExclude?: number;
    clientType: 'individual' | 'company';
    taxId?: string | null;
    idNumber?: string | null;
  }): Promise<boolean> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      if (input.clientType === 'company' && input.taxId) {
        const norm = digitsOnly(input.taxId);
        const params: Array<string | number> = [norm];
        let sql =
          "SELECT id FROM clients WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(tax_id,'-',''),' ',''),'.',''),'/',''),'_','') = ?";
        if (input.idToExclude) {
          sql += ' AND id != ?';
          params.push(input.idToExclude);
        }
        sql += ' LIMIT 1';
        const [rows] = await conn.query<RowDataPacket[]>(sql, params);
        return Boolean(rows?.[0]);
      }
      if (input.clientType === 'individual' && input.idNumber) {
        const norm = digitsOnly(input.idNumber);
        const params: Array<string | number> = [norm];
        let sql =
          "SELECT id FROM clients WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(id_number,'-',''),' ',''),'.',''),'/',''),'_','') = ?";
        if (input.idToExclude) {
          sql += ' AND id != ?';
          params.push(input.idToExclude);
        }
        sql += ' LIMIT 1';
        const [rows] = await conn.query<RowDataPacket[]>(sql, params);
        return Boolean(rows?.[0]);
      }
      return false;
    } finally {
      await conn.end();
    }
  }

  async create(input: {
    empresaId: number;
    clientType: 'individual' | 'company';
    name: string | null;
    companyName: string | null;
    taxId: string | null;
    legalRepresentative: string | null;
    phone: string;
    email: string | null;
    idNumber: string | null;
    address: string | null;
    notes: string | null;
  }): Promise<number> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      const taxId = input.taxId ? digitsOnly(input.taxId) : null;
      const idNumber = input.idNumber ? digitsOnly(input.idNumber) : null;

      const [result] = await conn.execute(
        `
        INSERT INTO clients (client_type, first_name, company_name, tax_id, legal_representative, phone, email, id_number, address, notes, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        `,
        [
          input.clientType,
          input.name,
          input.companyName,
          taxId,
          input.legalRepresentative,
          input.phone,
          input.email,
          idNumber,
          input.address,
          input.notes,
        ],
      );
      const anyResult = result as { insertId?: number };
      const id = Number(anyResult.insertId ?? 0);

      await this.ensureClientNumber(conn);
      const next = await this.nextClientNumber(conn);
      if (next > 0) {
        try {
          await conn.execute('UPDATE clients SET client_number = ? WHERE id = ?', [next, id]);
        } catch {
        }
      }
      return id;
    } finally {
      await conn.end();
    }
  }

  async update(input: {
    empresaId: number;
    id: number;
    clientType: 'individual' | 'company';
    name: string | null;
    companyName: string | null;
    taxId: string | null;
    legalRepresentative: string | null;
    phone: string;
    email: string | null;
    idNumber: string | null;
    address: string | null;
    notes: string | null;
  }): Promise<boolean> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      const taxId = input.taxId ? digitsOnly(input.taxId) : null;
      const idNumber = input.idNumber ? digitsOnly(input.idNumber) : null;
      const [result] = await conn.execute(
        `
        UPDATE clients
        SET client_type = ?, first_name = ?, company_name = ?, tax_id = ?, legal_representative = ?,
            phone = ?, email = ?, id_number = ?, address = ?, notes = ?, updated_at = NOW()
        WHERE id = ?
        `,
        [
          input.clientType,
          input.name,
          input.companyName,
          taxId,
          input.legalRepresentative,
          input.phone,
          input.email,
          idNumber,
          input.address,
          input.notes,
          input.id,
        ],
      );
      const anyResult = result as { affectedRows?: number };
      return Number(anyResult.affectedRows ?? 0) > 0;
    } finally {
      await conn.end();
    }
  }

  async delete(input: { empresaId: number; id: number }): Promise<boolean> {
    const conn = await createTenantConnection(this.masterPool, input.empresaId);
    try {
      const [result] = await conn.execute('DELETE FROM clients WHERE id = ? LIMIT 1', [input.id]);
      const anyResult = result as { affectedRows?: number };
      return Number(anyResult.affectedRows ?? 0) > 0;
    } finally {
      await conn.end();
    }
  }

  async stats(empresaId: number): Promise<{
    totalClients: number;
    individualClients: number;
    companyClients: number;
    recentClients: number;
  }> {
    const conn = await createTenantConnection(this.masterPool, empresaId);
    try {
      const [rows] = await conn.query<RowDataPacket[]>(
        `
        SELECT 
          COUNT(*) as total_clients,
          SUM(CASE WHEN client_type = 'individual' THEN 1 ELSE 0 END) as individual_clients,
          SUM(CASE WHEN client_type = 'company' THEN 1 ELSE 0 END) as company_clients,
          COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 END) as recent_clients
        FROM clients
        `,
      );
      const r = rows?.[0] ?? {};
      return {
        totalClients: Number(r.total_clients ?? 0),
        individualClients: Number(r.individual_clients ?? 0),
        companyClients: Number(r.company_clients ?? 0),
        recentClients: Number(r.recent_clients ?? 0),
      };
    } finally {
      await conn.end();
    }
  }
}
