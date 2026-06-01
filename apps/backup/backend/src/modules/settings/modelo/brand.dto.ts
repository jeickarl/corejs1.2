import { ApiProperty } from '@nestjs/swagger';

export class BrandDto {
  @ApiProperty({ example: 1 })
  id!: number;

  @ApiProperty({ example: 'Samsung' })
  name!: string;

  @ApiProperty({ example: true })
  isActive!: boolean;

  @ApiProperty({ example: '2026-01-01 10:00:00' })
  createdAt!: string;

  @ApiProperty({ example: '2026-01-01 10:00:00' })
  updatedAt!: string;
}

