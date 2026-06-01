import { ApiProperty } from '@nestjs/swagger';

export class SupplierPaymentDto {
  @ApiProperty({ example: 1 })
  id!: number;

  @ApiProperty({ example: 1 })
  supplierId!: number;

  @ApiProperty({ example: 'Proveedor SAS' })
  supplierName!: string;

  @ApiProperty({ example: 1, nullable: true })
  purchaseOrderId!: number | null;

  @ApiProperty({ example: 'PO-20260101-ABCDEF', nullable: true })
  poNumber!: string | null;

  @ApiProperty({ example: 1000 })
  paymentAmount!: number;

  @ApiProperty({ example: 'Efectivo', nullable: true })
  paymentMethod!: string | null;

  @ApiProperty({ example: '2026-01-01' })
  paymentDate!: string;

  @ApiProperty({ example: 'REF-1', nullable: true })
  referenceNumber!: string | null;

  @ApiProperty({ example: 'Notas', nullable: true })
  notes!: string | null;

  @ApiProperty({ example: 1, nullable: true })
  cashSessionId!: number | null;

  @ApiProperty({ example: 'active' })
  status!: string;

  @ApiProperty({ example: '2026-01-01T00:00:00.000Z' })
  createdAt!: string;

  @ApiProperty({ example: 1, nullable: true })
  createdBy!: number | null;
}

