import { Inject, Injectable } from '@nestjs/common';
import type { RowDataPacket } from 'mysql2';
import type { MasterDbPool } from '../../../infrastructure/db/master.pool';
import { encryptMaster } from '../../../infrastructure/crypto/masterCrypto';

export type DbPoolStatus = 'available' | 'reserved' | 'used' | 'error';

export type DbPoolItemRow = {
  id: number;
  dbHost: string;
  dbPort: number;
  dbName: string;
  dbUser: string;
  status: DbPoolStatus;
  empresaId: number | null;
  empresaNombre: string | null;
  reservedAt: string | null;
  usedAt: string | null;
  createdAt: string;
  lastError: string | null;
};

export type DbPoolStatsRow = Record<DbPoolStatus, number>;

@Injectable()
export class DbPoolDao {
  constructor(@Inject('MASTER_DB_POOL') private readonly pool: MasterDbPool) {}

  private async ensureSchema(): Promise<void> {
    await this.pool.query(
      `
      CREATE TABLE IF NOT EXISTS tenant_db_pool (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        db_host VARCHAR(255) NOT NULL DEFAULT 'localhost',
        db_port INT NOT NULL DEFAULT 3306,
        db_name VARCHAR(64) NOT NULL,
        db_user VARCHAR(64) NOT NULL,
        db_password_enc TEXT NOT NULL,
        db_password_iv VARCHAR(255) NOT NULL,
        db_password_tag VARCHAR(255) NOT NULL,
        status ENUM('available','reserved','used','error') NOT NULL DEFAULT 'available',
        empresa_id INT NULL DEFAULT NULL,
        reserved_at DATETIME NULL DEFAULT NULL,
        used_at DATETIME NULL DEFAULT NULL,
        last_error TEXT NULL DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_db_name (db_name),
        KEY idx_status (status),
        KEY idx_empresa (empresa_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
      `,
    );
  }

  async stats(): Promise<DbPoolStatsRow> {
    await this.ensureSchema();
    const base: DbPoolStatsRow = { available: 0, reserved: 0, used: 0, error: 0 };
    const [rows] = await this.pool.query<RowDataPacket[]>(
      `
      SELECT status, COUNT(*) as c
      FROM tenant_db_pool
      GROUP BY status
      `,
    );
    for (const r of rows ?? []) {
      const s = String(r.status ?? '') as DbPoolStatus;
      if (s in base) base[s] = Number(r.c ?? 0);
    }
    return base;
  }

  async list(): Promise<DbPoolItemRow[]> {
    await this.ensureSchema();
    const [rows] = await this.pool.query<RowDataPacket[]>(
      `
      SELECT
        p.id,
        p.db_host,
        p.db_port,
        p.db_name,
        p.db_user,
        p.status,
        p.empresa_id,
        e.nombre AS empresa_nombre,
        p.reserved_at,
        p.used_at,
        p.created_at,
        p.last_error
      FROM tenant_db_pool p
      LEFT JOIN empresas e ON e.id = p.empresa_id
      ORDER BY p.id DESC
      LIMIT 200
      `,
    );
    return (rows ?? []).map((r) => ({
      id: Number(r.id ?? 0),
      dbHost: String(r.db_host ?? ''),
      dbPort: Number(r.db_port ?? 0),
      dbName: String(r.db_name ?? ''),
      dbUser: String(r.db_user ?? ''),
      status: String(r.status ?? 'available') as DbPoolStatus,
      empresaId: r.empresa_id ? Number(r.empresa_id) : null,
      empresaNombre: r.empresa_nombre ? String(r.empresa_nombre) : null,
      reservedAt: r.reserved_at ? String(r.reserved_at) : null,
      usedAt: r.used_at ? String(r.used_at) : null,
      createdAt: String(r.created_at ?? ''),
      lastError: r.last_error ? String(r.last_error) : null,
    }));
  }

  async add(input: {
    dbHost: string;
    dbPort: number;
    dbName: string;
    dbUser: string;
    dbPass: string;
  }): Promise<number> {
    await this.ensureSchema();
    const enc = encryptMaster(input.dbPass);
    const [result] = await this.pool.execute(
      `
      INSERT INTO tenant_db_pool
        (db_host, db_port, db_name, db_user, db_password_enc, db_password_iv, db_password_tag, status, created_at, updated_at)
      VALUES
        (?, ?, ?, ?, ?, ?, ?, 'available', NOW(), NOW())
      `,
      [
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

  async syncFromEmpresas(): Promise<{ added: number; skipped: number }> {
    await this.ensureSchema();
    const existing = new Set<string>();
    const [poolRows] = await this.pool.query<RowDataPacket[]>('SELECT db_name FROM tenant_db_pool');
    for (const r of poolRows ?? []) {
      const n = String(r.db_name ?? '').trim();
      if (n) existing.add(n);
    }

    const [empRows] = await this.pool.query<RowDataPacket[]>(
      `
      SELECT
        id AS empresa_id,
        db_host,
        db_port,
        db_name,
        db_user,
        db_password_enc,
        db_password_iv,
        db_password_tag
      FROM empresas
      WHERE estado <> 'deleted'
      ORDER BY id ASC
      `,
    );

    let added = 0;
    let skipped = 0;
    for (const r of empRows ?? []) {
      const empresaId = Number(r.empresa_id ?? 0);
      const dbName = String(r.db_name ?? '').trim();
      const dbUser = String(r.db_user ?? '').trim();
      if (!empresaId || !dbName || !dbUser) {
        skipped++;
        continue;
      }
      if (existing.has(dbName)) {
        skipped++;
        continue;
      }
      await this.pool.execute(
        `
        INSERT INTO tenant_db_pool
          (db_host, db_port, db_name, db_user, db_password_enc, db_password_iv, db_password_tag, status, empresa_id, reserved_at, used_at, created_at, updated_at)
        VALUES
          (?, ?, ?, ?, ?, ?, ?, 'used', ?, NULL, NOW(), NOW(), NOW())
        `,
        [
          String(r.db_host ?? 'localhost'),
          Number(r.db_port ?? 3306),
          dbName,
          dbUser,
          String(r.db_password_enc ?? ''),
          String(r.db_password_iv ?? ''),
          String(r.db_password_tag ?? ''),
          empresaId,
        ],
      );
      existing.add(dbName);
      added++;
    }

    return { added, skipped };
  }

  async markAvailable(id: number): Promise<boolean> {
    await this.ensureSchema();
    const [result] = await this.pool.execute(
      `
      UPDATE tenant_db_pool
      SET status = 'available', empresa_id = NULL, reserved_at = NULL, used_at = NULL, last_error = NULL, updated_at = NOW()
      WHERE id = ? AND status <> 'used'
      `,
      [id],
    );
    const anyResult = result as { affectedRows?: number };
    return Number(anyResult.affectedRows ?? 0) > 0;
  }
}

