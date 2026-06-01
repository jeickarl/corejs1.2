import { ApiProperty } from '@nestjs/swagger';
import { PurchaseOrderDto } from './purchaseOrder.dto';

export class PurchaseOrdersPageDto {
  @ApiProperty({ type: [PurchaseOrderDto] })
  items!: PurchaseOrderDto[];

  @ApiProperty({ example: 1 })
  page!: number;

  @ApiProperty({ example: 10 })
  perPage!: number;

  @ApiProperty({ example: 0 })
  total!: number;
}

