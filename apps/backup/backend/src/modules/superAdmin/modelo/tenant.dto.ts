import { ApiProperty } from '@nestjs/swagger';

export class TenantDto {
  @ApiProperty({ example: 1 })
  id!: number;

  @ApiProperty({ example: 'Empresa Demo' })
  companyName!: string;

  @ApiProperty({ enum: ['active', 'suspended'] })
  status!: 'active' | 'suspended';

  @ApiProperty({ example: '2026-01-01T00:00:00.000Z' })
  createdAt!: string;
}

