import type { ApiResponse } from '@corejs/shared/types';
import { Injectable } from '@nestjs/common';
import { SuppliersDao, type SupplierRow } from '../daos/suppliers.dao';

@Injectable()
export class SuppliersController {
  constructor(private readonly suppliersDao: SuppliersDao) {}

  async list(input: {
    empresaId: number;
    search: string;
    onlyActive?: boolean;
    page: number;
    perPage: number;
  }): Promise<ApiResponse<{ items: SupplierRow[]; page: number; perPage: number; total: number }>> {
    const page = Number.isFinite(input.page) && input.page > 0 ? Math.floor(input.page) : 1;
    const perPage = Number.isFinite(input.perPage) && input.perPage > 0 ? Math.floor(input.perPage) : 10;
    const limit = Math.min(100, perPage);
    const offset = (page - 1) * limit;
    const onlyActive = input.onlyActive === undefined ? null : Boolean(input.onlyActive);

    try {
      const { rows, total } = await this.suppliersDao.list({
        empresaId: input.empresaId,
        search: input.search,
        onlyActive,
        limit,
        offset,
      });
      return { ok: true, data: { items: rows, page, perPage: limit, total } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async getById(input: { empresaId: number; id: number }): Promise<ApiResponse<SupplierRow>> {
    const id = Number(input.id);
    if (!Number.isFinite(id) || id <= 0) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'ID inválido' } };
    }
    try {
      const supplier = await this.suppliersDao.getById({ empresaId: input.empresaId, id });
      if (!supplier) {
        return { ok: false, error: { code: 'NOT_FOUND', message: 'Proveedor no encontrado' } };
      }
      return { ok: true, data: supplier };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async create(input: {
    empresaId: number;
    supplierCode?: string;
    supplierType?: string;
    companyName: string;
    contactName?: string;
    taxId?: string;
    phone?: string;
    mobile?: string;
    email?: string;
    website?: string;
    address?: string;
    city?: string;
    state?: string;
    country?: string;
    postalCode?: string;
    paymentTerms?: string;
    creditLimit?: number;
    discountPercentage?: number;
    bankName?: string;
    accountNumber?: string;
    accountType?: string;
    rating?: number;
    notes?: string;
  }): Promise<ApiResponse<SupplierRow>> {
    const companyName = input.companyName.trim();
    if (!companyName) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'La razón social es obligatoria.' } };
    }

    const supplierCode = (input.supplierCode ?? '').trim() || null;
    const supplierType = (input.supplierType ?? '').trim() || null;
    const contactName = (input.contactName ?? '').trim() || null;
    const taxId = (input.taxId ?? '').trim() || null;
    const phone = (input.phone ?? '').trim() || null;
    const mobile = (input.mobile ?? '').trim() || null;
    const email = (input.email ?? '').trim() || null;
    const website = (input.website ?? '').trim() || null;
    const address = (input.address ?? '').trim() || null;
    const city = (input.city ?? '').trim() || null;
    const state = (input.state ?? '').trim() || null;
    const country = (input.country ?? '').trim() || null;
    const postalCode = (input.postalCode ?? '').trim() || null;
    const paymentTerms = (input.paymentTerms ?? '').trim() || null;
    const bankName = (input.bankName ?? '').trim() || null;
    const accountNumber = (input.accountNumber ?? '').trim() || null;
    const accountType = (input.accountType ?? '').trim() || null;
    const notes = (input.notes ?? '').trim() || null;

    const creditLimit =
      input.creditLimit === undefined || input.creditLimit === null || !Number.isFinite(Number(input.creditLimit))
        ? null
        : Number(input.creditLimit);
    const discountPercentage =
      input.discountPercentage === undefined ||
      input.discountPercentage === null ||
      !Number.isFinite(Number(input.discountPercentage))
        ? null
        : Number(input.discountPercentage);
    const rating =
      input.rating === undefined || input.rating === null || !Number.isFinite(Number(input.rating)) ? null : Number(input.rating);

