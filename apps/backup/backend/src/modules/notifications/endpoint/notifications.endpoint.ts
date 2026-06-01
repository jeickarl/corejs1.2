import { Controller, Get, Patch, Param, Query, Req, UseGuards } from '@nestjs/common';
import { ApiOkResponse, ApiTags } from '@nestjs/swagger';
import type { Request } from 'express';
import { Roles } from '../../../infrastructure/auth/roles.decorator';
import { RolesGuard } from '../../../infrastructure/auth/roles.guard';
import type { AuthTokenPayload } from '../../../infrastructure/auth/jwt';
import { NotificationsController } from '../controller/notifications.controller';

@ApiTags('notifications')
@Controller('notifications')
@UseGuards(RolesGuard)
@Roles('ADMIN', 'USER')
export class NotificationsEndpoint {
  constructor(private readonly notificationsController: NotificationsController) {}

  @Get()
  @ApiOkResponse({ type: Object })
  list(
    @Req() req: Request & { user?: unknown },
    @Query('onlyUnread') onlyUnread = '0',
    @Query('page') page = '1',
    @Query('perPage') perPage = '10',
  ) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    return this.notificationsController.list({
      empresaId: tenantId,
      userId: user.sub,
      onlyUnread: String(onlyUnread) === '1',
      page: Number(page),
      perPage: Number(perPage),
    });
  }

  @Patch(':id/read')
  markRead(@Req() req: Request & { user?: unknown }, @Param('id') id: string) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    return this.notificationsController.markRead({ empresaId: tenantId, userId: user.sub, id: Number(id) });
  }

  @Patch('read-all')
  markAllRead(@Req() req: Request & { user?: unknown }) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    return this.notificationsController.markAllRead({ empresaId: tenantId, userId: user.sub });
  }
}

