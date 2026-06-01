import type { ApiResponse } from '@corejs/shared/types';
import { Injectable } from '@nestjs/common';
import bcrypt from 'bcryptjs';
import {
  SettingsDao,
  type BrandRow,
  type ClientPortalConfigRow,
  type CompanyConfigRow,
  type DeviceTypeRow,
  type ModelRow,
  type PaymentAccountRow,
  type PaymentMethodRow,
  type RegionalConfigRow,
} from '../daos/settings.dao';
import { SettingsUsersDao } from '../daos/settingsUsers.dao';

@Injectable()
export class SettingsController {
  constructor(
    private readonly settingsDao: SettingsDao,
    private readonly settingsUsersDao: SettingsUsersDao,
  ) {}

  async getCompany(input: { empresaId: number }): Promise<ApiResponse<CompanyConfigRow>> {
    try {
      const data = await this.settingsDao.getCompany(input);
      return { ok: true, data };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async updateCompany(input: { empresaId: number } & Partial<CompanyConfigRow>): Promise<ApiResponse<{ done: true }>> {
    try {
      const existing = await this.settingsDao.getCompany({ empresaId: input.empresaId });
      const ok = await this.settingsDao.updateCompany({
        empresaId: input.empresaId,
        companyName: (input.companyName ?? existing.companyName).trim(),
        companyPhone: (input.companyPhone ?? existing.companyPhone).trim(),
        companyEmail: (input.companyEmail ?? existing.companyEmail).trim(),
        companyWebsite: (input.companyWebsite ?? existing.companyWebsite).trim(),
        companyAddress: (input.companyAddress ?? existing.companyAddress).trim(),
        logoUrl: (input.logoUrl ?? existing.logoUrl).trim(),
      });
      if (!ok) return { ok: false, error: { code: 'DB_ERROR', message: 'No se pudo guardar' } };
      return { ok: true, data: { done: true } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async getRegional(input: { empresaId: number }): Promise<ApiResponse<RegionalConfigRow>> {
    try {
      const data = await this.settingsDao.getRegional(input);
      return { ok: true, data };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async updateRegional(input: { empresaId: number } & Partial<RegionalConfigRow>): Promise<ApiResponse<{ done: true }>> {
    try {
      const existing = await this.settingsDao.getRegional({ empresaId: input.empresaId });
      const ok = await this.settingsDao.updateRegional({
        empresaId: input.empresaId,
        currency: (input.currency ?? existing.currency).trim() || 'COP',
        currencySymbol: (input.currencySymbol ?? existing.currencySymbol).trim() || '$',
        taxEnabled: input.taxEnabled === undefined ? existing.taxEnabled : Boolean(input.taxEnabled),
        taxName: (input.taxName ?? existing.taxName).trim() || 'IVA',
        taxRate: input.taxRate === undefined ? existing.taxRate : Number(input.taxRate),
        invoiceDueDaysDefault:
          input.invoiceDueDaysDefault === undefined ? existing.invoiceDueDaysDefault : Number(input.invoiceDueDaysDefault),
      });
      if (!ok) return { ok: false, error: { code: 'DB_ERROR', message: 'No se pudo guardar' } };
      return { ok: true, data: { done: true } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async listPaymentMethods(input: { empresaId: number; onlyActive?: boolean }): Promise<ApiResponse<PaymentMethodRow[]>> {
    try {
      const rows = await this.settingsDao.listPaymentMethods(input);
      return { ok: true, data: rows };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async createPaymentMethod(input: {
    empresaId: number;
    name: string;
    isDefault?: boolean;
    isActive?: boolean;
  }): Promise<ApiResponse<{ id: number }>> {
    const name = (input.name ?? '').trim();
    if (!name) return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Nombre obligatorio' } };
    try {
      const id = await this.settingsDao.createPaymentMethod({
        empresaId: input.empresaId,
        name,
        isDefault: Boolean(input.isDefault),
        isActive: input.isActive === false ? false : true,
      });
      return { ok: true, data: { id } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async updatePaymentMethod(input: {
    empresaId: number;
    id: number;
    name?: string;
    isDefault?: boolean;
    isActive?: boolean;
  }): Promise<ApiResponse<{ done: true }>> {
    const id = Number(input.id);
    if (!Number.isFinite(id) || id <= 0) return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'ID inválido' } };
    try {
      const existing = (await this.settingsDao.listPaymentMethods({ empresaId: input.empresaId })).find((m) => m.id === id);
      if (!existing) return { ok: false, error: { code: 'NOT_FOUND', message: 'No encontrado' } };
      const ok = await this.settingsDao.updatePaymentMethod({
        empresaId: input.empresaId,
        id,
        name: (input.name ?? existing.name).trim(),
        isDefault: input.isDefault === undefined ? existing.isDefault : Boolean(input.isDefault),
        isActive: input.isActive === undefined ? existing.isActive : Boolean(input.isActive),
      });
      if (!ok) return { ok: false, error: { code: 'DB_ERROR', message: 'No se pudo guardar' } };
      return { ok: true, data: { done: true } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async deletePaymentMethod(input: { empresaId: number; id: number }): Promise<ApiResponse<{ done: true }>> {
    const id = Number(input.id);
    if (!Number.isFinite(id) || id <= 0) return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'ID inválido' } };
    try {
      const ok = await this.settingsDao.deactivatePaymentMethod({ empresaId: input.empresaId, id });
      if (!ok) return { ok: false, error: { code: 'NOT_FOUND', message: 'No encontrado' } };
      return { ok: true, data: { done: true } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async listPaymentAccounts(input: { empresaId: number; paymentMethodId: number; onlyActive?: boolean }): Promise<ApiResponse<PaymentAccountRow[]>> {
    try {
      const rows = await this.settingsDao.listPaymentAccounts(input);
      return { ok: true, data: rows };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async createPaymentAccount(input: {
    empresaId: number;
    paymentMethodId: number;
    alias: string;
    accountNumber: string;
    accountType: string;
    holderName: string;
    holderId: string;
    isActive: boolean;
  }): Promise<ApiResponse<{ id: number }>> {
    const alias = (input.alias ?? '').trim();
    if (!alias) return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Alias obligatorio' } };
    try {
      const id = await this.settingsDao.createPaymentAccount({
        empresaId: input.empresaId,
        paymentMethodId: input.paymentMethodId,
        alias,
        accountNumber: (input.accountNumber ?? '').trim(),
        accountType: (input.accountType ?? '').trim(),
        holderName: (input.holderName ?? '').trim(),
        holderId: (input.holderId ?? '').trim(),
        isActive: input.isActive,
      });
      return { ok: true, data: { id } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async updatePaymentAccount(input: {
    empresaId: number;
    id: number;
    paymentMethodId: number;
    alias: string;
    accountNumber: string;
    accountType: string;
    holderName: string;
    holderId: string;
    isActive: boolean;
  }): Promise<ApiResponse<{ done: true }>> {
    const alias = (input.alias ?? '').trim();
    if (!alias) return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Alias obligatorio' } };
    try {
      const ok = await this.settingsDao.updatePaymentAccount({
        empresaId: input.empresaId,
        id: input.id,
        paymentMethodId: input.paymentMethodId,
        alias,
        accountNumber: (input.accountNumber ?? '').trim(),
        accountType: (input.accountType ?? '').trim(),
        holderName: (input.holderName ?? '').trim(),
        holderId: (input.holderId ?? '').trim(),
        isActive: input.isActive,
      });
      if (!ok) return { ok: false, error: { code: 'NOT_FOUND', message: 'No encontrado' } };
      return { ok: true, data: { done: true } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async deletePaymentAccount(input: { empresaId: number; id: number }): Promise<ApiResponse<{ done: true }>> {
    const id = Number(input.id);
    if (!Number.isFinite(id) || id <= 0) return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'ID inválido' } };
    try {
      const ok = await this.settingsDao.deactivatePaymentAccount({ empresaId: input.empresaId, id });
      if (!ok) return { ok: false, error: { code: 'NOT_FOUND', message: 'No encontrado' } };
      return { ok: true, data: { done: true } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async getClientPortalConfig(input: { empresaId: number }): Promise<ApiResponse<ClientPortalConfigRow>> {
    try {
      const data = await this.settingsDao.getClientPortalConfig(input);
      return { ok: true, data };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async updateClientPortalConfig(input: { empresaId: number } & Partial<ClientPortalConfigRow>): Promise<ApiResponse<{ done: true }>> {
    try {
      const existing = await this.settingsDao.getClientPortalConfig({ empresaId: input.empresaId });
      const ok = await this.settingsDao.updateClientPortalConfig({
        empresaId: input.empresaId,
        enableLookupById: input.enableLookupById === undefined ? existing.enableLookupById : Boolean(input.enableLookupById),
        showTimeline: input.showTimeline === undefined ? existing.showTimeline : Boolean(input.showTimeline),
        allowApproval: input.allowApproval === undefined ? existing.allowApproval : Boolean(input.allowApproval),
        homeTitle: String(input.homeTitle ?? existing.homeTitle),
        homeSubtitle: String(input.homeSubtitle ?? existing.homeSubtitle),
        whatsappLink: String(input.whatsappLink ?? existing.whatsappLink),
        addressText: String(input.addressText ?? existing.addressText),
        hoursText: String(input.hoursText ?? existing.hoursText),
        mapEmbedUrl: String(input.mapEmbedUrl ?? existing.mapEmbedUrl),
      });
      if (!ok) return { ok: false, error: { code: 'DB_ERROR', message: 'No se pudo guardar' } };
      return { ok: true, data: { done: true } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async listDeviceTypes(input: { empresaId: number; search?: string; onlyActive?: boolean }): Promise<ApiResponse<DeviceTypeRow[]>> {
    try {
      const rows = await this.settingsDao.listDeviceTypes(input);
      return { ok: true, data: rows };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async createDeviceType(input: {
    empresaId: number;
    name: string;
    sortOrder?: number;
    isActive: boolean;
  }): Promise<ApiResponse<{ id: number }>> {
    const name = (input.name ?? '').trim();
    if (!name) return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Nombre obligatorio' } };
    try {
      const id = await this.settingsDao.createDeviceType({
        empresaId: input.empresaId,
        name,
        sortOrder: Number(input.sortOrder ?? 0),
        isActive: input.isActive,
      });
      return { ok: true, data: { id } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async updateDeviceType(input: {
    empresaId: number;
    id: number;
    name?: string;
    sortOrder?: number;
    isActive?: boolean;
  }): Promise<ApiResponse<{ done: true }>> {
    const id = Number(input.id);
    if (!Number.isFinite(id) || id <= 0) return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'ID inválido' } };
    try {
      const existing = (await this.settingsDao.listDeviceTypes({ empresaId: input.empresaId })).find((x) => x.id === id);
      if (!existing) return { ok: false, error: { code: 'NOT_FOUND', message: 'No encontrado' } };
      const name = String(input.name ?? existing.name).trim();
      if (!name) return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Nombre obligatorio' } };
      const ok = await this.settingsDao.updateDeviceType({
        empresaId: input.empresaId,
        id,
        name,
        sortOrder: Number(input.sortOrder ?? existing.sortOrder),
        isActive: input.isActive === undefined ? existing.isActive : Boolean(input.isActive),
      });
      if (!ok) return { ok: false, error: { code: 'NOT_FOUND', message: 'No encontrado' } };
      return { ok: true, data: { done: true } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async deleteDeviceType(input: { empresaId: number; id: number }): Promise<ApiResponse<{ done: true }>> {
    const id = Number(input.id);
    if (!Number.isFinite(id) || id <= 0) return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'ID inválido' } };
    try {
      const ok = await this.settingsDao.deactivateDeviceType({ empresaId: input.empresaId, id });
      if (!ok) return { ok: false, error: { code: 'NOT_FOUND', message: 'No encontrado' } };
      return { ok: true, data: { done: true } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async listBrands(input: { empresaId: number; search?: string; onlyActive?: boolean }): Promise<ApiResponse<BrandRow[]>> {
    try {
      const rows = await this.settingsDao.listBrands(input);
      return { ok: true, data: rows };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async createBrand(input: { empresaId: number; name: string; isActive: boolean }): Promise<ApiResponse<{ id: number }>> {
    const name = (input.name ?? '').trim();
    if (!name) return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Nombre obligatorio' } };
    try {
      const id = await this.settingsDao.createBrand({ empresaId: input.empresaId, name, isActive: input.isActive });
      return { ok: true, data: { id } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async updateBrand(input: {
    empresaId: number;
    id: number;
    name?: string;
    isActive?: boolean;
  }): Promise<ApiResponse<{ done: true }>> {
    const id = Number(input.id);
    if (!Number.isFinite(id) || id <= 0) return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'ID inválido' } };
    try {
      const existing = (await this.settingsDao.listBrands({ empresaId: input.empresaId })).find((x) => x.id === id);
      if (!existing) return { ok: false, error: { code: 'NOT_FOUND', message: 'No encontrado' } };
      const name = String(input.name ?? existing.name).trim();
      if (!name) return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Nombre obligatorio' } };
      const ok = await this.settingsDao.updateBrand({
        empresaId: input.empresaId,
        id,
        name,
        isActive: input.isActive === undefined ? existing.isActive : Boolean(input.isActive),
      });
      if (!ok) return { ok: false, error: { code: 'NOT_FOUND', message: 'No encontrado' } };
      return { ok: true, data: { done: true } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async deleteBrand(input: { empresaId: number; id: number }): Promise<ApiResponse<{ done: true }>> {
    const id = Number(input.id);
    if (!Number.isFinite(id) || id <= 0) return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'ID inválido' } };
    try {
      const ok = await this.settingsDao.deactivateBrand({ empresaId: input.empresaId, id });
      if (!ok) return { ok: false, error: { code: 'NOT_FOUND', message: 'No encontrado' } };
      return { ok: true, data: { done: true } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async listModels(input: {
    empresaId: number;
    search?: string;
    brandId?: number | null;
    deviceTypeId?: number | null;
    onlyActive?: boolean;
  }): Promise<ApiResponse<ModelRow[]>> {
    try {
      const rows = await this.settingsDao.listModels(input);
      return { ok: true, data: rows };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async createModel(input: {
    empresaId: number;
    name: string;
    brandId: number | null;
    deviceTypeId: number | null;
    isActive: boolean;
  }): Promise<ApiResponse<{ id: number }>> {
    const name = (input.name ?? '').trim();
    if (!name) return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Nombre obligatorio' } };
    try {
      const id = await this.settingsDao.createModel({
        empresaId: input.empresaId,
        name,
        brandId: input.brandId ?? null,
        deviceTypeId: input.deviceTypeId ?? null,
        isActive: input.isActive,
      });
      return { ok: true, data: { id } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async updateModel(input: {
    empresaId: number;
    id: number;
    name?: string;
    brandId?: number | null;
    deviceTypeId?: number | null;
    isActive?: boolean;
  }): Promise<ApiResponse<{ done: true }>> {
    const id = Number(input.id);
    if (!Number.isFinite(id) || id <= 0) return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'ID inválido' } };
    try {
      const existing = (await this.settingsDao.listModels({ empresaId: input.empresaId })).find((x) => x.id === id);
      if (!existing) return { ok: false, error: { code: 'NOT_FOUND', message: 'No encontrado' } };
      const name = String(input.name ?? existing.name).trim();
      if (!name) return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Nombre obligatorio' } };
      const ok = await this.settingsDao.updateModel({
        empresaId: input.empresaId,
        id,
        name,
        brandId: input.brandId === undefined ? existing.brandId : (input.brandId ?? null),
        deviceTypeId: input.deviceTypeId === undefined ? existing.deviceTypeId : (input.deviceTypeId ?? null),
        isActive: input.isActive === undefined ? existing.isActive : Boolean(input.isActive),
      });
      if (!ok) return { ok: false, error: { code: 'NOT_FOUND', message: 'No encontrado' } };
      return { ok: true, data: { done: true } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async deleteModel(input: { empresaId: number; id: number }): Promise<ApiResponse<{ done: true }>> {
    const id = Number(input.id);
    if (!Number.isFinite(id) || id <= 0) return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'ID inválido' } };
    try {
      const ok = await this.settingsDao.deactivateModel({ empresaId: input.empresaId, id });
      if (!ok) return { ok: false, error: { code: 'NOT_FOUND', message: 'No encontrado' } };
      return { ok: true, data: { done: true } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async getWhatsappTemplates(input: { empresaId: number }) {
    try {
      const data = await this.settingsDao.getWhatsappTemplates(input);
      return { ok: true, data };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async updateWhatsappTemplates(input: { empresaId: number } & Partial<{ reception: string; ready: string; delivery: string; sale: string }>) {
    try {
      const existing = await this.settingsDao.getWhatsappTemplates({ empresaId: input.empresaId });
      const ok = await this.settingsDao.updateWhatsappTemplates({
        empresaId: input.empresaId,
        reception: String(input.reception ?? existing.reception),
        ready: String(input.ready ?? existing.ready),
        delivery: String(input.delivery ?? existing.delivery),
        sale: String(input.sale ?? existing.sale),
      });
      if (!ok) return { ok: false, error: { code: 'DB_ERROR', message: 'No se pudo guardar' } };
      return { ok: true, data: { done: true } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async getAppearance(input: { empresaId: number }) {
    try {
      const data = await this.settingsDao.getAppearance(input);
      return { ok: true, data };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async updateAppearance(input: { empresaId: number; themeMode: 'light' | 'dark' }) {
    try {
      const ok = await this.settingsDao.updateAppearance(input);
      if (!ok) return { ok: false, error: { code: 'DB_ERROR', message: 'No se pudo guardar' } };
      return { ok: true, data: { done: true } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async listUsers(input: { empresaId: number }) {
    try {
      const rows = await this.settingsUsersDao.listByEmpresaId(input.empresaId);
      return { ok: true, data: rows };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async createUser(input: {
    empresaId: number;
    email: string;
    name: string;
    role: 'admin' | 'user';
    password: string;
    active: boolean;
  }) {
    const email = (input.email ?? '').trim().toLowerCase();
    const name = (input.name ?? '').trim();
    const role = input.role === 'admin' ? 'admin' : 'user';
    if (!email) return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Email obligatorio' } };
    if (!name) return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Nombre obligatorio' } };
    if (!input.password) return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Password obligatorio' } };
    try {
      const exists = await this.settingsUsersDao.emailExists({ email });
      if (exists) return { ok: false, error: { code: 'EMAIL_IN_USE', message: 'El email ya está registrado.' } };
      const hash = await bcrypt.hash(input.password, 10);
      const id = await this.settingsUsersDao.create({
        empresaId: input.empresaId,
        email,
        name,
        role,
        active: input.active,
        passwordHash: hash,
      });
      return { ok: true, data: { id } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async updateUser(input: {
    empresaId: number;
    userId: number;
    email?: string;
    name?: string;
    role?: 'admin' | 'user';
    active?: boolean;
  }) {
    const userId = Number(input.userId);
    if (!Number.isFinite(userId) || userId <= 0) return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'ID inválido' } };
    try {
      const existing = await this.settingsUsersDao.getById({ empresaId: input.empresaId, userId });
      if (!existing) return { ok: false, error: { code: 'NOT_FOUND', message: 'Usuario no encontrado' } };

      const nextEmail = (input.email ?? existing.email).trim().toLowerCase();
      const nextName = (input.name ?? existing.name).trim();
      const nextRole = (input.role ?? existing.role) === 'admin' ? 'admin' : 'user';
      const nextActive = input.active === undefined ? existing.active : Boolean(input.active);

      if (!nextEmail) return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Email obligatorio' } };
      if (!nextName) return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Nombre obligatorio' } };

      const emailInUse = await this.settingsUsersDao.emailExists({ email: nextEmail, excludeUserId: userId });
      if (emailInUse) return { ok: false, error: { code: 'EMAIL_IN_USE', message: 'El email ya está registrado.' } };

      if (existing.role === 'admin' && existing.active && (!nextActive || nextRole !== 'admin')) {
        const admins = await this.settingsUsersDao.countActiveAdmins(input.empresaId);
        if (admins <= 1) {
          return { ok: false, error: { code: 'LAST_ADMIN', message: 'No se puede quitar el último administrador activo.' } };
        }
      }

      const ok = await this.settingsUsersDao.update({
        empresaId: input.empresaId,
        userId,
        email: nextEmail,
        name: nextName,
        role: nextRole,
        active: nextActive,
      });
      if (!ok) return { ok: false, error: { code: 'NOT_FOUND', message: 'Usuario no encontrado' } };
      return { ok: true, data: { done: true } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async resetUserPassword(input: { empresaId: number; userId: number; newPassword: string }) {
    const userId = Number(input.userId);
    if (!Number.isFinite(userId) || userId <= 0) return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'ID inválido' } };
    const pw = String(input.newPassword ?? '').trim();
    if (!pw) return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'Password inválido' } };
    try {
      const user = await this.settingsUsersDao.getById({ empresaId: input.empresaId, userId });
      if (!user) return { ok: false, error: { code: 'NOT_FOUND', message: 'Usuario no encontrado' } };
      const hash = await bcrypt.hash(pw, 10);
      const ok = await this.settingsUsersDao.updatePasswordHash({ empresaId: input.empresaId, userId, passwordHash: hash });
      if (!ok) return { ok: false, error: { code: 'NOT_FOUND', message: 'Usuario no encontrado' } };
      return { ok: true, data: { done: true } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }

  async deleteUser(input: { empresaId: number; userId: number }) {
    const userId = Number(input.userId);
    if (!Number.isFinite(userId) || userId <= 0) return { ok: false, error: { code: 'VALIDATION_ERROR', message: 'ID inválido' } };
    try {
      const user = await this.settingsUsersDao.getById({ empresaId: input.empresaId, userId });
      if (!user) return { ok: false, error: { code: 'NOT_FOUND', message: 'Usuario no encontrado' } };
      if (user.role === 'admin' && user.active) {
        const admins = await this.settingsUsersDao.countActiveAdmins(input.empresaId);
        if (admins <= 1) {
          return { ok: false, error: { code: 'LAST_ADMIN', message: 'No se puede eliminar el último administrador activo.' } };
        }
      }
      const ok = await this.settingsUsersDao.delete({ empresaId: input.empresaId, userId });
      if (!ok) return { ok: false, error: { code: 'NOT_FOUND', message: 'Usuario no encontrado' } };
      return { ok: true, data: { deleted: true } };
    } catch {
      return { ok: false, error: { code: 'DB_ERROR', message: 'Error de base de datos' } };
    }
  }
}
