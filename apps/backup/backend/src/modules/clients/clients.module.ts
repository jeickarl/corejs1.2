import { Module } from '@nestjs/common';
import { ClientsDao } from './daos/clients.dao';
import { ClientsController } from './controller/clients.controller';
import { ClientsEndpoint } from './endpoint/clients.endpoint';

@Module({
  controllers: [ClientsEndpoint],
  providers: [ClientsController, ClientsDao],
})
export class ClientsModule {}

