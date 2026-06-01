import { Inject, Injectable } from '@nestjs/common';
import type { RowDataPacket } from 'mysql2';
import type { MasterDbPool } from '../../../infrastructure/db/master.pool';

export type SuperAdminRow = {
  id: number;
  username: string;
  email: string;
  password: string;
  mfa_preference: 'totp' | 'email' | 'pin' | null;
};

@Injectable()
export class SuperAdminDao {
  constructor(@Inject('MASTER_DB_POOL') private readonly pool: MasterDbPool) {}

  async ensureSchema(): Promise<void> {
    await this.pool.execute(`
      CREATE TABLE IF NOT EXISTS saas_super_admins (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(64) UNIQUE NOT NULL,
        email VARCHAR(255) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        twofa_secret VARCHAR(255) NULL,
        backup_pin VARCHAR(255) NULL,
        trusted_ips TEXT NULL,
        mfa_preference ENUM('totp', 'email', 'pin') DEFAULT 'totp',
        recovery_token VARCHAR(255) NULL,
        recovery_expires DATETIME NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    `);
  }

  async findByUsernameOrEmail(
    user: string,
  ): Promise<SuperAdminRow | null> {
    const [rows] = await this.pool.query<RowDataPacket[]>(
      `
      SELECT id, username, email, password, mfa_preference
      FROM saas_super_admins
      WHERE username = ? OR email = ?
      LIMIT 1
      `,
      [user, user],
    );
    const r = rows?.[0];
    if (!r) return null;
    return {
      id: Number(r.id),
      username: String(r.username),
      email: String(r.email),
      password: String(r.password),
      mfa_preference: (r.mfa_preference ?? null) as
        | 'totp'
        | 'email'
        | 'pin'
        | null,
    };
  }
}
