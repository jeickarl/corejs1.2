import { ApiProperty } from '@nestjs/swagger';
import { InventoryProductDto } from './inventoryProduct.dto';

export class InventoryProductsPageDto {
  @ApiProperty({ type: [InventoryProductDto] })
  items!: InventoryProductDto[];

  @ApiProperty({ example: 1 })
  page!: number;

  @ApiProperty({ example: 10 })
  perPage!: number;

  @ApiProperty({ example: 100 })
  total!: number;
}

