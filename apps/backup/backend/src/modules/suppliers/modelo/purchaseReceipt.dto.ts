import { ApiProperty } from '@nestjs/swagger';
import { PurchaseReceiptItemDto } from './purchaseReceiptItem.dto';

export class PurchaseReceiptDto {
  @ApiProperty({ example: 1 })
  id!: number;

  @ApiProperty({ example: 'RCV-20260101-ABCDEF' })
  receiptNumber!: string;

  @ApiProperty({ example: 1 })
  purchaseOrderId!: number;

  @ApiProperty({ example: 'PO-20260101-ABCDEF' })
  poNumber!: string;

  @ApiProperty({ example: 1 })
  supplierId!: number;

  @ApiProperty({ example: 'Proveedor SAS' })
  supplierName!: string;

  @ApiProperty({ example: '2026-01-01' })
  receivedDate!: string;

  @ApiProperty({ example: '' })
  notes!: string;

  @ApiProperty({ example: 100 })
  totalAmount!: number;

  @ApiProperty({ example: '2026-01-01T00:00:00.000Z' })
  createdAt!: string;

  @ApiProperty({ example: 1, nullable: true })
  createdBy!: number | null;

  @ApiProperty({ type: [PurchaseReceiptItemDto] })
  items!: PurchaseReceiptItemDto[];
}

