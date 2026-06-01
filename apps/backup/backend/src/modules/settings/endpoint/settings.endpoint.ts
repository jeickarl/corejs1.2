import { Body, Controller, Delete, Get, Param, Patch, Post, Query, Req, UseGuards } from '@nestjs/common';
import { ApiOkResponse, ApiTags } from '@nestjs/swagger';
import type { Request } from 'express';
import { Roles } from '../../../infrastructure/auth/roles.decorator';
import { RolesGuard } from '../../../infrastructure/auth/roles.guard';
import type { AuthTokenPayload } from '../../../infrastructure/auth/jwt';
import { SettingsController } from '../controller/settings.controller';
import { CompanyConfigDto } from '../modelo/companyConfig.dto';
import { UpdateCompanyConfigDto } from '../modelo/updateCompanyConfig.dto';
import { RegionalConfigDto } from '../modelo/regionalConfig.dto';
import { UpdateRegionalConfigDto } from '../modelo/updateRegionalConfig.dto';
import { PaymentMethodDto } from '../modelo/paymentMethod.dto';
import { CreatePaymentMethodDto } from '../modelo/createPaymentMethod.dto';
import { UpdatePaymentMethodDto } from '../modelo/updatePaymentMethod.dto';
import { PaymentAccountDto } from '../modelo/paymentAccount.dto';
import { CreatePaymentAccountDto } from '../modelo/createPaymentAccount.dto';
import { UpdatePaymentAccountDto } from '../modelo/updatePaymentAccount.dto';
import { WhatsappTemplatesDto } from '../modelo/whatsappTemplates.dto';
import { UpdateWhatsappTemplatesDto } from '../modelo/updateWhatsappTemplates.dto';
import { AppearanceDto } from '../modelo/appearance.dto';
import { UpdateAppearanceDto } from '../modelo/updateAppearance.dto';
import { SettingsUserDto } from '../modelo/settingsUser.dto';
import { CreateSettingsUserDto } from '../modelo/createSettingsUser.dto';
import { UpdateSettingsUserDto } from '../modelo/updateSettingsUser.dto';
import { ResetSettingsUserPasswordDto } from '../modelo/resetSettingsUserPassword.dto';
import { ClientPortalConfigDto } from '../modelo/clientPortalConfig.dto';
import { UpdateClientPortalConfigDto } from '../modelo/updateClientPortalConfig.dto';
import { DeviceTypeDto } from '../modelo/deviceType.dto';
import { CreateDeviceTypeDto } from '../modelo/createDeviceType.dto';
import { UpdateDeviceTypeDto } from '../modelo/updateDeviceType.dto';
import { BrandDto } from '../modelo/brand.dto';
import { CreateBrandDto } from '../modelo/createBrand.dto';
import { UpdateBrandDto } from '../modelo/updateBrand.dto';
import { ModelDto } from '../modelo/model.dto';
import { CreateModelDto } from '../modelo/createModel.dto';
import { UpdateModelDto } from '../modelo/updateModel.dto';

@ApiTags('settings')
@Controller('settings')
@UseGuards(RolesGuard)
@Roles('ADMIN')
export class SettingsEndpoint {
  constructor(private readonly settingsController: SettingsController) {}

  private tenantIdFrom(req: Request & { user?: unknown }) {
    const user = req.user as AuthTokenPayload;
    return user.tenantId;
  }

  @Get('company')
  @ApiOkResponse({ type: CompanyConfigDto })
  getCompany(@Req() req: Request & { user?: unknown }) {
    const tenantId = this.tenantIdFrom(req);
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    return this.settingsController.getCompany({ empresaId: tenantId });
  }

  @Patch('company')
  updateCompany(@Req() req: Request & { user?: unknown }, @Body() body: UpdateCompanyConfigDto) {
    const tenantId = this.tenantIdFrom(req);
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    return this.settingsController.updateCompany({ empresaId: tenantId, ...body });
  }

  @Get('regional')
  @ApiOkResponse({ type: RegionalConfigDto })
  getRegional(@Req() req: Request & { user?: unknown }) {
    const tenantId = this.tenantIdFrom(req);
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    return this.settingsController.getRegional({ empresaId: tenantId });
  }

