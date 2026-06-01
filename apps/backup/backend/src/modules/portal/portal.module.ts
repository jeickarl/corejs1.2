import { Module } from '@nestjs/common';
import { PortalDao } from './daos/portal.dao';
import { PortalController } from './controller/portal.controller';
import { PortalEndpoint } from './endpoint/portal.endpoint';

@Module({
  controllers: [PortalEndpoint],
  providers: [PortalController, PortalDao],
})
export class PortalModule {}
