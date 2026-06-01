import { Module } from '@nestjs/common';
import { NotificationsDao } from './daos/notifications.dao';
import { NotificationsController } from './controller/notifications.controller';
import { NotificationsEndpoint } from './endpoint/notifications.endpoint';

@Module({
  controllers: [NotificationsEndpoint],
  providers: [NotificationsController, NotificationsDao],
})
export class NotificationsModule {}

