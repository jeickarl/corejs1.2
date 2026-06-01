import { Module } from '@nestjs/common';
import { AuthController } from './controller/auth.controller';
import { AuthEndpoint } from './endpoint/auth.endpoint';
import { SuperAdminDao } from './daos/superAdmin.dao';
import { MasterUsersDao } from './daos/masterUsers.dao';

@Module({
  controllers: [AuthEndpoint],
  providers: [AuthController, SuperAdminDao, MasterUsersDao],
})
export class AuthModule {}
