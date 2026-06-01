import jwt from 'jsonwebtoken';

export type AuthTokenPayload = {
  sub: number;
  email: string;
  name?: string;
  role: 'SUPER_ADMIN' | 'ADMIN' | 'USER';
  tenantId: number | null;
};

export function signAuthToken(payload: AuthTokenPayload): string {
  const secret = process.env.JWT_SECRET ?? '';
  if (!secret) {
    throw new Error('JWT_SECRET is required');
  }
  return jwt.sign(payload, secret, { algorithm: 'HS256', expiresIn: '7d' });
}

export function verifyAuthToken(token: string): AuthTokenPayload {
  const secret = process.env.JWT_SECRET ?? '';
  if (!secret) {
    throw new Error('JWT_SECRET is required');
  }
  const decoded = jwt.verify(token, secret, { algorithms: ['HS256'] });
  if (typeof decoded === 'string' || decoded === null) {
    throw new Error('Invalid token');
  }
  const anyDecoded = decoded as Record<string, unknown>;
  if (
    typeof anyDecoded.sub !== 'number' ||
    typeof anyDecoded.email !== 'string' ||
    (anyDecoded.role !== 'SUPER_ADMIN' &&
      anyDecoded.role !== 'ADMIN' &&
      anyDecoded.role !== 'USER')
  ) {
    throw new Error('Invalid token');
  }
  if (typeof anyDecoded.name !== 'undefined' && typeof anyDecoded.name !== 'string') {
    throw new Error('Invalid token');
  }
  const tenantIdRaw = anyDecoded.tenantId;
  const tenantId =
    tenantIdRaw === null
      ? null
      : typeof tenantIdRaw === 'number'
        ? tenantIdRaw
        : null;
  return {
    sub: anyDecoded.sub,
    email: anyDecoded.email,
    name: typeof anyDecoded.name === 'string' ? anyDecoded.name : undefined,
    role: anyDecoded.role as 'SUPER_ADMIN' | 'ADMIN' | 'USER',
    tenantId,
  };
}
