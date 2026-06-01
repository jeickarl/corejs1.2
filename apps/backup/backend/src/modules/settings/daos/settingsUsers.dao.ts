import { Inject, Injectable } from '@nestjs/common';
import type { RowDataPacket } from 'mysql2';
import type { MasterDbPool } from '../../../infrastructure/db/master.pool';

export type SettingsUserRow = {
  id: number;
  email: string;
  name: string;
  role: 'admin' | 'user';
  active: boolean;
  createdAt: string;
};

@Injectable()
export class SettingsUsersDao {
  constructor(@Inject('MASTER_DB_POOL') private readonly pool: MasterDbPool) {}

  async listByEmpresaId(empresaId: number): Promise<SettingsUserRow[]> {
    const [rows] = await this.pool.query<RowDataPacket[]>(
      `
      SELECT id, email, nombre, rol, activo, created_at
      FROM usuarios_master
      WHERE empresa_id = ?
      ORDER BY id DESC
      LIMIT 200
      `,
      [empresaId],
    );
    return (rows ?? []).map((r) => ({
      id: Number(r.id),
      email: String(r.email ?? ''),
      name: String(r.nombre ?? ''),
      role: String(r.rol ?? 'user').trim().toLowerCase() === 'admin' ? 'admin' : 'user',
      active: Number(r.activo ?? 0) === 1,
      createdAt: String(r.created_at ?? ''),
    }));
  }

  async getById(input: { empresaId: number; userId: number }): Promise<SettingsUserRow | null> {
    const [rows] = await this.pool.query<RowDataPacket[]>(
      `
      SELECT id, email, nombre, rol, activo, created_at
      FROM usuarios_master
      WHERE id = ? AND empresa_id = ?
      LIMIT 1
      `,
      [input.userId, input.empresaId],
    );
    const r = rows?.[0];
    if (!r) return null;
    return {
      id: Number(r.id),
      email: String(r.email ?? ''),
      name: String(r.nombre ?? ''),
      role: String(r.rol ?? 'user').trim().toLowerCase() === 'admin' ? 'admin' : 'user',
      active: Number(r.activo ?? 0) === 1,
      createdAt: String(r.created_at ?? ''),
    };
  }

  async emailExists(input: { email: string; excludeUserId?: number | null }): Promise<boolean> {
    const exclude = Number(input.excludeUserId ?? 0);
    if (exclude > 0) {
      const [rows] = await this.pool.query<RowDataPacket[]>(
        'SELECT id FROM usuarios_master WHERE email = ? AND id <> ? LIMIT 1',
        [input.email, exclude],
      );
      return Boolean(rows?.[0]);
    }
    const [rows] = await this.pool.query<RowDataPacket[]>(
      'SELECT id FROM usuarios_master WHERE email = ? LIMIT 1',
      [input.email],
    );
    return Boolean(rows?.[0]);
  }

  async countActiveAdmins(empresaId: number): Promise<number> {
    const [rows] = await this.pool.query<RowDataPacket[]>(
      `
      SELECT COUNT(*) as c
      FROM usuarios_master
      WHERE empresa_id = ? AND rol = 'admin' AND activo = 1
      `,
      [empresaId],
    );
    return Number(rows?.[0]?.c ?? 0);
  }

  async create(input: {
    empresaId: number;
    email: string;
    name: string;
    role: 'admin' | 'user';
    active: boolean;
    passwordHash: string;
  }): Promise<number> {
    const [r] = await this.pool.query<{ insertId: number } & RowDataPacket[]>(
      `
      INSERT INTO usuarios_master (empresa_id, email, password_hash, rol, nombre, activo, created_at, updated_at)
      VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
      `,
      [input.empresaId, input.email, input.passwordHash, input.role, input.name, input.active ? 1 : 0],
    );
    return Number((r as unknown as { insertId: number })?.insertId ?? 0);
  }

  async update(input: {
    empresaId: number;
    userId: number;
    email: string;
    name: string;
    role: 'admin' | 'user';
    active: boolean;
  }): Promise<boolean> {
    const [r] = await this.pool.execute(
      `
      UPDATE usuarios_master
      SET email = ?, nombre = ?, rol = ?, activo = ?, updated_at = NOW()
      WHERE id = ? AND empresa_id = ?
      `,
      [input.email, input.name, input.role, input.active ? 1 : 0, input.userId, input.empresaId],
    );
    const anyResult = r as { affectedRows?: number };
    return Number(anyResult.affectedRows ?? 0) > 0;
  }

  async updatePasswordHash(input: { empresaId: number; userId: number; passwordHash: string }): Promise<boolean> {
    const [r] = await this.pool.execute(
      `
      UPDATE usuarios_master
      SET password_hash = ?, updated_at = NOW()
      WHERE id = ? AND empresa_id = ?
      `,
      [input.passwordHash, input.userId, input.empresaId],
    );
    const anyResult = r as { affectedRows?: number };
    return Number(anyResult.affectedRows ?? 0) > 0;
  }

  async delete(input: { empresaId: number; userId: number }): Promise<boolean> {
    const [r] = await this.pool.execute('DELETE FROM usuarios_master WHERE id = ? AND empresa_id = ?', [
      input.userId,
      input.empresaId,
    ]);
    const anyResult = r as { affectedRows?: number };
    return Number(anyResult.affectedRows ?? 0) > 0;
  }
}

