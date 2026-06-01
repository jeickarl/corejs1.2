import { Body, Controller, Delete, Get, Param, Patch, Post, Query, Req, UseGuards } from '@nestjs/common';
import { ApiOkResponse, ApiTags } from '@nestjs/swagger';
import type { Request } from 'express';
import { Roles } from '../../../infrastructure/auth/roles.decorator';
import { RolesGuard } from '../../../infrastructure/auth/roles.guard';
import type { AuthTokenPayload } from '../../../infrastructure/auth/jwt';
import { ClientsController } from '../controller/clients.controller';
import { ClientDto } from '../modelo/client.dto';
import { ClientsPageDto } from '../modelo/clientsPage.dto';
import { ClientsStatsDto } from '../modelo/clientsStats.dto';
import { CreateClientDto } from '../modelo/createClient.dto';
import { UpdateClientDto } from '../modelo/updateClient.dto';

@ApiTags('clients')
@Controller('clients')
@UseGuards(RolesGuard)
@Roles('ADMIN', 'USER')
export class ClientsEndpoint {
  constructor(private readonly clientsController: ClientsController) {}

  @Get('stats')
  @ApiOkResponse({ type: ClientsStatsDto })
  stats(@Req() req: Request & { user?: unknown }) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) {
      return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    }
    return this.clientsController.stats(tenantId);
  }

  @Get(':id')
  @ApiOkResponse({ type: ClientDto })
  get(@Req() req: Request & { user?: unknown }, @Param('id') id: string) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) {
      return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    }
    return this.clientsController.getById({ empresaId: tenantId, id: Number(id) });
  }

  @Post()
  @ApiOkResponse({ type: ClientDto })
  create(@Req() req: Request & { user?: unknown }, @Body() body: CreateClientDto) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) {
      return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    }
    return this.clientsController.create({
      empresaId: tenantId,
      clientType: body.clientType,
      name: body.name,
      companyName: body.companyName,
      taxId: body.taxId,
      legalRepresentative: body.legalRepresentative,
      phone: body.phone,
      email: body.email,
      idNumber: body.idNumber,
      address: body.address,
      notes: body.notes,
    });
  }

  @Patch(':id')
  @ApiOkResponse({ type: ClientDto })
  update(@Req() req: Request & { user?: unknown }, @Param('id') id: string, @Body() body: UpdateClientDto) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) {
      return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    }
    return this.clientsController.update({
      empresaId: tenantId,
      id: Number(id),
      clientType: body.clientType,
      name: body.name,
      companyName: body.companyName,
      taxId: body.taxId,
      legalRepresentative: body.legalRepresentative,
      phone: body.phone,
      email: body.email,
      idNumber: body.idNumber,
      address: body.address,
      notes: body.notes,
    });
  }

  @Delete(':id')
  delete(@Req() req: Request & { user?: unknown }, @Param('id') id: string) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) {
      return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    }
    return this.clientsController.delete({ empresaId: tenantId, id: Number(id) });
  }

  @Get()
  @ApiOkResponse({ type: ClientsPageDto })
  list(
    @Req() req: Request & { user?: unknown },
    @Query('search') search = '',
    @Query('page') page = '1',
    @Query('perPage') perPage = '10',
  ) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) {
      return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    }
    return this.clientsController.list({
      empresaId: tenantId,
      search,
      page: Number(page),
      perPage: Number(perPage),
    });
  }
}
