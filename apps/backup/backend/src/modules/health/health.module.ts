import { Module } from '@nestjs/common';
import { HealthController } from './controller/health.controller';
import { HealthEndpoint } from './endpoint/health.endpoint';

@Module({
  controllers: [HealthEndpoint],
  providers: [HealthController],
})
export class HealthModule {}

