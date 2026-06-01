import { Inject, Injectable } from '@nestjs/common';
import { createConnection } from 'mysql2/promise';
import type { RowDataPacket } from 'mysql2';
import type { MasterDbPool } from '../../../infrastructure/db/master.pool';
import { decryptMaster, encryptMaster } from '../../../infrastructure/crypto/masterCrypto';
import type { Connection } from 'mysql2/promise';

export type TenantRow = {
  id: number;
  companyName: string;
  status: 'active' | 'suspended';
  createdAt: string;
  dbHost?: string;
  dbPort?: number;
  dbName?: string;
  dbUser?: string;
  licenseCount?: number;
  lastLicense?: string | null;
};

@Injectable()
export class TenantsDao {
  constructor(@Inject('MASTER_DB_POOL') private readonly pool: MasterDbPool) {}

  private isPerDatabaseMode(): boolean {
    const saasMode = (process.env.SAAS_DB_MODE ?? '').trim().toLowerCase();
    return saasMode === 'per_database' || saasMode === 'per-db' || saasMode === 'perdb';
  }

  async testRawDb(input: {
    dbHost: string;
    dbPort: number;
    dbName: string;
    dbUser: string;
    dbPass: string;
  }): Promise<void> {
    if (!this.isPerDatabaseMode()) throw new Error('Unsupported mode');
    const conn: Connection = await createConnection({
      host: input.dbHost.trim() || 'localhost',
      port: Number.isFinite(input.dbPort) && input.dbPort > 0 ? input.dbPort : 3306,
      user: input.dbUser.trim(),
      password: input.dbPass,
      database: input.dbName.trim(),
    });
    try {
      await conn.query('SELECT 1');
    } finally {
      await conn.end();
    }
  }

  async createTenant(input: {
    companyName: string;
    dbHost: string;
    dbPort: number;
    dbName: string;
    dbUser: string;
    dbPass: string;
  }): Promise<number> {
    if (!this.isPerDatabaseMode()) throw new Error('Unsupported mode');
    const enc = encryptMaster(input.dbPass);
    const [result] = await this.pool.execute(
      `
      INSERT INTO empresas (nombre, estado, db_host, db_port, db_name, db_user, db_password_enc, db_password_iv, db_password_tag, created_at, updated_at)
      VALUES (?, 'active', ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
      `,
      [
        input.companyName.trim(),
        input.dbHost.trim() || 'localhost',
        Number.isFinite(input.dbPort) && input.dbPort > 0 ? input.dbPort : 3306,
        input.dbName.trim(),
        input.dbUser.trim(),
        enc.enc,
        enc.iv,
        enc.tag,
      ],
    );
    return Number((result as unknown as { insertId?: number })?.insertId ?? 0);
  }

  async list(): Promise<TenantRow[]> {
    const saasMode = (process.env.SAAS_DB_MODE ?? '').trim().toLowerCase();
    const perDatabase =
      saasMode === 'per_database' || saasMode === 'per-db' || saasMode === 'perdb';

    if (perDatabase) {
      const [rows] = await this.pool.query<RowDataPacket[]>(
        `
        SELECT id, nombre, estado, created_at
        FROM empresas
        WHERE estado <> 'deleted'
        ORDER BY created_at DESC
        LIMIT 200
        `,
      );
      return (rows ?? []).map((r) => ({
        id: Number(r.id),
        companyName: String(r.nombre ?? ''),
        status: String(r.estado ?? '') === 'active' ? 'active' : 'suspended',
        createdAt: String(r.created_at ?? ''),
      }));
    }

    const [rows] = await this.pool.query<RowDataPacket[]>(
      `
      SELECT id, company_name, status, created_at
      FROM tenants
      ORDER BY created_at DESC
      LIMIT 200
      `,
    );
    return (rows ?? []).map((r) => ({
      id: Number(r.id),
      companyName: String(r.company_name ?? ''),
      status: String(r.status ?? '') === 'active' ? 'active' : 'suspended',
      createdAt: String(r.created_at ?? ''),
    }));
  }

