import { Body, Controller, Delete, Get, Param, Patch, Post, Query, Req, UseGuards } from '@nestjs/common';
import { ApiOkResponse, ApiTags } from '@nestjs/swagger';
import type { Request } from 'express';
import { Roles } from '../../../infrastructure/auth/roles.decorator';
import { RolesGuard } from '../../../infrastructure/auth/roles.guard';
import type { AuthTokenPayload } from '../../../infrastructure/auth/jwt';
import { OrdersController } from '../controller/orders.controller';
import { ChangeOrderStatusDto } from '../modelo/changeOrderStatus.dto';
import { ChangeOrderApprovalDto } from '../modelo/changeOrderApproval.dto';
import { CreateOrderDto } from '../modelo/createOrder.dto';
import { CreateAccessoryDto } from '../modelo/createAccessory.dto';
import { OrderDto } from '../modelo/order.dto';
import { OrdersPageDto } from '../modelo/ordersPage.dto';
import { OrderStatusDto } from '../modelo/orderStatus.dto';
import { SerialLookupDto } from '../modelo/serialLookup.dto';
import { UpdateOrderDto } from '../modelo/updateOrder.dto';
import { AccessoryDto } from '../modelo/accessory.dto';
import { OrderStatusHistoryDto } from '../modelo/orderStatusHistory.dto';
import { ClientOrdersStatsDto } from '../modelo/clientOrdersStats.dto';
import { TechnicalReportListItemDto } from '../modelo/technicalReportListItem.dto';
import { TechnicalReportDto } from '../modelo/technicalReport.dto';
import { CreateTechnicalReportDto } from '../modelo/createTechnicalReport.dto';
import { AddOrderServiceDto } from '../modelo/addOrderService.dto';
import { OrderServiceItemDto } from '../modelo/orderServiceItem.dto';

@ApiTags('orders')
@Controller('orders')
@UseGuards(RolesGuard)
@Roles('ADMIN', 'USER')
export class OrdersEndpoint {
  constructor(private readonly ordersController: OrdersController) {}

