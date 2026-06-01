import { ApiProperty } from '@nestjs/swagger';
import { IsEmail } from 'class-validator';

export class TenantUserUpdateEmailDto {
  @ApiProperty({ example: 'nuevo@correo.com' })
  @IsEmail()
  newEmail!: string;
}

