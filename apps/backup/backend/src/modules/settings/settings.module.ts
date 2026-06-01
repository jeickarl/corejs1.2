import { Module } from '@nestjs/common';
import { SettingsDao } from './daos/settings.dao';
import { SettingsUsersDao } from './daos/settingsUsers.dao';
import { SettingsController } from './controller/settings.controller';
import { SettingsEndpoint } from './endpoint/settings.endpoint';

@Module({
  controllers: [SettingsEndpoint],
  providers: [SettingsController, SettingsDao, SettingsUsersDao],
})
export class SettingsModule {}
