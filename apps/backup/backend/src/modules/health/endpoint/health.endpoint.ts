import { Controller, Get } from '@nestjs/common';
import { ApiOkResponse, ApiTags } from '@nestjs/swagger';
import { HealthController } from '../controller/health.controller';
import { HealthDto } from '../modelo/health.dto';

@ApiTags('health')
@Controller('health')
export class HealthEndpoint {
  constructor(private readonly healthController: HealthController) {}

  @Get()
  @ApiOkResponse({ type: HealthDto })
  getHealth() {
    return this.healthController.getHealth();
  }
}

