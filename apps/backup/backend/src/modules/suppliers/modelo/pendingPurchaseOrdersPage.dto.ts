import { ApiProperty } from '@nestjs/swagger';
import { PendingPurchaseOrderDto } from './pendingPurchaseOrder.dto';

export class PendingPurchaseOrdersPageDto {
  @ApiProperty({ type: [PendingPurchaseOrderDto] })
  items!: PendingPurchaseOrderDto[];

  @ApiProperty({ example: 1 })
  page!: number;

  @ApiProperty({ example: 10 })
  perPage!: number;

  @ApiProperty({ example: 0 })
  total!: number;
}

