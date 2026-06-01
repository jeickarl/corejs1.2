import { ApiProperty } from '@nestjs/swagger';
import { PurchaseReceiptDto } from './purchaseReceipt.dto';

export class PurchaseReceiptsPageDto {
  @ApiProperty({ type: [PurchaseReceiptDto] })
  items!: PurchaseReceiptDto[];

  @ApiProperty({ example: 1 })
  page!: number;

  @ApiProperty({ example: 10 })
  perPage!: number;

  @ApiProperty({ example: 0 })
  total!: number;
}

