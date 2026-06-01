import { Inject, Injectable } from '@nestjs/common';
import type { RowDataPacket } from 'mysql2';
import type { MasterDbPool } from '../../../infrastructure/db/master.pool';

export type MasterUserRow = {
  id: number;
  empresa_id: number;
  email: string;
  password_hash: string;
  rol: string;
  nombre: string;
  activo: number;
};

export type EmpresaRow = {
  id: number;
  nombre: string;
  estado: string;
};

@Injectable()
export class MasterUsersDao {
  constructor(@Inject('MASTER_DB_POOL') private readonly pool: MasterDbPool) {}

  async findUsuarioByEmail(email: string): Promise<MasterUserRow | null> {
    const [rows] = await this.pool.query<RowDataPacket[]>(
      `
      SELECT id, empresa_id, email, password_hash, rol, nombre, activo
      FROM usuarios_master
      WHERE email = ?
      LIMIT 1
      `,
      [email],
    );
    const r = rows?.[0];
    if (!r) return null;
    return {
      id: Number(r.id),
      empresa_id: Number(r.empresa_id),
      email: String(r.email),
      password_hash: String(r.password_hash ?? ''),
      rol: String(r.rol ?? ''),
      nombre: String(r.nombre ?? ''),
      activo: Number(r.activo ?? 0),
    };
  }

  async getEmpresa(id: number): Promise<EmpresaRow | null> {
    const [rows] = await this.pool.query<RowDataPacket[]>(
      `
      SELECT id, nombre, estado
      FROM empresas
      WHERE id = ?
      LIMIT 1
      `,
      [id],
    );
    const r = rows?.[0];
    if (!r) return null;
    return { id: Number(r.id), nombre: String(r.nombre), estado: String(r.estado) };
  }

  async upgradePasswordHash(userId: number, newHash: string): Promise<void> {
    await this.pool.execute(
      'UPDATE usuarios_master SET password_hash = ?, updated_at = NOW() WHERE id = ?',
      [newHash, userId],
    );
  }
}