  @Patch('regional')
  updateRegional(@Req() req: Request & { user?: unknown }, @Body() body: UpdateRegionalConfigDto) {
    const tenantId = this.tenantIdFrom(req);
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    return this.settingsController.updateRegional({ empresaId: tenantId, ...body });
  }

  @Get('payment-methods')
  @Roles('ADMIN', 'USER')
  @ApiOkResponse({ type: [PaymentMethodDto] })
  listPaymentMethods(@Req() req: Request & { user?: unknown }, @Query('onlyActive') onlyActive = '0') {
    const tenantId = this.tenantIdFrom(req);
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    return this.settingsController.listPaymentMethods({ empresaId: tenantId, onlyActive: String(onlyActive) === '1' });
  }

  @Post('payment-methods')
  createPaymentMethod(@Req() req: Request & { user?: unknown }, @Body() body: CreatePaymentMethodDto) {
    const tenantId = this.tenantIdFrom(req);
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    return this.settingsController.createPaymentMethod({
      empresaId: tenantId,
      name: body.name,
      isDefault: body.isDefault ?? false,
      isActive: body.isActive ?? true,
    });
  }

  @Patch('payment-methods/:id')
  updatePaymentMethod(
    @Req() req: Request & { user?: unknown },
    @Param('id') id: string,
    @Body() body: UpdatePaymentMethodDto,
  ) {
    const tenantId = this.tenantIdFrom(req);
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    return this.settingsController.updatePaymentMethod({
      empresaId: tenantId,
      id: Number(id),
      name: body.name,
      isDefault: body.isDefault,
      isActive: body.isActive,
    });
  }

  @Delete('payment-methods/:id')
  deletePaymentMethod(@Req() req: Request & { user?: unknown }, @Param('id') id: string) {
    const tenantId = this.tenantIdFrom(req);
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    return this.settingsController.deletePaymentMethod({ empresaId: tenantId, id: Number(id) });
  }

  @Get('payment-methods/:id/accounts')
  @Roles('ADMIN', 'USER')
  @ApiOkResponse({ type: [PaymentAccountDto] })
  listPaymentAccounts(
    @Req() req: Request & { user?: unknown },
    @Param('id') id: string,
    @Query('onlyActive') onlyActive = '0',
  ) {
    const tenantId = this.tenantIdFrom(req);
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    return this.settingsController.listPaymentAccounts({
      empresaId: tenantId,
      paymentMethodId: Number(id),
      onlyActive: String(onlyActive) === '1',
    });
  }

  @Post('payment-methods/:id/accounts')
  createPaymentAccount(@Req() req: Request & { user?: unknown }, @Param('id') id: string, @Body() body: CreatePaymentAccountDto) {
    const tenantId = this.tenantIdFrom(req);
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    return this.settingsController.createPaymentAccount({
      empresaId: tenantId,
      paymentMethodId: Number(id),
      alias: body.alias,
      accountNumber: body.accountNumber ?? '',
      accountType: body.accountType ?? '',
      holderName: body.holderName ?? '',
      holderId: body.holderId ?? '',
      isActive: body.isActive === false ? false : true,
    });
  }

  @Patch('payment-methods/:methodId/accounts/:id')
  updatePaymentAccount(
    @Req() req: Request & { user?: unknown },
    @Param('methodId') methodId: string,
    @Param('id') id: string,
    @Body() body: UpdatePaymentAccountDto,
  ) {
    const tenantId = this.tenantIdFrom(req);
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    const accountId = Number(id);
    const pmId = Number(methodId);
    return this.settingsController.updatePaymentAccount({
      empresaId: tenantId,
      id: accountId,
      paymentMethodId: pmId,
      alias: body.alias ?? '',
      accountNumber: body.accountNumber ?? '',
      accountType: body.accountType ?? '',
      holderName: body.holderName ?? '',
      holderId: body.holderId ?? '',
      isActive: body.isActive === false ? false : true,
    });
  }

  @Delete('payment-accounts/:id')
  deletePaymentAccount(@Req() req: Request & { user?: unknown }, @Param('id') id: string) {
    const tenantId = this.tenantIdFrom(req);
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    return this.settingsController.deletePaymentAccount({ empresaId: tenantId, id: Number(id) });
  }

  @Get('whatsapp')
  @ApiOkResponse({ type: WhatsappTemplatesDto })
  getWhatsapp(@Req() req: Request & { user?: unknown }) {
    const tenantId = this.tenantIdFrom(req);
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    return this.settingsController.getWhatsappTemplates({ empresaId: tenantId });
  }