  @Get('accessories')
  @ApiOkResponse({ type: [AccessoryDto] })
  accessories(@Req() req: Request & { user?: unknown }) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) {
      return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    }
    return this.ordersController.accessories({ empresaId: tenantId });
  }

  @Post('accessories')
  @ApiOkResponse({ type: AccessoryDto })
  createAccessory(@Req() req: Request & { user?: unknown }, @Body() body: CreateAccessoryDto) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) {
      return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    }
    return this.ordersController.createAccessory({ empresaId: tenantId, name: body.name });
  }

  @Get('statuses')
  @ApiOkResponse({ type: [OrderStatusDto] })
  statuses(@Req() req: Request & { user?: unknown }) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) {
      return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    }
    return this.ordersController.statuses({ empresaId: tenantId });
  }

  @Get('serial-lookup')
  @ApiOkResponse({ type: SerialLookupDto })
  serialLookup(@Req() req: Request & { user?: unknown }, @Query('serial') serial = '') {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) {
      return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    }
    return this.ordersController.serialLookup({ empresaId: tenantId, serial });
  }

  @Get('client-stats')
  @ApiOkResponse({ type: ClientOrdersStatsDto })
  clientStats(@Req() req: Request & { user?: unknown }, @Query('clientId') clientId = '') {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) {
      return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    }
    return this.ordersController.clientStats({ empresaId: tenantId, clientId: Number(clientId) });
  }

  @Get()
  @ApiOkResponse({ type: OrdersPageDto })
  list(
    @Req() req: Request & { user?: unknown },
    @Query('search') search = '',
    @Query('status') status = '',
    @Query('approvalStatus') approvalStatus = '',
    @Query('clientId') clientId = '',
    @Query('page') page = '1',
    @Query('perPage') perPage = '10',
  ) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) {
      return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    }
    return this.ordersController.list({
      empresaId: tenantId,
      search,
      status,
      approvalStatus,
      clientId: clientId ? Number(clientId) : null,
      page: Number(page),
      perPage: Number(perPage),
    });
  }

  @Get(':id')
  @ApiOkResponse({ type: OrderDto })
  get(@Req() req: Request & { user?: unknown }, @Param('id') id: string) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) {
      return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    }
    return this.ordersController.getById({ empresaId: tenantId, id: Number(id) });
  }

  @Get(':id/history')
  @ApiOkResponse({ type: [OrderStatusHistoryDto] })
  history(@Req() req: Request & { user?: unknown }, @Param('id') id: string) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) {
      return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    }
    return this.ordersController.history({ empresaId: tenantId, id: Number(id) });
  }

  @Post()
  @ApiOkResponse({ type: OrderDto })
  create(@Req() req: Request & { user?: unknown }, @Body() body: CreateOrderDto) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) {
      return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    }
    return this.ordersController.create({
      empresaId: tenantId,
      clientId: body.clientId,
      deviceTypeId: body.deviceTypeId,
      deviceBrand: body.deviceBrand,
      deviceModel: body.deviceModel,
      devicePassword: body.devicePassword,
      serialNumber: body.serialNumber,
      reportedIssue: body.reportedIssue,
      clientObservations: body.clientObservations,
      status: body.status,
      priority: body.priority,
      estimatedCost: body.estimatedCost,
      advancePayment: body.advancePayment,
      paymentMethod: body.paymentMethod,
      paymentReference: body.paymentReference,
      technicianNotes: body.technicianNotes,
      estimatedCompletion: body.estimatedCompletion ?? null,
      accessoryIds: body.accessoryIds ?? [],
    });
  }

  @Patch(':id')
  @ApiOkResponse({ type: OrderDto })
  update(@Req() req: Request & { user?: unknown }, @Param('id') id: string, @Body() body: UpdateOrderDto) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) {
      return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    }
    return this.ordersController.update({
      empresaId: tenantId,
      id: Number(id),
      clientId: body.clientId,
      deviceTypeId: body.deviceTypeId,
      deviceBrand: body.deviceBrand,
      deviceModel: body.deviceModel,
      devicePassword: body.devicePassword,
      serialNumber: body.serialNumber,
      reportedIssue: body.reportedIssue,
      clientObservations: body.clientObservations,
      priority: body.priority,
      estimatedCost: body.estimatedCost,
      advancePayment: body.advancePayment,
      paymentMethod: body.paymentMethod,
      paymentReference: body.paymentReference,
      technicianNotes: body.technicianNotes,
      diagnosis: body.diagnosis,
      solution: body.solution,
      estimatedCompletion: body.estimatedCompletion ?? null,
      accessoryIds: body.accessoryIds ?? [],
    });
  }

  @Patch(':id/status')
  changeStatus(
    @Req() req: Request & { user?: unknown },
    @Param('id') id: string,
    @Body() body: ChangeOrderStatusDto,
  ) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) {
      return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    }
    return this.ordersController.changeStatus({
      empresaId: tenantId,
      id: Number(id),
      status: body.status,
      userId: Number.isFinite(user.sub) ? user.sub : null,
      finalCost: body.finalCost,
    });
  }

  @Patch(':id/approval')
  changeApproval(
    @Req() req: Request & { user?: unknown },
    @Param('id') id: string,
    @Body() body: ChangeOrderApprovalDto,
  ) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) {
      return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    }
    return this.ordersController.changeApproval({
      empresaId: tenantId,
      id: Number(id),
      approvalStatus: body.approvalStatus,
      approvedQuoteAmount: body.approvedQuoteAmount ?? null,
      approvalComment: body.approvalComment ?? null,
      approvalSignature: body.approvalSignature ?? null,
    });
  }

  @Delete(':id')
  delete(@Req() req: Request & { user?: unknown }, @Param('id') id: string) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) {
      return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    }
    return this.ordersController.delete({ empresaId: tenantId, id: Number(id) });
  }

  @Get(':id/reports')
  @ApiOkResponse({ type: [TechnicalReportListItemDto] })
  reports(@Req() req: Request & { user?: unknown }, @Param('id') id: string) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) {
      return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    }
    return this.ordersController.listTechnicalReports({ empresaId: tenantId, orderId: Number(id) });
  }

  @Get(':id/reports/:reportId')
  @ApiOkResponse({ type: TechnicalReportDto })
  reportDetail(@Req() req: Request & { user?: unknown }, @Param('id') id: string, @Param('reportId') reportId: string) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) {
      return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    }
    return this.ordersController.getTechnicalReport({ empresaId: tenantId, orderId: Number(id), reportId: Number(reportId) });
  }

  @Post(':id/reports')
  createReport(@Req() req: Request & { user?: unknown }, @Param('id') id: string, @Body() body: CreateTechnicalReportDto) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) {
      return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    }
    return this.ordersController.createTechnicalReport({
      empresaId: tenantId,
      orderId: Number(id),
      reportTitle: body.reportTitle,
      diagnosis: body.diagnosis ?? '',
      procedureTaken: body.procedureTaken ?? '',
      introduction: body.introduction ?? '',
      conclusion: body.conclusion ?? '',
      createdBy: Number.isFinite(user.sub) ? user.sub : null,
    });
  }

  @Delete(':id/reports/:reportId')
  deleteReport(@Req() req: Request & { user?: unknown }, @Param('id') id: string, @Param('reportId') reportId: string) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) {
      return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    }
    return this.ordersController.deleteTechnicalReport({
      empresaId: tenantId,
      orderId: Number(id),
      reportId: Number(reportId),
    });
  }

  @Get(':id/services')
  @ApiOkResponse({ type: [OrderServiceItemDto] })
  orderServices(@Req() req: Request & { user?: unknown }, @Param('id') id: string) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) {
      return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    }
    return this.ordersController.listOrderServices({ empresaId: tenantId, orderId: Number(id) });
  }

  @Post(':id/services')
  addOrderService(@Req() req: Request & { user?: unknown }, @Param('id') id: string, @Body() body: AddOrderServiceDto) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) {
      return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    }
    return this.ordersController.addOrderService({
      empresaId: tenantId,
      orderId: Number(id),
      serviceId: body.serviceId,
      quantity: body.quantity,
      servicePrice: body.servicePrice,
    });
  }

  @Delete(':id/services/:itemId')
  deleteOrderService(@Req() req: Request & { user?: unknown }, @Param('id') id: string, @Param('itemId') itemId: string) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) {
      return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    }
    return this.ordersController.deleteOrderService({
      empresaId: tenantId,
      orderId: Number(id),
      itemId: Number(itemId),
    });
  }
}