    try {
      const isDup = await this.suppliersDao.existsDuplicate({
        empresaId: input.empresaId,
        supplierCode,
        taxId,
      });
      if (isDup) {
        return { ok: false, error: { code: 'DUPLICATE_SUPPLIER', message: 'Ya existe un proveedor con ese código o NIT.' } };
      }

      const id = await this.suppliersDao.create({
        empresaId: input.empresaId,
        supplierCode,
        supplierType,
        companyName,
        contactName,
        taxId,
        phone,
        mobile,
        email,
        website,
        address,
        city,
        state,
        country,
        postalCode,
        paymentTerms,
        creditLimit,
        discountPercentage,
        bankName,
        accountNumber,
        accountType,
        rating,
        notes,
      });
      const created = await this.suppliersDao.getById({ empresaId: input.empresaId, id });
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
    supplierCode?: string;
    supplierType?: string;
    companyName: string;
    contactName?: string;
    taxId?: string;
    phone?: string;
    mobile?: string;
    email?: string;
    website?: string;
    address?: string;
    city?: string;
    state?: string;
    country?: string;
    postalCode?: string;
    paymentTerms?: string;
    creditLimit?: number;
    discountPercentage?: number;
    bankName?: string;
    accountNumber?: string;
    accountType?: string;
    isActive: boolean;
    rating?: number;
    notes?: string;
  }): Promise<ApiResponse<{ done: true }>> {
    const id = Number(input.id);
    if (!Number.isFinite(id) || id <= 0) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'ID inválido' } };
    }
    const companyName = input.companyName.trim();
    if (!companyName) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'La razón social es obligatoria.' } };
    }

    const supplierCode = (input.supplierCode ?? '').trim() || null;
    const supplierType = (input.supplierType ?? '').trim() || null;
    const contactName = (input.contactName ?? '').trim() || null;
    const taxId = (input.taxId ?? '').trim() || null;
    const phone = (input.phone ?? '').trim() || null;
    const mobile = (input.mobile ?? '').trim() || null;
    const email = (input.email ?? '').trim() || null;
    const website = (input.website ?? '').trim() || null;
    const address = (input.address ?? '').trim() || null;
    const city = (input.city ?? '').trim() || null;
    const state = (input.state ?? '').trim() || null;
    const country = (input.country ?? '').trim() || null;
    const postalCode = (input.postalCode ?? '').trim() || null;
    const paymentTerms = (input.paymentTerms ?? '').trim() || null;
    const bankName = (input.bankName ?? '').trim() || null;
    const accountNumber = (input.accountNumber ?? '').trim() || null;
    const accountType = (input.accountType ?? '').trim() || null;
    const notes = (input.notes ?? '').trim() || null;

    const creditLimit =
      input.creditLimit === undefined || input.creditLimit === null || !Number.isFinite(Number(input.creditLimit))
        ? null
        : Number(input.creditLimit);
    const discountPercentage =
      input.discountPercentage === undefined ||
      input.discountPercentage === null ||
      !Number.isFinite(Number(input.discountPercentage))
        ? null
        : Number(input.discountPercentage);
    const rating =
      input.rating === undefined || input.rating === null || !Number.isFinite(Number(input.rating)) ? null : Number(input.rating);

    try {
      const existing = await this.suppliersDao.getById({ empresaId: input.empresaId, id });
      if (!existing) {
        return { ok: false, error: { code: 'NOT_FOUND', message: 'Proveedor no encontrado' } };
      }

      const isDup = await this.suppliersDao.existsDuplicate({
        empresaId: input.empresaId,
        supplierCode,
        taxId,
        idToExclude: id,
      });
      if (isDup) {
        return { ok: false, error: { code: 'DUPLICATE_SUPPLIER', message: 'Ya existe un proveedor con ese código o NIT.' } };
      }

      await this.suppliersDao.update({
        empresaId: input.empresaId,
        id,
        supplierCode,
        supplierType,
        companyName,
        contactName,
        taxId,
        phone,
        mobile,
        email,
        website,
        address,
        city,
        state,
        country,
        postalCode,
        paymentTerms,
        creditLimit,
        discountPercentage,
        bankName,
        accountNumber,
        accountType,
        isActive: Boolean(input.isActive),
        rating,
        notes,
      });
      return { ok: true, data: { done: true } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async delete(input: { empresaId: number; id: number }): Promise<ApiResponse<{ done: true }>> {
    const id = Number(input.id);
    if (!Number.isFinite(id) || id <= 0) {
      return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'ID inválido' } };
    }
    try {
      const existing = await this.suppliersDao.getById({ empresaId: input.empresaId, id });
      if (!existing) {
        return { ok: false, error: { code: 'NOT_FOUND', message: 'Proveedor no encontrado' } };
      }
      await this.suppliersDao.deactivate({ empresaId: input.empresaId, id });
      return { ok: true, data: { done: true } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }
}