  @Patch('whatsapp')
  updateWhatsapp(@Req() req: Request & { user?: unknown }, @Body() body: UpdateWhatsappTemplatesDto) {
    const tenantId = this.tenantIdFrom(req);
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    return this.settingsController.updateWhatsappTemplates({ empresaId: tenantId, ...body });
  }

  @Get('appearance')
  @ApiOkResponse({ type: AppearanceDto })
  getAppearance(@Req() req: Request & { user?: unknown }) {
    const tenantId = this.tenantIdFrom(req);
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    return this.settingsController.getAppearance({ empresaId: tenantId });
  }

  @Patch('appearance')
  updateAppearance(@Req() req: Request & { user?: unknown }, @Body() body: UpdateAppearanceDto) {
    const tenantId = this.tenantIdFrom(req);
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    return this.settingsController.updateAppearance({ empresaId: tenantId, themeMode: body.themeMode });
  }

  @Get('client-portal')
  @ApiOkResponse({ type: ClientPortalConfigDto })
  getClientPortal(@Req() req: Request & { user?: unknown }) {
    const tenantId = this.tenantIdFrom(req);
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    return this.settingsController.getClientPortalConfig({ empresaId: tenantId });
  }

  @Patch('client-portal')
  updateClientPortal(@Req() req: Request & { user?: unknown }, @Body() body: UpdateClientPortalConfigDto) {
    const tenantId = this.tenantIdFrom(req);
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    return this.settingsController.updateClientPortalConfig({ empresaId: tenantId, ...body });
  }

  @Get('device-types')
  @Roles('ADMIN', 'USER')
  @ApiOkResponse({ type: [DeviceTypeDto] })
  listDeviceTypes(@Req() req: Request & { user?: unknown }, @Query('search') search = '', @Query('onlyActive') onlyActive = '0') {
    const tenantId = this.tenantIdFrom(req);
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    return this.settingsController.listDeviceTypes({
      empresaId: tenantId,
      search: String(search ?? ''),
      onlyActive: String(onlyActive) === '1',
    });
  }

  @Post('device-types')
  createDeviceType(@Req() req: Request & { user?: unknown }, @Body() body: CreateDeviceTypeDto) {
    const tenantId = this.tenantIdFrom(req);
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    return this.settingsController.createDeviceType({
      empresaId: tenantId,
      name: body.name,
      sortOrder: body.sortOrder,
      isActive: body.isActive === false ? false : true,
    });
  }

  @Patch('device-types/:id')
  updateDeviceType(@Req() req: Request & { user?: unknown }, @Param('id') id: string, @Body() body: UpdateDeviceTypeDto) {
    const tenantId = this.tenantIdFrom(req);
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    return this.settingsController.updateDeviceType({
      empresaId: tenantId,
      id: Number(id),
      name: body.name,
      sortOrder: body.sortOrder,
      isActive: body.isActive,
    });
  }

  @Delete('device-types/:id')
  deleteDeviceType(@Req() req: Request & { user?: unknown }, @Param('id') id: string) {
    const tenantId = this.tenantIdFrom(req);
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    return this.settingsController.deleteDeviceType({ empresaId: tenantId, id: Number(id) });
  }

  @Get('brands')
  @Roles('ADMIN', 'USER')
  @ApiOkResponse({ type: [BrandDto] })
  listBrands(@Req() req: Request & { user?: unknown }, @Query('search') search = '', @Query('onlyActive') onlyActive = '0') {
    const tenantId = this.tenantIdFrom(req);
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    return this.settingsController.listBrands({
      empresaId: tenantId,
      search: String(search ?? ''),
      onlyActive: String(onlyActive) === '1',
    });
  }

  @Post('brands')
  createBrand(@Req() req: Request & { user?: unknown }, @Body() body: CreateBrandDto) {
    const tenantId = this.tenantIdFrom(req);
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    return this.settingsController.createBrand({
      empresaId: tenantId,
      name: body.name,
      isActive: body.isActive === false ? false : true,
    });
  }

