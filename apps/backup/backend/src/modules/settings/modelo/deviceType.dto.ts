import { ApiProperty } from '@nestjs/swagger';

export class DeviceTypeDto {
  @ApiProperty({ example: 1 })
  id!: number;

  @ApiProperty({ example: 'Celular' })
  name!: string;

  @ApiProperty({ example: true })
  isActive!: boolean;

  @ApiProperty({ example: 0 })
  sortOrder!: number;

  @ApiProperty({ example: '2026-01-01 10:00:00' })
  createdAt!: string;

  @ApiProperty({ example: '2026-01-01 10:00:00' })
  updatedAt!: string;
}

