import type { ApiResponse, MeDto } from '@corejs/shared/types';
import bcrypt from 'bcryptjs';
import crypto from 'node:crypto';
import { Injectable } from '@nestjs/common';
import { signAuthToken, verifyAuthToken } from '../../../infrastructure/auth/jwt';
import { MasterUsersDao } from '../daos/masterUsers.dao';
import { SuperAdminDao } from '../daos/superAdmin.dao';

type LoginInput = {
  email: string;
  password: string;
};

function parseCookies(cookieHeader: string | undefined): Record<string, string> {
  const out: Record<string, string> = {};
  if (!cookieHeader) return out;
  const parts = cookieHeader.split(';');
  for (const p of parts) {
    const trimmed = p.trim();
    if (!trimmed) continue;
    const idx = trimmed.indexOf('=');
    if (idx < 0) continue;
    const k = trimmed.slice(0, idx).trim();
    const v = trimmed.slice(idx + 1).trim();
    if (!k) continue;
    out[k] = decodeURIComponent(v);
  }
  return out;
}

function isMd5Hash(value: string): boolean {
  return value.length === 32 && /^[a-f0-9]+$/i.test(value);
}

function md5(value: string): string {
  return crypto.createHash('md5').update(value).digest('hex');
}

function normalizeTenantRole(raw: string): 'ADMIN' | 'USER' {
  const v = raw.trim().toLowerCase();
  const adminValues = new Set([
    'admin',
    'administrador',
    'owner',
    'super_admin',
    'superadmin',
  ]);
  return adminValues.has(v) ? 'ADMIN' : 'USER';
}

@Injectable()
export class AuthController {
  constructor(
    private readonly superAdminDao: SuperAdminDao,
    private readonly masterUsersDao: MasterUsersDao,
  ) {}

  async me(cookieHeader: string | undefined): Promise<ApiResponse<MeDto>> {
    const cookies = parseCookies(cookieHeader);
    const token = cookies['corejs_token'];
    if (!token) {
      return { ok: false, error: { code: 'UNAUTHENTICATED', message: 'No autenticado' } };
    }
    try {
      const payload = verifyAuthToken(token);
      let name = (payload.name ?? '').trim();
      if (!name) {
        try {
          if (payload.role === 'SUPER_ADMIN') {
            const sa = await this.superAdminDao.findByUsernameOrEmail(payload.email);
            name = String(sa?.username ?? '').trim();
          } else {
            const mu = await this.masterUsersDao.findUsuarioByEmail(payload.email);
            name = String(mu?.nombre ?? '').trim();
          }
        } catch {
        }
      }
      return {
        ok: true,
        data: {
          id: payload.sub,
          email: payload.email,
          name,
          role: payload.role,
          tenantId: payload.tenantId,
        },
      };
    } catch {
      return { ok: false, error: { code: 'UNAUTHENTICATED', message: 'No autenticado' } };
    }
  }

  async login(input: LoginInput): Promise<ApiResponse<MeDto> & { token?: string }> {
    const userOrEmail = input.email.trim();
    const password = input.password;

    try {
      await this.superAdminDao.ensureSchema();
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }

    let superAdmin = null as Awaited<ReturnType<SuperAdminDao['findByUsernameOrEmail']>>;
    try {
      superAdmin = await this.superAdminDao.findByUsernameOrEmail(userOrEmail);
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
    if (superAdmin) {
      const ok = await bcrypt.compare(password, superAdmin.password);
      if (ok) {
        const me: MeDto = {
          id: superAdmin.id,
          email: superAdmin.email,
          name: superAdmin.username,
          role: 'SUPER_ADMIN',
          tenantId: null,
        };
        const token = signAuthToken({
          sub: me.id,
          email: me.email,
          name: me.name,
          role: me.role,
          tenantId: me.tenantId,
        });
        return { ok: true, data: me, token };
      }
    }

    const saasMode = (process.env.SAAS_DB_MODE ?? '').trim().toLowerCase();
    const perDatabase = saasMode === 'per_database' || saasMode === 'per-db' || saasMode === 'perdb';
    if (!perDatabase) {
      return { ok: false, error: { code: 'UNSUPPORTED', message: 'Modo SaaS no soportado aún' } };
    }

    let masterUser = null as Awaited<ReturnType<MasterUsersDao['findUsuarioByEmail']>>;
    try {
      masterUser = await this.masterUsersDao.findUsuarioByEmail(userOrEmail);
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
    if (!masterUser || masterUser.activo !== 1) {
      return { ok: false, error: { code: 'INVALID_CREDENTIALS', message: 'Credenciales incorrectas' } };
    }

    let empresa = null as Awaited<ReturnType<MasterUsersDao['getEmpresa']>>;
    try {
      empresa = await this.masterUsersDao.getEmpresa(masterUser.empresa_id);
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
    if (!empresa || empresa.estado !== 'active') {
      return { ok: false, error: { code: 'TENANT_SUSPENDED', message: 'La cuenta de su empresa está suspendida.' } };
    }

    const hash = masterUser.password_hash ?? '';
    let valid = false;
    if (hash && !isMd5Hash(hash)) {
      valid = await bcrypt.compare(password, hash);
    } else if (hash && isMd5Hash(hash)) {
      valid = md5(password) === hash.toLowerCase();
      if (valid) {
        const newHash = await bcrypt.hash(password, 10);
        try {
          await this.masterUsersDao.upgradePasswordHash(masterUser.id, newHash);
        } catch {
        }
      }
    }
    if (!valid) {
      return { ok: false, error: { code: 'INVALID_CREDENTIALS', message: 'Credenciales incorrectas' } };
    }

    const me: MeDto = {
      id: masterUser.id,
      email: masterUser.email,
      name: masterUser.nombre,
      role: normalizeTenantRole(masterUser.rol),
      tenantId: masterUser.empresa_id,
    };
    const token = signAuthToken({
      sub: me.id,
      email: me.email,
      name: me.name,
      role: me.role,
      tenantId: me.tenantId,
    });
    return { ok: true, data: me, token };
  }
}
