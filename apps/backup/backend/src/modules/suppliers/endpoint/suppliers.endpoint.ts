import { Body, Controller, Delete, Get, Param, Patch, Post, Query, Req, UseGuards } from '@nestjs/common';
import { ApiOkResponse, ApiTags } from '@nestjs/swagger';
import type { Request } from 'express';
import { Roles } from '../../../infrastructure/auth/roles.decorator';
import { RolesGuard } from '../../../infrastructure/auth/roles.guard';
import type { AuthTokenPayload } from '../../../infrastructure/auth/jwt';
import { SuppliersController } from '../controller/suppliers.controller';
import { SupplierDto } from '../modelo/supplier.dto';
import { SuppliersPageDto } from '../modelo/suppliersPage.dto';
import { CreateSupplierDto } from '../modelo/createSupplier.dto';
import { UpdateSupplierDto } from '../modelo/updateSupplier.dto';

@ApiTags('suppliers')
@Controller('suppliers')
@UseGuards(RolesGuard)
@Roles('ADMIN', 'USER')
export class SuppliersEndpoint {
  constructor(private readonly suppliersController: SuppliersController) {}

  @Get()
  @ApiOkResponse({ type: SuppliersPageDto })
  list(
    @Req() req: Request & { user?: unknown },
    @Query('search') search = '',
    @Query('onlyActive') onlyActive = '',
    @Query('page') page = '1',
    @Query('perPage') perPage = '10',
  ) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) {
      return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    }
    const onlyActiveParsed = (onlyActive ?? '').trim();
    return this.suppliersController.list({
      empresaId: tenantId,
      search,
      onlyActive: onlyActiveParsed === '' ? undefined : onlyActiveParsed === '1' || onlyActiveParsed.toLowerCase() === 'true',
      page: Number(page),
      perPage: Number(perPage),
    });
  }

  @Get(':id')
  @ApiOkResponse({ type: SupplierDto })
  get(@Req() req: Request & { user?: unknown }, @Param('id') id: string) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) {
      return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    }
    return this.suppliersController.getById({ empresaId: tenantId, id: Number(id) });
  }

  @Post()
  @ApiOkResponse({ type: SupplierDto })
  create(@Req() req: Request & { user?: unknown }, @Body() body: CreateSupplierDto) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) {
      return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    }
    return this.suppliersController.create({
      empresaId: tenantId,
      supplierCode: body.supplierCode,
      supplierType: body.supplierType,
      companyName: body.companyName,
      contactName: body.contactName,
      taxId: body.taxId,
      phone: body.phone,
      mobile: body.mobile,
      email: body.email,
      website: body.website,
      address: body.address,
      city: body.city,
      state: body.state,
      country: body.country,
      postalCode: body.postalCode,
      paymentTerms: body.paymentTerms,
      creditLimit: body.creditLimit,
      discountPercentage: body.discountPercentage,
      bankName: body.bankName,
      accountNumber: body.accountNumber,
      accountType: body.accountType,
      rating: body.rating,
      notes: body.notes,
    });
  }

  @Patch(':id')
  update(@Req() req: Request & { user?: unknown }, @Param('id') id: string, @Body() body: UpdateSupplierDto) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) {
      return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    }
    return this.suppliersController.update({
      empresaId: tenantId,
      id: Number(id),
      supplierCode: body.supplierCode,
      supplierType: body.supplierType,
      companyName: body.companyName,
      contactName: body.contactName,
      taxId: body.taxId,
      phone: body.phone,
      mobile: body.mobile,
      email: body.email,
      website: body.website,
      address: body.address,
      city: body.city,
      state: body.state,
      country: body.country,
      postalCode: body.postalCode,
      paymentTerms: body.paymentTerms,
      creditLimit: body.creditLimit,
      discountPercentage: body.discountPercentage,
      bankName: body.bankName,
      accountNumber: body.accountNumber,
      accountType: body.accountType,
      isActive: body.isActive,
      rating: body.rating,
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
    return this.suppliersController.delete({ empresaId: tenantId, id: Number(id) });
  }
}

