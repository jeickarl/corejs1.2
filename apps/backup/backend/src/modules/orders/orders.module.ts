import { Module } from '@nestjs/common';
import { OrdersController } from './controller/orders.controller';
import { AccessoriesDao } from './daos/accessories.dao';
import { OrdersDao } from './daos/orders.dao';
import { OrdersEndpoint } from './endpoint/orders.endpoint';

@Module({
  controllers: [OrdersEndpoint],
  providers: [OrdersController, OrdersDao, AccessoriesDao],
})
export class OrdersModule {}
