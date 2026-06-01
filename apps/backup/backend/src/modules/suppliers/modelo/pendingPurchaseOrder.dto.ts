import { ApiProperty } from '@nestjs/swagger';

export class PendingPurchaseOrderDto {
  @ApiProperty({ example: 1 })
  id!: number;

  @ApiProperty({ example: 'PO-20260101-ABCDEF' })
  poNumber!: string;

  @ApiProperty({ example: 1 })
  supplierId!: number;

  @ApiProperty({ example: 'Proveedor SAS' })
  supplierName!: string;

  @ApiProperty({ example: '2026-01-01' })
  orderDate!: string;

  @ApiProperty({ example: 'draft' })
  status!: string;

  @ApiProperty({ example: 'pending' })
  paymentStatus!: string;

  @ApiProperty({ example: 1000 })
  totalAmount!: number;

  @ApiProperty({ example: 200 })
  paidAmount!: number;

  @ApiProperty({ example: 800 })
  pendingAmount!: number;
}

