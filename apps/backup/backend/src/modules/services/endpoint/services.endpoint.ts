import { Body, Controller, Get, Param, Patch, Post, Query, Req, UseGuards } from '@nestjs/common';
import { ApiOkResponse, ApiTags } from '@nestjs/swagger';
import type { Request } from 'express';
import { Roles } from '../../../infrastructure/auth/roles.decorator';
import { RolesGuard } from '../../../infrastructure/auth/roles.guard';
import type { AuthTokenPayload } from '../../../infrastructure/auth/jwt';
import { ServicesController } from '../controller/services.controller';
import { ServiceDto } from '../modelo/service.dto';
import { ServicesPageDto } from '../modelo/servicesPage.dto';
import { CreateServiceDto } from '../modelo/createService.dto';
import { UpdateServiceDto } from '../modelo/updateService.dto';

@ApiTags('services')
@Controller('services')
@UseGuards(RolesGuard)
@Roles('ADMIN', 'USER')
export class ServicesEndpoint {
  constructor(private readonly servicesController: ServicesController) {}

  @Get()
  @ApiOkResponse({ type: ServicesPageDto })
  list(
    @Req() req: Request & { user?: unknown },
    @Query('search') search = '',
    @Query('categoryId') categoryId = '',
    @Query('onlyActive') onlyActive = '',
    @Query('page') page = '1',
    @Query('perPage') perPage = '10',
  ) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    const activeV = onlyActive.trim();
    return this.servicesController.list({
      empresaId: tenantId,
      search,
      categoryId: categoryId.trim() ? Number(categoryId) : undefined,
      onlyActive: activeV === '' ? undefined : activeV === '1' || activeV.toLowerCase() === 'true',
      page: Number(page),
      perPage: Number(perPage),
    });
  }

  @Get(':id')
  @ApiOkResponse({ type: ServiceDto })
  get(@Req() req: Request & { user?: unknown }, @Param('id') id: string) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    return this.servicesController.getById({ empresaId: tenantId, id: Number(id) });
  }

  @Post()
  @ApiOkResponse({ type: ServiceDto })
  create(@Req() req: Request & { user?: unknown }, @Body() body: CreateServiceDto) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    return this.servicesController.create({
      empresaId: tenantId,
      name: body.name,
      description: body.description,
      deviceCategoryId: body.deviceCategoryId,
      basePrice: body.basePrice,
      estimatedTime: body.estimatedTime,
      notes: body.notes,
    });
  }

  @Patch(':id')
  update(@Req() req: Request & { user?: unknown }, @Param('id') id: string, @Body() body: UpdateServiceDto) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    return this.servicesController.update({
      empresaId: tenantId,
      id: Number(id),
      name: body.name,
      description: body.description,
      deviceCategoryId: body.deviceCategoryId,
      basePrice: body.basePrice,
      estimatedTime: body.estimatedTime,
      notes: body.notes,
      active: body.active,
    });
  }
}

