import type { ApiResponse, MasterUserDto, TenantDetailDto, TenantDto } from '@corejs/shared/types';
import bcrypt from 'bcryptjs';
import { Inject, Injectable } from '@nestjs/common';
import type { RowDataPacket } from 'mysql2';
import type { MasterDbPool } from '../../../infrastructure/db/master.pool';
import { createTenantConnection } from '../../../infrastructure/db/tenant.connection';
import { MasterTenantUsersDao } from '../daos/masterTenantUsers.dao';
import { TenantsDao } from '../daos/tenants.dao';

function normalizeRoleToMaster(raw: string): 'admin' | 'user' {
  const v = raw.trim().toLowerCase();
  const adminValues = new Set(['admin', 'administrador', 'owner', 'super_admin', 'superadmin']);
  return adminValues.has(v) ? 'admin' : 'user';
}

function isValidEmail(v: string): boolean {
  if (!v) return false;
  const s = v.trim();
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(s);
}

@Injectable()
export class TenantsController {
  constructor(
    @Inject('MASTER_DB_POOL') private readonly pool: MasterDbPool,
    private readonly tenantsDao: TenantsDao,
    private readonly masterTenantUsersDao: MasterTenantUsersDao,
  ) {}

  async create(input: {
    companyName: string;
    dbHost: string;
    dbPort: number;
    dbName: string;
    dbUser: string;
    dbPass: string;
    adminName: string;
    adminEmail: string;
    adminPassword: string;
  }): Promise<ApiResponse<{ tenantId: number; adminUserId: number }>> {
    const email = input.adminEmail.trim().toLowerCase();
    if (!email) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Email requerido' } };
    }
    try {
      await this.tenantsDao.testRawDb({
        dbHost: input.dbHost,
        dbPort: input.dbPort,
        dbName: input.dbName,
        dbUser: input.dbUser,
        dbPass: input.dbPass,
      });
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'No se pudo conectar a la base de datos del tenant' } };
    }

    try {
      const existing = await this.masterTenantUsersDao.anyUserByEmail(email);
      if (existing) {
        return { ok: false, error: { code: 'EMAIL_IN_USE', message: 'El email ya está registrado en otra empresa.' } };
      }
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }

    let tenantId = 0;
    try {
      tenantId = await this.tenantsDao.createTenant({
        companyName: input.companyName,
        dbHost: input.dbHost,
        dbPort: input.dbPort,
        dbName: input.dbName,
        dbUser: input.dbUser,
        dbPass: input.dbPass,
      });
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
    if (!tenantId) return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };

    let adminUserId = 0;
    try {
      const hash = await bcrypt.hash(input.adminPassword, 10);
      adminUserId = await this.masterTenantUsersDao.create({
        empresaId: tenantId,
        email,
        nombre: input.adminName.trim(),
        rol: 'admin',
        passwordHash: hash,
        activo: 1,
      });
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'No se pudo crear el usuario administrador' } };
    }

    if (!adminUserId) return { ok: false, error: { code: 'DB_ERROR', message: 'No se pudo crear el usuario administrador' } };
    return { ok: true, data: { tenantId, adminUserId } };
  }

  async syncUsers(input: { tenantId: number | null }): Promise<
    ApiResponse<{
      tenants: Array<{
        tenantId: number;
        companyName: string;
        status: string;
        created: number;
        exists: number;
        conflicts: number;
        fails: number;
        skipped: number;
      }>;
      totals: { created: number; exists: number; conflicts: number; fails: number; skipped: number };
    }>
  > {
    const targetId = input.tenantId && Number.isFinite(input.tenantId) ? Number(input.tenantId) : null;
    let empresas: RowDataPacket[] = [];
    try {
      if (targetId) {
        const [rows] = await this.pool.query<RowDataPacket[]>(
          `SELECT id, nombre, estado FROM empresas WHERE id = ? LIMIT 1`,
          [targetId],
        );
        empresas = rows ?? [];
      } else {
        const [rows] = await this.pool.query<RowDataPacket[]>(
          `SELECT id, nombre, estado FROM empresas ORDER BY id ASC LIMIT 500`,
        );
        empresas = rows ?? [];
      }
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }

    const totals = { created: 0, exists: 0, conflicts: 0, fails: 0, skipped: 0 };
    const out: Array<{
      tenantId: number;
      companyName: string;
      status: string;
      created: number;
      exists: number;
      conflicts: number;
      fails: number;
      skipped: number;
    }> = [];

    for (const e of empresas) {
      const tenantId = Number(e.id ?? 0);
      const companyName = String(e.nombre ?? '');
      const status = String(e.estado ?? '');
      if (!tenantId) continue;
      if (status !== 'active') {
        out.push({ tenantId, companyName, status, created: 0, exists: 0, conflicts: 0, fails: 0, skipped: 0 });
        totals.skipped++;
        continue;
      }

      let created = 0;
      let exists = 0;
      let conflicts = 0;
      let fails = 0;
      let skipped = 0;

      let tenantConn: Awaited<ReturnType<typeof createTenantConnection>> | null = null;
      try {
        tenantConn = await createTenantConnection(this.pool, tenantId);
        const [hasUsers] = await tenantConn.query<RowDataPacket[]>("SHOW TABLES LIKE 'users'");
        if (!hasUsers?.length) {
          skipped++;
          out.push({ tenantId, companyName, status, created, exists, conflicts, fails, skipped });
          totals.skipped += skipped;
          continue;
        }

        const [cols] = await tenantConn.query<RowDataPacket[]>("SHOW COLUMNS FROM users");
        const colSet = new Set((cols ?? []).map((c) => String(c.Field ?? '').toLowerCase()).filter(Boolean));

        const colName = colSet.has('name') ? 'name' : colSet.has('nombre') ? 'nombre' : null;
        const colEmail = colSet.has('email') ? 'email' : null;
        const colRole = colSet.has('role') ? 'role' : colSet.has('rol') ? 'rol' : null;
        const colActive = colSet.has('active')
          ? 'active'
          : colSet.has('is_active')
            ? 'is_active'
            : colSet.has('activo')
              ? 'activo'
              : null;
        const colPass = colSet.has('password_hash')
          ? 'password_hash'
          : colSet.has('password')
            ? 'password'
            : colSet.has('passwordhash')
              ? 'passwordHash'
              : null;

        if (!colEmail || !colPass) {
          skipped++;
          out.push({ tenantId, companyName, status, created, exists, conflicts, fails, skipped });
          totals.skipped += skipped;
          continue;
        }

        const nameExpr = colName ? `u.\`${colName}\`` : "''";
        const roleExpr = colRole ? `u.\`${colRole}\`` : "'user'";
        const activeExpr = colActive ? `u.\`${colActive}\`` : '1';
        const passExpr = `u.\`${colPass}\``;

        const [rows] = await tenantConn.query<RowDataPacket[]>(
          `
          SELECT
            ${nameExpr} as name,
            u.\`${colEmail}\` as email,
            ${passExpr} as password_hash,
            ${roleExpr} as role,
            ${activeExpr} as active
          FROM users u
          ORDER BY u.id ASC
          LIMIT 5000
          `,
        );

        for (const r of rows ?? []) {
          const email = String(r.email ?? '').trim().toLowerCase();
          if (!isValidEmail(email)) continue;
          const name = String(r.name ?? '');
          const passHash = String(r.password_hash ?? '');
          if (!passHash) continue;
          const role = normalizeRoleToMaster(String(r.role ?? 'user'));
          const active = Number(r.active ?? 1) ? 1 : 0;

          try {
            const existing = await this.masterTenantUsersDao.anyUserByEmail(email);
            if (existing) {
              if (existing.empresaId !== tenantId) {
                conflicts++;
              } else {
                exists++;
              }
              continue;
            }
            const id = await this.masterTenantUsersDao.create({
              empresaId: tenantId,
              email,
              nombre: name,
              rol: role,
              passwordHash: passHash,
              activo: active,
            });
            if (id) created++;
          } catch {
            fails++;
          }
        }
      } catch {
        fails++;
      } finally {
        if (tenantConn) {
          try {
            await tenantConn.end();
          } catch {
          }
        }
      }

      totals.created += created;
      totals.exists += exists;
      totals.conflicts += conflicts;
      totals.fails += fails;
      totals.skipped += skipped;
      out.push({ tenantId, companyName, status, created, exists, conflicts, fails, skipped });
    }

    return { ok: true, data: { tenants: out, totals } };
  }

  async list(): Promise<ApiResponse<TenantDto[]>> {
    let rows: Awaited<ReturnType<TenantsDao['listWithMeta']>> = [];
    try {
      rows = await this.tenantsDao.listWithMeta();
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
    return {
      ok: true,
      data: rows.map((r) => ({
        id: r.id,
        companyName: r.companyName,
        status: r.status,
        createdAt: r.createdAt,
      })),
    };
  }

  async get(id: number): Promise<ApiResponse<TenantDetailDto>> {
    try {
      const row = await this.tenantsDao.getById(id);
      if (!row) {
        return { ok: false, error: { code: 'NOT_FOUND', message: 'Empresa no encontrada' } };
      }
      return {
        ok: true,
        data: {
          id: row.id,
          companyName: row.companyName,
          status: row.status,
          createdAt: row.createdAt,
          dbHost: row.dbHost,
          dbPort: row.dbPort,
          dbName: row.dbName,
          dbUser: row.dbUser,
          licenseCount: row.licenseCount,
          lastLicense: row.lastLicense ?? null,
        },
      };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async setStatus(id: number, status: 'active' | 'suspended'): Promise<ApiResponse<{ done: true }>> {
    try {
      await this.tenantsDao.updateStatus(id, status);
      return { ok: true, data: { done: true } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async listUsers(id: number): Promise<ApiResponse<MasterUserDto[]>> {
    try {
      const rows = await this.masterTenantUsersDao.listByEmpresaId(id);
      return {
        ok: true,
        data: rows.map((r) => ({
          id: r.id,
          email: r.email,
          name: r.nombre,
          role: r.rol,
          active: r.activo === 1,
        })),
      };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async resetTenantUserPassword(input: {
    empresaId: number;
    userId: number;
    newPassword: string;
  }): Promise<ApiResponse<{ done: true }>> {
    try {
      const user = await this.masterTenantUsersDao.getById({
        empresaId: input.empresaId,
        userId: input.userId,
      });
      if (!user) {
        return { ok: false, error: { code: 'NOT_FOUND', message: 'Usuario no encontrado' } };
      }
      const hash = await bcrypt.hash(input.newPassword, 10);
      const ok = await this.masterTenantUsersDao.updatePasswordHash({
        empresaId: input.empresaId,
        userId: input.userId,
        passwordHash: hash,
      });
      if (!ok) {
        return { ok: false, error: { code: 'NOT_FOUND', message: 'Usuario no encontrado' } };
      }
      return { ok: true, data: { done: true } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async updateTenantUserEmail(input: {
    empresaId: number;
    userId: number;
    newEmail: string;
  }): Promise<ApiResponse<{ done: true }>> {
    try {
      const user = await this.masterTenantUsersDao.getById({
        empresaId: input.empresaId,
        userId: input.userId,
      });
      if (!user) {
        return { ok: false, error: { code: 'NOT_FOUND', message: 'Usuario no encontrado' } };
      }
      const exists = await this.masterTenantUsersDao.emailExists({
        email: input.newEmail,
        excludeUserId: input.userId,
      });
      if (exists) {
        return { ok: false, error: { code: 'EMAIL_IN_USE', message: 'El email ya está registrado.' } };
      }
      const ok = await this.masterTenantUsersDao.updateEmail({
        empresaId: input.empresaId,
        userId: input.userId,
        newEmail: input.newEmail,
      });
      if (!ok) {
        return { ok: false, error: { code: 'NOT_FOUND', message: 'Usuario no encontrado' } };
      }
      return { ok: true, data: { done: true } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async deleteTenantUser(input: { empresaId: number; userId: number }): Promise<ApiResponse<{ deleted: true }>> {
    try {
      const user = await this.masterTenantUsersDao.getById({
        empresaId: input.empresaId,
        userId: input.userId,
      });
      if (!user) {
        return { ok: false, error: { code: 'NOT_FOUND', message: 'Usuario no encontrado' } };
      }
      const role = String(user.rol ?? '').trim().toLowerCase();
      const active = Number(user.activo ?? 0) === 1;
      if (role === 'admin' && active) {
        const admins = await this.masterTenantUsersDao.countActiveAdmins(input.empresaId);
        if (admins <= 1) {
          return {
            ok: false,
            error: { code: 'LAST_ADMIN', message: 'No se puede eliminar el último administrador activo.' },
          };
        }
      }
      const ok = await this.masterTenantUsersDao.delete({
        empresaId: input.empresaId,
        userId: input.userId,
      });
      if (!ok) {
        return { ok: false, error: { code: 'NOT_FOUND', message: 'Usuario no encontrado' } };
      }
      return { ok: true, data: { deleted: true } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async deleteTenant(id: number): Promise<ApiResponse<{ deleted: true }>> {
    const tenantId = Number(id);
    if (!Number.isFinite(tenantId) || tenantId <= 0) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'ID inválido' } };
    }
    try {
      const existing = await this.tenantsDao.getById(tenantId);
      if (!existing) {
        return { ok: false, error: { code: 'NOT_FOUND', message: 'Empresa no encontrada' } };
      }
      const ok = await this.tenantsDao.markDeleted(tenantId);
      if (!ok) {
        return { ok: false, error: { code: 'NOT_FOUND', message: 'Empresa no encontrada' } };
      }
      await this.masterTenantUsersDao.deactivateByEmpresaId(tenantId);
      return { ok: true, data: { deleted: true } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async update(
    id: number,
    input: {
      companyName: string;
      status: 'active' | 'suspended' | 'provisioning';
      dbHost: string;
      dbPort: number;
      dbName: string;
      dbUser: string;
      dbPass: string | null;
    },
  ): Promise<ApiResponse<{ done: true }>> {
    try {
      await this.tenantsDao.updateTenant({
        id,
        companyName: input.companyName,
        status: input.status,
        dbHost: input.dbHost,
        dbPort: input.dbPort,
        dbName: input.dbName,
        dbUser: input.dbUser,
        dbPass: input.dbPass,
      });
      return { ok: true, data: { done: true } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async testDb(
    id: number,
    input: {
      dbHost: string | null;
      dbPort: number | null;
      dbName: string | null;
      dbUser: string | null;
      dbPass: string | null;
    },
  ): Promise<ApiResponse<{ ok: true }>> {
    try {
      const current = await this.tenantsDao.getById(id);
      if (!current) {
        return { ok: false, error: { code: 'NOT_FOUND', message: 'Empresa no encontrada' } };
      }
      await this.tenantsDao.testTenantDb({
        id,
        dbHost: input.dbHost ?? current.dbHost ?? 'localhost',
        dbPort: input.dbPort ?? current.dbPort ?? 3306,
        dbName: input.dbName ?? current.dbName ?? '',
        dbUser: input.dbUser ?? current.dbUser ?? '',
        dbPass: input.dbPass,
      });
      return { ok: true, data: { ok: true } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }
}
