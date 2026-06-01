import { Body, Controller, Delete, Get, Param, Patch, Post, UseGuards } from '@nestjs/common';
import { ApiOkResponse, ApiTags } from '@nestjs/swagger';
import { TenantsController } from '../controller/tenants.controller';
import { TenantDetailDto } from '../modelo/tenantDetail.dto';
import { TenantDto } from '../modelo/tenant.dto';
import { TenantStatusDto } from '../modelo/tenantStatus.dto';
import { MasterUserDto } from '../modelo/masterUser.dto';
import { TenantUpdateDto } from '../modelo/tenantUpdate.dto';
import { TenantTestDbDto } from '../modelo/tenantTestDb.dto';
import { TenantUserResetPasswordDto } from '../modelo/tenantUserResetPassword.dto';
import { TenantUserUpdateEmailDto } from '../modelo/tenantUserUpdateEmail.dto';
import { TenantCreateDto } from '../modelo/tenantCreate.dto';
import { Roles } from '../../../infrastructure/auth/roles.decorator';
import { RolesGuard } from '../../../infrastructure/auth/roles.guard';

@ApiTags('super-admin')
@Controller('super-admin/tenants')
@UseGuards(RolesGuard)
@Roles('SUPER_ADMIN')
export class TenantsEndpoint {
  constructor(private readonly tenantsController: TenantsController) {}

  @Post()
  create(@Body() body: TenantCreateDto) {
    return this.tenantsController.create({
      companyName: body.companyName,
      dbHost: body.dbHost,
      dbPort: body.dbPort,
      dbName: body.dbName,
      dbUser: body.dbUser,
      dbPass: body.dbPass,
      adminName: body.adminName,
      adminEmail: body.adminEmail,
      adminPassword: body.adminPassword,
    });
  }

  @Post('sync-users')
  syncAllUsers() {
    return this.tenantsController.syncUsers({ tenantId: null });
  }

  @Post(':id/sync-users')
  syncTenantUsers(@Param('id') id: string) {
    return this.tenantsController.syncUsers({ tenantId: Number(id) });
  }

  @Get()
  @ApiOkResponse({ type: [TenantDto] })
  list() {
    return this.tenantsController.list();
  }

  @Get(':id')
  @ApiOkResponse({ type: TenantDetailDto })
  get(@Param('id') id: string) {
    return this.tenantsController.get(Number(id));
  }

  @Delete(':id')
  delete(@Param('id') id: string) {
    return this.tenantsController.deleteTenant(Number(id));
  }

  @Patch(':id/status')
  setStatus(@Param('id') id: string, @Body() body: TenantStatusDto) {
    return this.tenantsController.setStatus(Number(id), body.status);
  }

  @Patch(':id')
  update(@Param('id') id: string, @Body() body: TenantUpdateDto) {
    return this.tenantsController.update(Number(id), {
      companyName: body.companyName,
      status: body.status,
      dbHost: body.dbHost,
      dbPort: body.dbPort,
      dbName: body.dbName,
      dbUser: body.dbUser,
      dbPass: body.dbPass ?? null,
    });
  }

  @Patch(':id/test-db')
  testDb(@Param('id') id: string, @Body() body: TenantTestDbDto) {
    return this.tenantsController.testDb(Number(id), {
      dbHost: body.dbHost ?? null,
      dbPort: body.dbPort ?? null,
      dbName: body.dbName ?? null,
      dbUser: body.dbUser ?? null,
      dbPass: body.dbPass ?? null,
    });
  }

  @Get(':id/users')
  @ApiOkResponse({ type: [MasterUserDto] })
  users(@Param('id') id: string) {
    return this.tenantsController.listUsers(Number(id));
  }

  @Patch(':id/users/:userId/password')
  resetUserPassword(
    @Param('id') id: string,
    @Param('userId') userId: string,
    @Body() body: TenantUserResetPasswordDto,
  ) {
    return this.tenantsController.resetTenantUserPassword({
      empresaId: Number(id),
      userId: Number(userId),
      newPassword: body.newPassword,
    });
  }

  @Patch(':id/users/:userId/email')
  updateUserEmail(
    @Param('id') id: string,
    @Param('userId') userId: string,
    @Body() body: TenantUserUpdateEmailDto,
  ) {
    return this.tenantsController.updateTenantUserEmail({
      empresaId: Number(id),
      userId: Number(userId),
      newEmail: body.newEmail,
    });
  }

  @Delete(':id/users/:userId')
  deleteUser(@Param('id') id: string, @Param('userId') userId: string) {
    return this.tenantsController.deleteTenantUser({ empresaId: Number(id), userId: Number(userId) });
  }
}
