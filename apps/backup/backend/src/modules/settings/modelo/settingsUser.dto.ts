import { ApiProperty } from '@nestjs/swagger';

export class SettingsUserDto {
  @ApiProperty({ example: 1 })
  id!: number;

  @ApiProperty({ example: 'user@empresa.com' })
  email!: string;

  @ApiProperty({ example: 'Juan Pérez' })
  name!: string;

  @ApiProperty({ enum: ['admin', 'user'] })
  role!: 'admin' | 'user';

  @ApiProperty({ example: true })
  active!: boolean;

  @ApiProperty({ example: '2026-01-01 10:00:00' })
  createdAt!: string;
}

