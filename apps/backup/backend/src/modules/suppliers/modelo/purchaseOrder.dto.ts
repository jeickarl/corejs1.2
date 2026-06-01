import { ApiProperty } from '@nestjs/swagger';

export class PurchaseOrderDto {
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

  @ApiProperty({ example: '2026-01-10', nullable: true })
  expectedDate!: string | null;

  @ApiProperty({ example: 'Efectivo' })
  paymentMethod!: string;

  @ApiProperty({ example: '30_days' })
  paymentTerms!: string;

  @ApiProperty({ example: 'Notas' })
  notes!: string;

  @ApiProperty({ example: 0 })
  totalAmount!: number;

  @ApiProperty({ example: 'pending' })
  paymentStatus!: string;

  @ApiProperty({ example: 'draft' })
  status!: string;

  @ApiProperty({ example: '2026-01-01T00:00:00.000Z' })
  createdAt!: string;

  @ApiProperty({ example: '2026-01-01T00:00:00.000Z' })
  updatedAt!: string;
}

