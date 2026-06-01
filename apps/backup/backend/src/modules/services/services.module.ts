import { Module } from '@nestjs/common';
import { DeviceCategoriesDao } from './daos/deviceCategories.dao';
import { ServicesDao } from './daos/services.dao';
import { DeviceCategoriesController } from './controller/deviceCategories.controller';
import { ServicesController } from './controller/services.controller';
import { DeviceCategoriesEndpoint } from './endpoint/deviceCategories.endpoint';
import { ServicesEndpoint } from './endpoint/services.endpoint';

@Module({
  controllers: [DeviceCategoriesEndpoint, ServicesEndpoint],
  providers: [DeviceCategoriesController, DeviceCategoriesDao, ServicesController, ServicesDao],
})
export class ServicesModule {}