  @Patch('brands/:id')
  updateBrand(@Req() req: Request & { user?: unknown }, @Param('id') id: string, @Body() body: UpdateBrandDto) {
    const tenantId = this.tenantIdFrom(req);
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    return this.settingsController.updateBrand({
      empresaId: tenantId,
      id: Number(id),
      name: body.name,
      isActive: body.isActive,
    });
  }

  @Delete('brands/:id')
  deleteBrand(@Req() req: Request & { user?: unknown }, @Param('id') id: string) {
    const tenantId = this.tenantIdFrom(req);
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    return this.settingsController.deleteBrand({ empresaId: tenantId, id: Number(id) });
  }

  @Get('models')
  @Roles('ADMIN', 'USER')
  @ApiOkResponse({ type: [ModelDto] })
  listModels(
    @Req() req: Request & { user?: unknown },
    @Query('search') search = '',
    @Query('brandId') brandId = '',
    @Query('deviceTypeId') deviceTypeId = '',
    @Query('onlyActive') onlyActive = '0',
  ) {
    const tenantId = this.tenantIdFrom(req);
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    const b = String(brandId ?? '').trim();
    const dt = String(deviceTypeId ?? '').trim();
    return this.settingsController.listModels({
      empresaId: tenantId,
      search: String(search ?? ''),
      brandId: b ? Number(b) : null,
      deviceTypeId: dt ? Number(dt) : null,
      onlyActive: String(onlyActive) === '1',
    });
  }

  @Post('models')
  createModel(@Req() req: Request & { user?: unknown }, @Body() body: CreateModelDto) {
    const tenantId = this.tenantIdFrom(req);
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    return this.settingsController.createModel({
      empresaId: tenantId,
      name: body.name,
      brandId: body.brandId === undefined ? null : (body.brandId ?? null),
      deviceTypeId: body.deviceTypeId === undefined ? null : (body.deviceTypeId ?? null),
      isActive: body.isActive === false ? false : true,
    });
  }

  @Patch('models/:id')
  updateModel(@Req() req: Request & { user?: unknown }, @Param('id') id: string, @Body() body: UpdateModelDto) {
    const tenantId = this.tenantIdFrom(req);
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    return this.settingsController.updateModel({
      empresaId: tenantId,
      id: Number(id),
      name: body.name,
      brandId: body.brandId,
      deviceTypeId: body.deviceTypeId,
      isActive: body.isActive,
    });
  }

  @Delete('models/:id')
  deleteModel(@Req() req: Request & { user?: unknown }, @Param('id') id: string) {
    const tenantId = this.tenantIdFrom(req);
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    return this.settingsController.deleteModel({ empresaId: tenantId, id: Number(id) });
  }

  @Get('users')
  @ApiOkResponse({ type: [SettingsUserDto] })
  listUsers(@Req() req: Request & { user?: unknown }) {
    const tenantId = this.tenantIdFrom(req);
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    return this.settingsController.listUsers({ empresaId: tenantId });
  }

  @Post('users')
  createUser(@Req() req: Request & { user?: unknown }, @Body() body: CreateSettingsUserDto) {
    const tenantId = this.tenantIdFrom(req);
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    return this.settingsController.createUser({
      empresaId: tenantId,
      email: body.email,
      name: body.name,
      role: body.role,
      password: body.password,
      active: body.active === false ? false : true,
    });
  }

  @Patch('users/:id')
  updateUser(@Req() req: Request & { user?: unknown }, @Param('id') id: string, @Body() body: UpdateSettingsUserDto) {
    const tenantId = this.tenantIdFrom(req);
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    return this.settingsController.updateUser({
      empresaId: tenantId,
      userId: Number(id),
      email: body.email,
      name: body.name,
      role: body.role,
      active: body.active,
    });
  }

  @Patch('users/:id/password')
  resetPassword(@Req() req: Request & { user?: unknown }, @Param('id') id: string, @Body() body: ResetSettingsUserPasswordDto) {
    const tenantId = this.tenantIdFrom(req);
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    return this.settingsController.resetUserPassword({
      empresaId: tenantId,
      userId: Number(id),
      newPassword: body.newPassword,
    });
  }

  @Delete('users/:id')
  deleteUser(@Req() req: Request & { user?: unknown }, @Param('id') id: string) {
    const tenantId = this.tenantIdFrom(req);
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    return this.settingsController.deleteUser({ empresaId: tenantId, userId: Number(id) });
  }
}
