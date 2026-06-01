import { Inject, Injectable } from '@nestjs/common';
import type { RowDataPacket } from 'mysql2';
import type { MasterDbPool } from '../../../infrastructure/db/master.pool';
import crypto from 'node:crypto';

function generateSaasLicenseCode(): string {
  const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  let out = '';
  const max = alphabet.length - 1;
  for (let i = 0; i < 12; i++) {
    const idx = crypto.randomInt(0, max + 1);
    out += alphabet[idx];
  }
  return `${out.slice(0, 4)}-${out.slice(4, 8)}-${out.slice(8, 12)}`;
}

@Injectable()
export class LicensesDao {
  constructor(@Inject('MASTER_DB_POOL') private readonly pool: MasterDbPool) {}

  async listAvailableCodes(): Promise<string[]> {
    const [rows] = await this.pool.query<RowDataPacket[]>(
      `
      SELECT codigo
      FROM licencias
      WHERE estado = 'disponible' AND empresa_id IS NULL
      ORDER BY id DESC
      LIMIT 200
      `,
    );
    return (rows ?? []).map((r) => String(r.codigo ?? '')).filter(Boolean);
  }

  async generateAndAssign(empresaId: number): Promise<string> {
    const code = generateSaasLicenseCode();
    await this.pool.execute(
      `
      INSERT INTO licencias (codigo, plan, estado, empresa_id, used_at, created_at, updated_at)
      VALUES (?, 'standard', 'usada', ?, NOW(), NOW(), NOW())
      `,
      [code, empresaId],
    );
    return code;
  }

  async assignExisting(empresaId: number, code: string): Promise<boolean> {
    const c = code.trim().toUpperCase();
    const [result] = await this.pool.execute(
      `
      UPDATE licencias
      SET estado = 'usada', empresa_id = ?, used_at = NOW(), updated_at = NOW()
      WHERE codigo = ? AND estado = 'disponible' AND empresa_id IS NULL
      `,
      [empresaId, c],
    );
    const anyResult = result as { affectedRows?: number };
    return Number(anyResult.affectedRows ?? 0) > 0;
  }
}
