import crypto from 'node:crypto';

function getMasterKey(): Buffer {
  const raw = (process.env.MASTER_DB_KEY ?? '').trim();
  if (!raw) {
    throw new Error('MASTER_DB_KEY is required');
  }
  const decoded = Buffer.from(raw, 'base64');
  if (decoded.length === 32) {
    return decoded;
  }
  return crypto.createHash('sha256').update(raw).digest();
}

export type EncryptedPayload = {
  enc: string;
  iv: string;
  tag: string;
};

export function encryptMaster(plaintext: string): EncryptedPayload {
  const key = getMasterKey();
  const iv = crypto.randomBytes(12);
  const cipher = crypto.createCipheriv('aes-256-gcm', key, iv);
  const ciphertext = Buffer.concat([cipher.update(plaintext, 'utf8'), cipher.final()]);
  const tag = cipher.getAuthTag();
  return {
    enc: ciphertext.toString('base64'),
    iv: iv.toString('base64'),
    tag: tag.toString('base64'),
  };
}

export function decryptMaster(encB64: string, ivB64: string, tagB64: string): string {
  const key = getMasterKey();
  const ciphertext = Buffer.from(encB64, 'base64');
  const iv = Buffer.from(ivB64, 'base64');
  const tag = Buffer.from(tagB64, 'base64');
  const decipher = crypto.createDecipheriv('aes-256-gcm', key, iv);
  decipher.setAuthTag(tag);
  const plaintext = Buffer.concat([decipher.update(ciphertext), decipher.final()]);
  return plaintext.toString('utf8');
}