  async listWithMeta(): Promise<TenantRow[]> {
    const saasMode = (process.env.SAAS_DB_MODE ?? '').trim().toLowerCase();
    const perDatabase =
      saasMode === 'per_database' || saasMode === 'per-db' || saasMode === 'perdb';

    if (perDatabase) {
      const [rows] = await this.pool.query<RowDataPacket[]>(
        `
        SELECT
          e.id,
          e.nombre,
          e.estado,
          e.db_host,
          e.db_port,
          e.db_name,
          e.db_user,
          e.created_at,
          (
            SELECT COUNT(*) FROM licencias l
            WHERE l.empresa_id = e.id AND l.estado = 'usada'
          ) AS license_count,
          (
            SELECT l.codigo FROM licencias l
            WHERE l.empresa_id = e.id AND l.estado = 'usada'
            ORDER BY l.used_at DESC, l.id DESC
            LIMIT 1
          ) AS last_license
        FROM empresas e
        WHERE e.estado <> 'deleted'
        ORDER BY e.created_at DESC
        LIMIT 200
        `,
      );
      return (rows ?? []).map((r) => ({
        id: Number(r.id),
        companyName: String(r.nombre ?? ''),
        status: String(r.estado ?? '') === 'active' ? 'active' : 'suspended',
        createdAt: String(r.created_at ?? ''),
        dbHost: r.db_host ? String(r.db_host) : undefined,
        dbPort: r.db_port ? Number(r.db_port) : undefined,
        dbName: r.db_name ? String(r.db_name) : undefined,
        dbUser: r.db_user ? String(r.db_user) : undefined,
        licenseCount: Number(r.license_count ?? 0),
        lastLicense: r.last_license ? String(r.last_license) : null,
      }));
    }

    return this.list();
  }

  async getById(id: number): Promise<TenantRow | null> {
    const saasMode = (process.env.SAAS_DB_MODE ?? '').trim().toLowerCase();
    const perDatabase =
      saasMode === 'per_database' || saasMode === 'per-db' || saasMode === 'perdb';

    if (perDatabase) {
      const [rows] = await this.pool.query<RowDataPacket[]>(
        `
        SELECT
          e.id,
          e.nombre,
          e.estado,
          e.db_host,
          e.db_port,
          e.db_name,
          e.db_user,
          e.created_at,
          (
            SELECT COUNT(*) FROM licencias l
            WHERE l.empresa_id = e.id AND l.estado = 'usada'
          ) AS license_count,
          (
            SELECT l.codigo FROM licencias l
            WHERE l.empresa_id = e.id AND l.estado = 'usada'
            ORDER BY l.used_at DESC, l.id DESC
            LIMIT 1
          ) AS last_license
        FROM empresas e
        WHERE e.id = ?
        LIMIT 1
        `,
        [id],
      );
      const r = rows?.[0];
      if (!r) return null;
      return {
        id: Number(r.id),
        companyName: String(r.nombre ?? ''),
        status: String(r.estado ?? '') === 'active' ? 'active' : 'suspended',
        createdAt: String(r.created_at ?? ''),
        dbHost: r.db_host ? String(r.db_host) : undefined,
        dbPort: r.db_port ? Number(r.db_port) : undefined,
        dbName: r.db_name ? String(r.db_name) : undefined,
        dbUser: r.db_user ? String(r.db_user) : undefined,
        licenseCount: Number(r.license_count ?? 0),
        lastLicense: r.last_license ? String(r.last_license) : null,
      };
    }

    const [rows] = await this.pool.query<RowDataPacket[]>(
      `
      SELECT id, company_name, status, created_at
      FROM tenants
      WHERE id = ?
      LIMIT 1
      `,
      [id],
    );
    const r = rows?.[0];
    if (!r) return null;
    return {
      id: Number(r.id),
      companyName: String(r.company_name ?? ''),
      status: String(r.status ?? '') === 'active' ? 'active' : 'suspended',
      createdAt: String(r.created_at ?? ''),
    };
  }

  async updateStatus(id: number, status: 'active' | 'suspended'): Promise<void> {
    const saasMode = (process.env.SAAS_DB_MODE ?? '').trim().toLowerCase();
    const perDatabase =
      saasMode === 'per_database' || saasMode === 'per-db' || saasMode === 'perdb';

    if (perDatabase) {
      await this.pool.execute(
        'UPDATE empresas SET estado = ?, updated_at = NOW() WHERE id = ? LIMIT 1',
        [status, id],
      );
      return;
    }

    await this.pool.execute(
      'UPDATE tenants SET status = ?, updated_at = NOW() WHERE id = ? LIMIT 1',
      [status, id],
    );
  }

