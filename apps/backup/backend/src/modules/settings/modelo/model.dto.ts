import { ApiProperty } from '@nestjs/swagger';

export class ModelDto {
  @ApiProperty({ example: 1 })
  id!: number;

  @ApiProperty({ example: 'A51' })
  name!: string;

  @ApiProperty({ example: 1, nullable: true })
  brandId!: number | null;

  @ApiProperty({ example: 1, nullable: true })
  deviceTypeId!: number | null;

  @ApiProperty({ example: true })
  isActive!: boolean;

  @ApiProperty({ example: '2026-01-01 10:00:00' })
  createdAt!: string;

  @ApiProperty({ example: '2026-01-01 10:00:00' })
  updatedAt!: string;
}
