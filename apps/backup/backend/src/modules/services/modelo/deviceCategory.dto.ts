import { ApiProperty } from '@nestjs/swagger';

export class DeviceCategoryDto {
  @ApiProperty({ example: 1 })
  id!: number;

  @ApiProperty({ example: 'Celulares' })
  name!: string;

  @ApiProperty({ example: '' })
  description!: string;

  @ApiProperty({ example: 0 })
  sortOrder!: number;

  @ApiProperty({ example: true })
  active!: boolean;

  @ApiProperty({ example: 0 })
  serviceCount!: number;

  @ApiProperty({ example: '2026-01-01T00:00:00.000Z' })
  createdAt!: string;

  @ApiProperty({ example: '2026-01-01T00:00:00.000Z' })
  updatedAt!: string;
}

