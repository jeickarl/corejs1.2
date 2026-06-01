import { ApiProperty } from '@nestjs/swagger';

export class InventoryProductDto {
  @ApiProperty({ example: 1 })
  id!: number;

  @ApiProperty({ example: 'SKU-001' })
  sku!: string;

  @ApiProperty({ example: 'Pantalla iPhone X' })
  name!: string;

  @ApiProperty({ example: 'Repuesto original', required: false })
  description!: string;

  @ApiProperty({ example: 25000 })
  salePrice!: number;

  @ApiProperty({ example: 15000 })
  costPrice!: number;

  @ApiProperty({ example: 5 })
  currentStock!: number;

  @ApiProperty({ example: 1 })
  minStock!: number;

  @ApiProperty({ example: true })
  isActive!: boolean;

  @ApiProperty({ example: '2026-01-01 10:00:00' })
  createdAt!: string;

  @ApiProperty({ example: '2026-01-01 10:00:00' })
  updatedAt!: string;
}

