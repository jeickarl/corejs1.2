import { Body, Controller, Get, Post, Req, UseGuards } from '@nestjs/common';
import { ApiOkResponse, ApiTags } from '@nestjs/swagger';
import type { Request } from 'express';
import { Roles } from '../../../infrastructure/auth/roles.decorator';
import { RolesGuard } from '../../../infrastructure/auth/roles.guard';
import type { AuthTokenPayload } from '../../../infrastructure/auth/jwt';
import { BackupController } from '../controller/backup.controller';
import { ImportBackupDto } from '../modelo/importBackup.dto';
import type { BackupPayload } from '../daos/backup.dao';

@ApiTags('backup')
@Controller('backup')
@UseGuards(RolesGuard)
@Roles('ADMIN')
export class BackupEndpoint {
  constructor(private readonly backupController: BackupController) {}

  @Get('export')
  @ApiOkResponse({ type: Object })
  export(@Req() req: Request & { user?: unknown }) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    return this.backupController.exportTenant({ empresaId: tenantId });
  }

  @Post('import')
  import(@Req() req: Request & { user?: unknown }, @Body() body: ImportBackupDto) {
    const user = req.user as AuthTokenPayload;
    const tenantId = user.tenantId;
    if (!tenantId) return { ok: false, error: { code: 'TENANT_REQUIRED', message: 'Tenant requerido' } };
    const mode = body.mode ?? 'replace';
    return this.backupController.importTenant({
      empresaId: tenantId,
      payload: body.payload as BackupPayload,
      mode,
    });
  }
}

