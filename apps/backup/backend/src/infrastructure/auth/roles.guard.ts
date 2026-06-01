import {
  CanActivate,
  ExecutionContext,
  Injectable,
  UnauthorizedException,
} from '@nestjs/common';
import { Reflector } from '@nestjs/core';
import type { Request } from 'express';
import { ROLES_KEY } from './roles.decorator';
import { verifyAuthToken } from './jwt';

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

@Injectable()
export class RolesGuard implements CanActivate {
  constructor(private readonly reflector: Reflector) {}

  canActivate(context: ExecutionContext): boolean {
    const roles = this.reflector.getAllAndOverride<
      Array<'SUPER_ADMIN' | 'ADMIN' | 'USER'>
    >(ROLES_KEY, [context.getHandler(), context.getClass()]);
    if (!roles || roles.length === 0) return true;

    const req = context.switchToHttp().getRequest<Request & { user?: unknown }>();
    const cookieHeader = (req.headers as Record<string, string | undefined>)['cookie'];
    const cookies = parseCookies(cookieHeader);
    const token = cookies['corejs_token'];
    if (!token) {
      throw new UnauthorizedException('No autenticado');
    }
    const payload = verifyAuthToken(token);
    req.user = payload;
    if (!roles.includes(payload.role)) {
      throw new UnauthorizedException('No autorizado');
    }
    return true;
  }
}
