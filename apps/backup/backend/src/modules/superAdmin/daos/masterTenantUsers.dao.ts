import { Inject, Injectable } from '@nestjs/common';
import type { RowDataPacket } from 'mysql2';
import type { MasterDbPool } from '../../../infrastructure/db/master.pool';

export type MasterTenantUserRow = {
  id: number;
  email: string;
  nombre: string;
  rol: string;
  activo: number;
};

@Injectable()
export class MasterTenantUsersDao {
  constructor(@Inject('MASTER_DB_POOL') private readonly pool: MasterDbPool) {}

  async anyUserByEmail(email: string): Promise<{ id: number; empresaId: number } | null> {
    const [rows] = await this.pool.query<RowDataPacket[]>(
      `
      SELECT id, empresa_id
      FROM usuarios_master
      WHERE email = ?
      LIMIT 1
      `,
      [email],
    );
    const r = rows?.[0];
    if (!r) return null;
    return { id: Number(r.id), empresaId: Number(r.empresa_id ?? 0) };
  }

  async create(input: {
    empresaId: number;
    email: string;
    nombre: string;
    rol: string;
    passwordHash: string;
    activo: number;
  }): Promise<number> {
    const [result] = await this.pool.execute(
      `
      INSERT INTO usuarios_master (empresa_id, email, password_hash, rol, nombre, activo, created_at, updated_at)
      VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
      `,
      [input.empresaId, input.email, input.passwordHash, input.rol, input.nombre, input.activo],
    );
    return Number((result as unknown as { insertId?: number })?.insertId ?? 0);
  }

  async deactivateByEmpresaId(empresaId: number): Promise<number> {
    const [result] = await this.pool.execute(
      `
      UPDATE usuarios_master
      SET activo = 0, updated_at = NOW()
      WHERE empresa_id = ?
      `,
      [empresaId],
    );
    const anyResult = result as { affectedRows?: number };
    return Number(anyResult.affectedRows ?? 0);
  }

  async getById(input: { empresaId: number; userId: number }): Promise<MasterTenantUserRow | null> {
    const [rows] = await this.pool.query<RowDataPacket[]>(
      `
      SELECT id, email, nombre, rol, activo
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
      nombre: String(r.nombre ?? ''),
      rol: String(r.rol ?? ''),
      activo: Number(r.activo ?? 0),
    };
  }

  async emailExists(input: { email: string; excludeUserId: number }): Promise<boolean> {
    const [rows] = await this.pool.query<RowDataPacket[]>(
      `
      SELECT id
      FROM usuarios_master
      WHERE email = ? AND id <> ?
      LIMIT 1
      `,
      [input.email, input.excludeUserId],
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

  async updatePasswordHash(input: { empresaId: number; userId: number; passwordHash: string }): Promise<boolean> {
    const [result] = await this.pool.execute(
      `
      UPDATE usuarios_master
      SET password_hash = ?, updated_at = NOW()
      WHERE id = ? AND empresa_id = ?
      `,
      [input.passwordHash, input.userId, input.empresaId],
    );
    const anyResult = result as { affectedRows?: number };
    return Number(anyResult.affectedRows ?? 0) > 0;
  }

  async updateEmail(input: { empresaId: number; userId: number; newEmail: string }): Promise<boolean> {
    const [result] = await this.pool.execute(
      `
      UPDATE usuarios_master
      SET email = ?, updated_at = NOW()
      WHERE id = ? AND empresa_id = ?
      `,
      [input.newEmail, input.userId, input.empresaId],
    );
    const anyResult = result as { affectedRows?: number };
    return Number(anyResult.affectedRows ?? 0) > 0;
  }

  async delete(input: { empresaId: number; userId: number }): Promise<boolean> {
    const [result] = await this.pool.execute(
      'DELETE FROM usuarios_master WHERE id = ? AND empresa_id = ?',
      [input.userId, input.empresaId],
    );
    const anyResult = result as { affectedRows?: number };
    return Number(anyResult.affectedRows ?? 0) > 0;
  }

  async listByEmpresaId(empresaId: number): Promise<MasterTenantUserRow[]> {
    const [rows] = await this.pool.query<RowDataPacket[]>(
      `
      SELECT id, email, nombre, rol, activo
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
      nombre: String(r.nombre ?? ''),
      rol: String(r.rol ?? ''),
      activo: Number(r.activo ?? 0),
    }));
  }
}
