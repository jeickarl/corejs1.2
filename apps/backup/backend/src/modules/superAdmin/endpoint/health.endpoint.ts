import { Controller, Get, UseGuards } from '@nestjs/common';
import { ApiOkResponse, ApiTags } from '@nestjs/swagger';
import { Roles } from '../../../infrastructure/auth/roles.decorator';
import { RolesGuard } from '../../../infrastructure/auth/roles.guard';
import { HealthController } from '../controller/health.controller';
import { TenantHealthDto } from '../modelo/tenantHealth.dto';

@ApiTags('super-admin')
@Controller('super-admin/health')
@UseGuards(RolesGuard)
@Roles('SUPER_ADMIN')
export class HealthEndpoint {
  constructor(private readonly healthController: HealthController) {}

  @Get('tenants')
  @ApiOkResponse({ type: [TenantHealthDto] })
  tenants() {
    return this.healthController.tenants();
  }
}

