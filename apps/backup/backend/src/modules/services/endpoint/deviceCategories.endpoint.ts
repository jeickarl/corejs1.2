import { Body, Controller, Delete, Get, Param, Patch, Post, Query, Req, UseGuards } from '@nestjs/common';
import { ApiOkResponse, ApiTags } from '@nestjs/swagger';
import type { Request } from 'express';
import { Roles } from '../../../infrastructure/auth/roles.decorator';
import { RolesGuard } from '../../../infrastructure/auth/roles.guard';
import type { AuthTokenPayload } from '../../../infrastructure/auth/jwt';
import { DeviceCategoriesController } from '../controller/deviceCategories.controller';
import { DeviceCategoriesListDto } from '../modelo/deviceCategoriesList.dto';
import { DeviceCategoryDto } from '../modelo/deviceCategory.dto';
import { CreateDeviceCategoryDto } from '../modelo/createDeviceCategory.dto';
import { UpdateDeviceCategoryDto } from '../modelo/updateDeviceCategory.dto';

@ApiTags('service-categories')
@Controller('services/categories')
@UseGuards(RolesGuard)
@Roles('ADMIN', 'USER')
export class DeviceCategoriesEndpoint {
  constructor(private readonly categoriesController: DeviceCategoriesController) {}

  @Get()
  @ApiOkResponse({ type: DeviceCategoriesListDto })
  list(@Req() req: Request & { user?: unknown }, @Query('onlyActive') onlyActive = '') {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    const v = onlyActive.trim();
    return this.categoriesController.list({
      empresaId: tenantId,
      onlyActive: v === '' ? undefined : v === '1' || v.toLowerCase() === 'true',
    });
  }

  @Post()
  @ApiOkResponse({ type: DeviceCategoryDto })
  create(@Req() req: Request & { user?: unknown }, @Body() body: CreateDeviceCategoryDto) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    return this.categoriesController.create({
      empresaId: tenantId,
      name: body.name,
      description: body.description,
      sortOrder: body.sortOrder,
    });
  }

  @Patch(':id')
  update(@Req() req: Request & { user?: unknown }, @Param('id') id: string, @Body() body: UpdateDeviceCategoryDto) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    return this.categoriesController.update({
      empresaId: tenantId,
      id: Number(id),
      name: body.name,
      description: body.description,
      sortOrder: body.sortOrder,
      active: body.active,
    });
  }

  @Delete(':id')
  delete(@Req() req: Request & { user?: unknown }, @Param('id') id: string) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    return this.categoriesController.delete({ empresaId: tenantId, id: Number(id) });
  }
}

