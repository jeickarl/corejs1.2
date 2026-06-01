import { ApiProperty } from '@nestjs/swagger';

export class MeDto {
  @ApiProperty({ example: 1 })
  id!: number;

  @ApiProperty({ example: 'superadmin@local.test' })
  email!: string;

  @ApiProperty({ example: 'Jeisson' })
  name!: string;

  @ApiProperty({ enum: ['SUPER_ADMIN', 'ADMIN', 'USER'] })
  role!: 'SUPER_ADMIN' | 'ADMIN' | 'USER';

  @ApiProperty({ example: null, nullable: true })
  tenantId!: number | null;
}