  async markDeleted(id: number): Promise<boolean> {
    const saasMode = (process.env.SAAS_DB_MODE ?? '').trim().toLowerCase();
    const perDatabase =
      saasMode === 'per_database' || saasMode === 'per-db' || saasMode === 'perdb';
    if (!perDatabase) {
      throw new Error('Unsupported mode');
    }
    const [result] = await this.pool.execute(
      'UPDATE empresas SET estado = ?, updated_at = NOW() WHERE id = ? LIMIT 1',
      ['deleted', id],
    );
    const anyResult = result as { affectedRows?: number };
    return Number(anyResult.affectedRows ?? 0) > 0;
  }

  async updateTenant(input: {
    id: number;
    companyName: string;
    status: 'active' | 'suspended' | 'provisioning';
    dbHost: string;
    dbPort: number;
    dbName: string;
    dbUser: string;
    dbPass: string | null;
  }): Promise<void> {
    const saasMode = (process.env.SAAS_DB_MODE ?? '').trim().toLowerCase();
    const perDatabase =
      saasMode === 'per_database' || saasMode === 'per-db' || saasMode === 'perdb';
    if (!perDatabase) {
      throw new Error('Unsupported mode');
    }

    const dbHost = input.dbHost.trim() || 'localhost';
    const dbPort = Number.isFinite(input.dbPort) && input.dbPort > 0 ? input.dbPort : 3306;
    const dbName = input.dbName.trim();
    const dbUser = input.dbUser.trim();

    if (!input.companyName.trim() || !dbName || !dbUser) {
      throw new Error('Validation error');
    }

    if (input.dbPass && input.dbPass !== '') {
      const enc = encryptMaster(input.dbPass);
      await this.pool.execute(
        `
        UPDATE empresas
        SET nombre = ?, estado = ?, db_host = ?, db_port = ?, db_name = ?, db_user = ?,
            db_password_enc = ?, db_password_iv = ?, db_password_tag = ?, updated_at = NOW()
        WHERE id = ?
        LIMIT 1
        `,
        [
          input.companyName.trim(),
          input.status,
          dbHost,
          dbPort,
          dbName,
          dbUser,
          enc.enc,
          enc.iv,
          enc.tag,
          input.id,
        ],
      );
      return;
    }

    await this.pool.execute(
      `
      UPDATE empresas
      SET nombre = ?, estado = ?, db_host = ?, db_port = ?, db_name = ?, db_user = ?, updated_at = NOW()
      WHERE id = ?
      LIMIT 1
      `,
      [input.companyName.trim(), input.status, dbHost, dbPort, dbName, dbUser, input.id],
    );
  }

  async testTenantDb(input: {
    id: number;
    dbHost: string;
    dbPort: number;
    dbName: string;
    dbUser: string;
    dbPass: string | null;
  }): Promise<void> {
    const saasMode = (process.env.SAAS_DB_MODE ?? '').trim().toLowerCase();
    const perDatabase =
      saasMode === 'per_database' || saasMode === 'per-db' || saasMode === 'perdb';
    if (!perDatabase) {
      throw new Error('Unsupported mode');
    }

    const dbHost = input.dbHost.trim() || 'localhost';
    const dbPort = Number.isFinite(input.dbPort) && input.dbPort > 0 ? input.dbPort : 3306;
    const dbName = input.dbName.trim();
    const dbUser = input.dbUser.trim();
    let dbPass = input.dbPass ?? '';

    if (!dbName || !dbUser) {
      throw new Error('Validation error');
    }

    if (!dbPass) {
      const [rows] = await this.pool.query<RowDataPacket[]>(
        `
        SELECT db_password_enc, db_password_iv, db_password_tag
        FROM empresas
        WHERE id = ?
        LIMIT 1
        `,
        [input.id],
      );
      const r = rows?.[0];
      if (r && r.db_password_enc && r.db_password_iv && r.db_password_tag) {
        dbPass = decryptMaster(
          String(r.db_password_enc),
          String(r.db_password_iv),
          String(r.db_password_tag),
        );
      }
    }

    const conn = await createConnection({
      host: dbHost,
      port: dbPort,
      user: dbUser,
      password: dbPass,
      database: dbName,
    });
    try {
      await conn.query('SELECT 1');
    } finally {
      await conn.end();
    }
  }
}
