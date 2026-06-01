import { Module } from '@nestjs/common';
import { DashboardController } from './controller/dashboard.controller';
import { DashboardDao } from './daos/dashboard.dao';
import { DashboardEndpoint } from './endpoint/dashboard.endpoint';

@Module({
  controllers: [DashboardEndpoint],
  providers: [DashboardController, DashboardDao],
})
export class DashboardModule {}

