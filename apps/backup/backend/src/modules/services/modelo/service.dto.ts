import { ApiProperty } from '@nestjs/swagger';

export class ServiceDto {
  @ApiProperty({ example: 1 })
  id!: number;

  @ApiProperty({ example: 'Cambio de pantalla' })
  name!: string;

  @ApiProperty({ example: '' })
  description!: string;

  @ApiProperty({ example: 1 })
  deviceCategoryId!: number;

  @ApiProperty({ example: 'Celulares' })
  deviceCategoryName!: string;

  @ApiProperty({ example: 100 })
  basePrice!: number;

  @ApiProperty({ example: 60 })
  estimatedTime!: number;

  @ApiProperty({ example: '' })
  notes!: string;

  @ApiProperty({ example: true })
  active!: boolean;

  @ApiProperty({ example: '2026-01-01T00:00:00.000Z' })
  createdAt!: string;

  @ApiProperty({ example: '2026-01-01T00:00:00.000Z' })
  updatedAt!: string;
}

