import { ApiProperty } from '@nestjs/swagger';

export class PurchaseReceiptItemDto {
  @ApiProperty({ example: 1 })
  id!: number;

  @ApiProperty({ example: 1 })
  receiptId!: number;

  @ApiProperty({ example: 1 })
  productId!: number;

  @ApiProperty({ example: 'Producto' })
  productName!: string;

  @ApiProperty({ example: 1 })
  quantity!: number;

  @ApiProperty({ example: 10 })
  unitCost!: number;

  @ApiProperty({ example: 10 })
  subtotal!: number;
}

