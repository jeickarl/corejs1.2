import type { ApiResponse } from '@corejs/shared/types';
import { Injectable } from '@nestjs/common';
import { ClientsDao, type ClientRow } from '../daos/clients.dao';

function dbErrorMessage(e: unknown): string {
  if (e instanceof Error) {
    const m = e.message.trim();
    if (m.startsWith('TENANT_DB_CONNECT_FAILED')) return m;
  }
  return 'Error de base de datos';
}

@Injectable()
export class ClientsController {
  constructor(private readonly clientsDao: ClientsDao) {}

  async getById(input: { empresaId: number; id: number }): Promise<ApiResponse<ClientRow>> {
    try {
      const client = await this.clientsDao.getById({ empresaId: input.empresaId, id: input.id });
      if (!client) {
        return { ok: false, error: { code: 'NOT_FOUND', message: 'Cliente no encontrado' } };
      }
      return { ok: true, data: client };
    } catch (e) {
      return { ok: false, error: { code: 'DB_ERROR', message: dbErrorMessage(e) } };
    }
  }

  async create(input: {
    empresaId: number;
    clientType: 'individual' | 'company';
    name?: string;
    companyName?: string;
    taxId?: string;
    legalRepresentative?: string;
    phone: string;
    email?: string;
    idNumber?: string;
    address?: string;
    notes?: string;
  }): Promise<ApiResponse<ClientRow>> {
    const clientType = input.clientType;
    const phone = input.phone.trim();
    if (!phone) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'El número de teléfono es obligatorio.' } };
    }

    let name: string | null = null;
    let companyName: string | null = null;
    let taxId: string | null = null;
    let legalRepresentative: string | null = null;

    if (clientType === 'individual') {
      const nm = (input.name ?? '').trim();
      if (!nm) {
        return {
          ok: false,
          error: { code: 'VALIDATION_ERROR', message: 'El nombre es obligatorio para personas naturales.' },
        };
      }
      name = nm;
    } else {
      const cn = (input.companyName ?? '').trim();
      const tx = (input.taxId ?? '').trim();
      if (!tx) {
        return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'El NIT/RUC es obligatorio para empresas.' } };
      }
      if (!cn) {
        return {
          ok: false,
          error: { code: 'VALIDATION_ERROR', message: 'La razón social es obligatoria para empresas.' },
        };
      }
      companyName = cn;
      taxId = tx;
      legalRepresentative = (input.legalRepresentative ?? '').trim() || null;
    }

    const email = (input.email ?? '').trim() || null;
    const address = (input.address ?? '').trim() || null;
    const notes = (input.notes ?? '').trim() || null;

    let idNumber: string | null =
      clientType === 'company' ? (input.idNumber ?? input.taxId ?? '').trim() : (input.idNumber ?? '').trim();
    if (!idNumber) idNumber = null;

    try {
      const isDuplicate = await this.clientsDao.existsDuplicate({
        empresaId: input.empresaId,
        clientType,
        taxId: clientType === 'company' ? taxId : null,
        idNumber: clientType === 'individual' ? idNumber : null,
      });
      if (isDuplicate) {
        return {
          ok: false,
          error: {
            code: 'DUPLICATE_CLIENT',
            message:
              clientType === 'company'
                ? 'Ya existe una empresa con este NIT/RUC.'
                : 'Ya existe un cliente con este número de identificación.',
          },
        };
      }

      const id = await this.clientsDao.create({
        empresaId: input.empresaId,
        clientType,
        name,
        companyName,
        taxId,
        legalRepresentative,
        phone,
        email,
        idNumber,
        address,
        notes,
      });
      const created = await this.clientsDao.getById({ empresaId: input.empresaId, id });
      if (!created) {
        return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
      }
      return { ok: true, data: created };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async update(input: {
    empresaId: number;
    id: number;
    clientType: 'individual' | 'company';
    name?: string;
    companyName?: string;
    taxId?: string;
    legalRepresentative?: string;
    phone: string;
    email?: string;
    idNumber?: string;
    address?: string;
    notes?: string;
  }): Promise<ApiResponse<ClientRow>> {
    const id = Number(input.id);
    if (!Number.isFinite(id) || id <= 0) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'ID inválido' } };
    }

    const clientType = input.clientType;
    const phone = input.phone.trim();
    if (!phone) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'El número de teléfono es obligatorio.' } };
    }

    let name: string | null = null;
    let companyName: string | null = null;
    let taxId: string | null = null;
    let legalRepresentative: string | null = null;

    if (clientType === 'individual') {
      const nm = (input.name ?? '').trim();
      if (!nm) {
        return {
          ok: false,
          error: { code: 'VALIDATION_ERROR', message: 'El nombre es obligatorio para personas naturales.' },
        };
      }
      name = nm;
    } else {
      const cn = (input.companyName ?? '').trim();
      const tx = (input.taxId ?? '').trim();
      if (!tx) {
        return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'El NIT/RUC es obligatorio para empresas.' } };
      }
      if (!cn) {
        return {
          ok: false,
          error: { code: 'VALIDATION_ERROR', message: 'La razón social es obligatoria para empresas.' },
        };
      }
      companyName = cn;
      taxId = tx;
      legalRepresentative = (input.legalRepresentative ?? '').trim() || null;
    }

    const email = (input.email ?? '').trim() || null;
    const address = (input.address ?? '').trim() || null;
    const notes = (input.notes ?? '').trim() || null;

    let idNumber: string | null =
      clientType === 'company' ? (input.idNumber ?? input.taxId ?? '').trim() : (input.idNumber ?? '').trim();
    if (!idNumber) idNumber = null;

    try {
      const existing = await this.clientsDao.getById({ empresaId: input.empresaId, id });
      if (!existing) {
        return { ok: false, error: { code: 'NOT_FOUND', message: 'Cliente no encontrado' } };
      }

      const isDuplicate = await this.clientsDao.existsDuplicate({
        empresaId: input.empresaId,
        idToExclude: id,
        clientType,
        taxId: clientType === 'company' ? taxId : null,
        idNumber: clientType === 'individual' ? idNumber : null,
      });
      if (isDuplicate) {
        return {
          ok: false,
          error: {
            code: 'DUPLICATE_CLIENT',
            message:
              clientType === 'company'
                ? 'Ya existe una empresa con este NIT/RUC.'
                : 'Ya existe un cliente con este número de identificación.',
          },
        };
      }

      const ok = await this.clientsDao.update({
        empresaId: input.empresaId,
        id,
        clientType,
        name,
        companyName,
        taxId,
        legalRepresentative,
        phone,
        email,
        idNumber,
        address,
        notes,
      });
      if (!ok) {
        return { ok: false, error: { code: 'NOT_FOUND', message: 'Cliente no encontrado' } };
      }
      const updated = await this.clientsDao.getById({ empresaId: input.empresaId, id });
      if (!updated) {
        return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
      }
      return { ok: true, data: updated };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async delete(input: { empresaId: number; id: number }): Promise<ApiResponse<{ deleted: true }>> {
    try {
      const ok = await this.clientsDao.delete({ empresaId: input.empresaId, id: input.id });
      if (!ok) {
        return { ok: false, error: { code: 'NOT_FOUND', message: 'Cliente no encontrado' } };
      }
      return { ok: true, data: { deleted: true } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async list(input: {
    empresaId: number;
    search: string;
    page: number;
    perPage: number;
  }): Promise<ApiResponse<{ items: unknown[]; page: number; perPage: number; total: number }>> {
    const page = Number.isFinite(input.page) && input.page > 0 ? input.page : 1;
    const perPage = Number.isFinite(input.perPage) && input.perPage > 0 ? input.perPage : 10;
    const offset = (page - 1) * perPage;
    try {
      const { rows, total } = await this.clientsDao.list({
        empresaId: input.empresaId,
        search: input.search,
        limit: perPage,
        offset,
      });
      return {
        ok: true,
        data: {
          items: rows,
          page,
          perPage,
          total,
        },
      };
    } catch (e) {
      return { ok: false, error: { code: 'DB_ERROR', message: dbErrorMessage(e) } };
    }
  }

  async stats(empresaId: number): Promise<ApiResponse<unknown>> {
    try {
      const stats = await this.clientsDao.stats(empresaId);
      return { ok: true, data: stats };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }
}
