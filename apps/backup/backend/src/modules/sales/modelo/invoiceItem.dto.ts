import { ApiProperty } from '@nestjs/swagger';

export class InvoiceItemDto {
  @ApiProperty({ example: 1 })
  id!: number;

  @ApiProperty({ enum: ['manual', 'product', 'service'] })
  itemType!: 'manual' | 'product' | 'service';

  @ApiProperty({ example: 123, nullable: true })
  productId!: number | null;

  @ApiProperty({ example: 'Servicio de reparación' })
  description!: string;

  @ApiProperty({ example: 1 })
  quantity!: number;

  @ApiProperty({ example: 10000 })
  unitPrice!: number;

  @ApiProperty({ example: 10000 })
  totalPrice!: number;
}
